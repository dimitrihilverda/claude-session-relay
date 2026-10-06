<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/** Uses */

use PDO;
use Relay\Http\HttpError;

/**
 * Sessions: register (also the heartbeat), unregister, the board and the conflict check.
 *
 * Visibility is decided by the SQL function visible_to() (migrations/003_teams.sql) and nowhere
 * else. Whatever is not visible answers exactly like something that does not exist.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class SessionStore
{
	const string LIVE = "s.last_seen > now() - interval '10 minutes'";

	const string COLUMNS = "s.name, p.name AS person, s.team, s.machine, s.repo, s.repo_base, s.branch, s.ticket, s.claim,
		to_char(s.started_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS started_at,
		to_char(s.last_seen AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS last_seen";

	const string UNKNOWN_SESSION = 'Unknown session.';

	const string UNKNOWN_TEAM = 'Unknown team.';

	const int MAX_SESSIONS = 30;

	const int MAX_CLAIM = 1000;

	/**
	 * @param PDO $pdo
	 */
	public function __construct(private PDO $pdo)
	{
	}

	/**
	 * Registers a session or updates it; without `claim` or `team` the stored value stays.
	 * @param array{id:int,name:string} $person
	 * @param array<string, mixed> $in
	 * @return array<string, mixed>
	 * @throws HttpError
	 */
	public function register(array $person, array $in): array
	{
		//Validate the input before looking anything up, so the order of errors reveals nothing:
		$name = Input::name($in, 'name');
		$team = array_key_exists('team', $in) === true ? Input::text($in, 'team', 80) : null;
		$machine = Input::text($in, 'machine', 200);
		$repo = Input::text($in, 'repo', 200);
		$repoBase = Input::text($in, 'repo_base', 200);
		$branch = Input::text($in, 'branch', 200, false);
		$ticket = Input::text($in, 'ticket', 100, false);
		$claimSet = array_key_exists('claim', $in);
		$claim = $claimSet === true ? Input::pathList($in, 'claim', self::MAX_CLAIM) : array();

		//Someone else's name: "taken" only when I may see it; otherwise exactly like a name that does not exist:
		$prefix = strtolower($person['name']) . '-';
		$foreign = new HttpError(404, self::UNKNOWN_SESSION . " Your session names must start with $prefix.");
		$st = $this->pdo->prepare('SELECT person_id, team, visible_to(team, person_id, :viewer) AS visible FROM session WHERE name = :name');
		$st->execute(array('viewer' => $person['id'], 'name' => $name));
		$existing = $st->fetch();
		if($existing !== false && (int) $existing['person_id'] !== $person['id']) {
			throw $existing['visible'] === true ? new HttpError(409, 'Session name is taken.') : $foreign;
		}
		if($existing === false && str_starts_with($name, $prefix) === false) {
			throw $foreign;
		}

		//Team: required for a new session; 'private' or a team I am a member of:
		$team = $team ?? ($existing === false ? null : (string) $existing['team']);
		if($team === null) {
			throw new HttpError(422, 'Field team is required for a new session.');
		}
		$this->requireTeam($person, $team);

		//A new session only while I have fewer than MAX_SESSIONS live ones (counts only my own):
		if($existing === false) {
			$st = $this->pdo->prepare('SELECT count(*) FROM session s WHERE s.person_id = ? AND ' . self::LIVE);
			$st->execute(array($person['id']));
			if((int) $st->fetchColumn() >= self::MAX_SESSIONS) {
				throw new HttpError(422, 'Too many sessions.');
			}
		}

		//Upsert; moving to another team starts afresh (claim and history stay behind in the old team):
		$st = $this->pdo->prepare(
			'INSERT INTO session (name, person_id, team, machine, repo, repo_base, branch, ticket, claim)
			 VALUES (:name, :person, :team, :machine, :repo, :repo_base, :branch, :ticket, CAST(:claim AS jsonb))
			 ON CONFLICT (name) DO UPDATE SET team = EXCLUDED.team, machine = EXCLUDED.machine, repo = EXCLUDED.repo,
				repo_base = EXCLUDED.repo_base, branch = EXCLUDED.branch, ticket = EXCLUDED.ticket,
				claim = CASE WHEN CAST(:claim_set AS boolean) OR session.team <> EXCLUDED.team THEN EXCLUDED.claim ELSE session.claim END,
				started_at = CASE WHEN session.team <> EXCLUDED.team THEN now() ELSE session.started_at END,
				last_seen = now()
			 WHERE session.person_id = EXCLUDED.person_id'
		);
		$st->execute(array(
			'name' => $name,
			'person' => $person['id'],
			'team' => $team,
			'machine' => $machine,
			'repo' => $repo,
			'repo_base' => $repoBase,
			'branch' => $branch,
			'ticket' => $ticket === '' ? null : $ticket,
			'claim' => json_encode($claim, JSON_THROW_ON_ERROR),
			'claim_set' => $claimSet === true ? 'true' : 'false',
		));
		if($st->rowCount() !== 1) {
			//Someone else registered this name in the meantime:
			throw $foreign;
		}

		return $this->fetch($name);
	}

	/**
	 * Updates `last_seen` of one of my own sessions.
	 * @param array{id:int,name:string} $person
	 * @param string $name
	 * @return array{name:string,team:string} The session.
	 * @throws HttpError 404 when it is not my own (visible) session.
	 */
	public function heartbeat(array $person, string $name): array
	{
		$session = $this->own($person, $name);
		$this->pdo->prepare('UPDATE session SET last_seen = now() WHERE name = ?')->execute(array($session['name']));

		return $session;
	}

	/**
	 * Deletes one of my own sessions; my own name that does not exist (any more) is fine too.
	 * @param array{id:int,name:string} $person
	 * @param string $name
	 * @return void
	 * @throws HttpError
	 */
	public function unregister(array $person, string $name): void
	{
		$name = Input::name(array('name' => $name), 'name');
		$st = $this->pdo->prepare('SELECT person_id FROM session WHERE name = ?');
		$st->execute(array($name));
		$owner = $st->fetchColumn();
		$mine = $owner === false ? str_starts_with($name, strtolower($person['name']) . '-') : (int) $owner === $person['id'];
		if($mine === false) {
			throw new HttpError(404, self::UNKNOWN_SESSION);
		}
		$this->pdo->prepare('DELETE FROM session WHERE name = ? AND person_id = ?')->execute(array($name, $person['id']));
	}

	/**
	 * @param array{id:int,name:string} $person
	 * @param string $team Only this team ('' = all teams).
	 * @return list<array<string, mixed>> All live sessions visible to me.
	 */
	public function board(array $person, string $team = ''): array
	{
		$st = $this->pdo->prepare(
			'SELECT ' . self::COLUMNS . ' FROM session s JOIN person p ON p.id = s.person_id
			 WHERE ' . self::LIVE . ' AND visible_to(s.team, s.person_id, :viewer)
				AND (CAST(:team AS text) = \'\' OR s.team = :same_team)
			 ORDER BY s.team = \'private\', s.team, lower(p.name), s.name'
		);
		$st->execute(array('viewer' => $person['id'], 'team' => $team, 'same_team' => $team));

		return array_map(array($this, 'format'), $st->fetchAll());
	}

	/**
	 * Live sessions of other persons in the same team as my session that conflict with what I am about to do.
	 * @param array{id:int,name:string} $person
	 * @param array<string, mixed> $in session, repo_base, branch, paths
	 * @return list<array{session:string,person:string,reason:string}>
	 * @throws HttpError
	 */
	public function check(array $person, array $in): array
	{
		//Validate input:
		$name = Input::name($in, 'session');
		$repoBase = Input::text($in, 'repo_base', 200);
		$mine = array('branch' => Input::text($in, 'branch', 200, false), 'paths' => Input::pathList($in, 'paths'));
		$session = $this->own($person, $name);
		if($session['team'] === 'private') {
			return array();
		}

		//Live sessions of others in the same team and the same underlying repo:
		$st = $this->pdo->prepare(
			'SELECT s.name, p.name AS person, s.branch, s.claim FROM session s JOIN person p ON p.id = s.person_id
			 WHERE ' . self::LIVE . ' AND s.person_id <> :person AND s.team = :team AND lower(s.repo_base) = lower(:repo_base)
				AND visible_to(s.team, s.person_id, :viewer)
			 ORDER BY s.name'
		);
		$st->execute(array('person' => $person['id'], 'team' => $session['team'], 'repo_base' => $repoBase, 'viewer' => $person['id']));

		$conflicts = array();
		foreach($st->fetchAll() as $row) {
			$reason = Conflict::reason($mine, array('branch' => (string) $row['branch'], 'claim' => json_decode((string) $row['claim'], true)));
			if($reason !== null) {
				$conflicts[] = array('session' => (string) $row['name'], 'person' => (string) $row['person'], 'reason' => $reason);
			}
		}

		return $conflicts;
	}

	/**
	 * @param array{id:int,name:string} $person
	 * @param string $name
	 * @return array{name:string,team:string}
	 * @throws HttpError 404 when it is not my own (visible) session.
	 */
	public function own(array $person, string $name): array
	{
		$name = Input::name(array('session' => $name), 'session');
		$st = $this->pdo->prepare('SELECT s.name, s.team FROM session s WHERE s.name = :name AND s.person_id = :person AND visible_to(s.team, s.person_id, :viewer)');
		$st->execute(array('name' => $name, 'person' => $person['id'], 'viewer' => $person['id']));
		$row = $st->fetch();
		if($row === false) {
			throw new HttpError(404, self::UNKNOWN_SESSION);
		}

		return array('name' => (string) $row['name'], 'team' => (string) $row['team']);
	}

	/**
	 * @param array{id:int,name:string} $person
	 * @param string $team
	 * @return void
	 * @throws HttpError 404 when it is neither 'private' nor a team I am a member of.
	 */
	private function requireTeam(array $person, string $team): void
	{
		$st = $this->pdo->prepare('SELECT visible_to(:team, :owner, :viewer)');
		$st->execute(array('team' => $team, 'owner' => $person['id'], 'viewer' => $person['id']));
		if($st->fetchColumn() !== true) {
			throw new HttpError(404, self::UNKNOWN_TEAM);
		}
	}

	/**
	 * @param string $name
	 * @return array<string, mixed>
	 */
	private function fetch(string $name): array
	{
		$st = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM session s JOIN person p ON p.id = s.person_id WHERE s.name = ?');
		$st->execute(array($name));

		return $this->format($st->fetch());
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function format(array $row): array
	{
		$row['claim'] = json_decode((string) $row['claim'], true);

		return $row;
	}
}
