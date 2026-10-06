<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/** Uses */

use PDO;
use Relay\Http\HttpError;

/**
 * Messages between sessions: sending and the inbox (read per receiving session).
 *
 * A message belongs to the team of the session that sent it and never leaves that team:
 * the recipient must be able to see a session of the sender in that team (visible_to()).
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class MessageStore
{
	const array KINDS = array('note', 'question', 'answer');

	const string NO_RECIPIENT = 'No such session or person.';

	const string NO_MESSAGE = 'No such message.';

	/**
	 * @param PDO $pdo
	 */
	public function __construct(private PDO $pdo)
	{
	}

	/**
	 * @param array{id:int,name:string} $person
	 * @param array<string, mixed> $in from, to, kind, text, reply_to
	 * @return int Id of the new message.
	 * @throws HttpError
	 */
	public function send(array $person, array $in): int
	{
		//Validate the input before looking anything up:
		$from = Input::name($in, 'from');
		$kind = Input::text($in, 'kind', 20);
		if(in_array($kind, self::KINDS, true) === false) {
			throw new HttpError(422, 'Field kind must be note, question or answer.');
		}
		$text = Input::text($in, 'text', 4000);
		$replyTo = $kind === 'answer' ? Input::id($in, 'reply_to') : null;
		$to = $kind === 'answer' ? '' : Input::text($in, 'to', 80);

		//The sending session must be mine; the message gets its team:
		$session = (new SessionStore($this->pdo))->heartbeat($person, $from);

		//Resolve the recipient:
		$toPerson = null;
		if($replyTo !== null) {
			$toSession = $this->askerOf($person, $session, $replyTo);
		} else {
			[$toSession, $toPerson] = $this->recipient($person, $session['team'], $to);
		}

		//Store:
		$st = $this->pdo->prepare(
			'INSERT INTO message (from_session, from_person_id, to_session, to_person_id, team, kind, text, reply_to)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id'
		);
		$st->execute(array($from, $person['id'], $toSession, $toPerson, $session['team'], $kind, $text, $replyTo));

		return (int) $st->fetchColumn();
	}

	/**
	 * Heartbeat plus inbox with long-poll: on an empty inbox keep asking for at most $wait seconds.
	 * @param array{id:int,name:string} $person
	 * @param string $session
	 * @param int $wait Seconds, already capped by the caller.
	 * @return list<array<string, mixed>>
	 * @throws HttpError 404 when it is not my own (visible) session.
	 */
	public function poll(array $person, string $session, int $wait): array
	{
		//Heartbeat (also checks that the session is mine):
		$own = (new SessionStore($this->pdo))->heartbeat($person, $session);

		//Poll once per second until something arrives or time is up:
		$end = microtime(true) + max(0, $wait);
		while(true) {
			$list = $this->inbox($person, $own['name']);
			if($list !== array() || microtime(true) >= $end) {
				return $list;
			}
			usleep(1000000);
		}
	}

	/**
	 * Unread messages for this session; marks them as read straight away.
	 * @param array{id:int,name:string} $person
	 * @param string $session Already checked by SessionStore::heartbeat().
	 * @return list<array<string, mixed>>
	 */
	public function inbox(array $person, string $session): array
	{
		$session = strtolower($session);
		$this->pdo->beginTransaction();

		//Unread messages of the session's team to this session, or to this person (max 1 hour old, not sent by it):
		$st = $this->pdo->prepare(
			"SELECT m.id, m.kind, m.from_session AS \"from\", fp.name AS from_person, m.text, m.reply_to,
				to_char(m.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS created_at
			 FROM session s
			 JOIN message m ON m.team = s.team AND m.created_at >= s.started_at
			 JOIN person fp ON fp.id = m.from_person_id
			 WHERE s.name = :session AND s.person_id = :person
				AND (m.to_session = s.name
					OR (m.to_person_id = s.person_id AND m.created_at > now() - interval '1 hour' AND m.from_session <> s.name))
				AND visible_to(m.team, m.from_person_id, s.person_id)
				AND NOT EXISTS (SELECT 1 FROM message_read r WHERE r.message_id = m.id AND r.session = s.name)
			 ORDER BY m.id"
		);
		$st->execute(array('session' => $session, 'person' => $person['id']));
		$messages = $st->fetchAll();

		//Mark as read:
		$mark = $this->pdo->prepare('INSERT INTO message_read (message_id, session) VALUES (?, ?) ON CONFLICT DO NOTHING');
		foreach($messages as &$message) {
			$mark->execute(array($message['id'], $session));
			$message['id'] = (int) $message['id'];
			$message['reply_to'] = $message['reply_to'] === null ? null : (int) $message['reply_to'];
		}
		unset($message);
		$this->pdo->commit();

		return $messages;
	}

	/**
	 * @param array{id:int,name:string} $person
	 * @param string $team Team of the sending session.
	 * @param string $to Session name or person name.
	 * @return array{0:string|null,1:int|null} [to_session, to_person_id]
	 * @throws HttpError
	 */
	private function recipient(array $person, string $team, string $to): array
	{
		//A live session in the same team that I can see:
		$st = $this->pdo->prepare(
			'SELECT s.name FROM session s
			 WHERE s.name = lower(:to) AND ' . SessionStore::LIVE . ' AND s.team = :team AND visible_to(s.team, s.person_id, :viewer)'
		);
		$st->execute(array('to' => $to, 'team' => $team, 'viewer' => $person['id']));
		$session = $st->fetchColumn();
		if($session !== false) {
			return array((string) $session, null);
		}

		//Or an active person who can see my sessions in this team (private: only myself):
		$st = $this->pdo->prepare('SELECT p.id FROM person p WHERE lower(p.name) = lower(:to) AND p.active AND visible_to(:team, :owner, p.id)');
		$st->execute(array('to' => $to, 'team' => $team, 'owner' => $person['id']));
		$personId = $st->fetchColumn();
		if($personId !== false) {
			return array(null, (int) $personId);
		}

		throw new HttpError(404, self::NO_RECIPIENT);
	}

	/**
	 * @param array{id:int,name:string} $person
	 * @param array{name:string,team:string} $session My sending session.
	 * @param int $questionId
	 * @return string The session that sent the message I answer.
	 * @throws HttpError 404 when it does not exist, is in another team or was not addressed to me.
	 */
	private function askerOf(array $person, array $session, int $questionId): string
	{
		$st = $this->pdo->prepare(
			'SELECT m.from_session FROM message m
			 WHERE m.id = :id AND m.team = :team AND (m.to_session = :session OR m.to_person_id = :person)
				AND visible_to(m.team, m.from_person_id, :viewer)'
		);
		$st->execute(array('id' => $questionId, 'team' => $session['team'], 'session' => $session['name'], 'person' => $person['id'], 'viewer' => $person['id']));
		$asker = $st->fetchColumn();
		if($asker === false) {
			throw new HttpError(404, self::NO_MESSAGE);
		}

		return (string) $asker;
	}
}
