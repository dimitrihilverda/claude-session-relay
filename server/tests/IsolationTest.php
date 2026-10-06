<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/** Uses */

use Relay\Http\Response;

/**
 * Team isolation: Carol (team beta only) must not see or infer anything of team acme, and
 * private sessions are invisible to everybody but their own person.
 *
 * Alice: acme + beta. Bob: acme. Carol: beta.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class IsolationTest extends DbTestCase
{
	private string $alice;

	private string $bob;

	private string $carol;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		$this->alice = $this->person('alice')['token'];
		$this->bob = $this->person('bob')['token'];
		$this->carol = $this->person('carol')['token'];
		$this->team('acme', 'alice', 'bob');
		$this->team('beta', 'alice', 'carol');

		//Everybody works in the same repo on the same branch, so only the team keeps them apart:
		$this->register($this->alice, 'alice-mi', array('team' => 'acme', 'claim' => array('src/')));
		$this->register($this->alice, 'alice-beta', array('team' => 'beta', 'claim' => array('src/')));
		$this->register($this->alice, 'alice-priv', array('team' => 'private', 'claim' => array('src/')));
		$this->register($this->bob, 'bob-mi', array('team' => 'acme', 'claim' => array('src/')));
		$this->register($this->carol, 'carol-beta', array('team' => 'beta', 'claim' => array('src/')));
		$this->register($this->carol, 'carol-priv', array('team' => 'private', 'claim' => array('src/')));
	}

	/**
	 * Asserts that $hidden behaves exactly like $missing (status and body).
	 * @param Response $hidden
	 * @param Response $missing
	 * @param int $status
	 * @return void
	 */
	private static function assertSameAsMissing(Response $hidden, Response $missing, int $status = 404): void
	{
		self::assertSame($status, $missing->status, (string) json_encode($missing->data));
		self::assertSame($missing->status, $hidden->status, (string) json_encode($hidden->data));
		self::assertSame($missing->data, $hidden->data);
	}

	/**
	 * @param string $token
	 * @param array<string, mixed> $query
	 * @return list<string>
	 */
	private function board(string $token, array $query = array()): array
	{
		$response = $this->request('GET', '/board', $token, array(), $query);
		self::assertSame(200, $response->status);

		return array_column($response->data['sessions'], 'name');
	}

	/**
	 * @param string $token
	 * @param string $from
	 * @param string $to
	 * @param string $kind
	 * @return Response
	 */
	private function message(string $token, string $from, string $to, string $kind = 'note'): Response
	{
		return $this->request('POST', '/message', $token, array('from' => $from, 'to' => $to, 'kind' => $kind, 'text' => 'hi'));
	}

	/**
	 * @param string $token
	 * @param string $session
	 * @return list<string> Texts of the unread messages.
	 */
	private function inbox(string $token, string $session): array
	{
		$response = $this->request('GET', '/inbox', $token, array(), array('session' => $session));
		self::assertSame(200, $response->status, (string) json_encode($response->data));

		return array_column($response->data['messages'], 'text');
	}

	/**
	 * @return void
	 */
	public function testBoardOfMezShowsOnlyGtiAndHisOwnPrivateSessions(): void
	{
		$response = $this->request('GET', '/board', $this->carol);
		self::assertSame(array('alice-beta', 'carol-beta', 'carol-priv'), array_column($response->data['sessions'], 'name'));
		self::assertSame(array('beta', 'beta', 'private'), array_column($response->data['sessions'], 'team'));
		self::assertStringNotContainsString('acme', (string) json_encode($response->data));
		self::assertStringNotContainsString('bob', strtolower((string) json_encode($response->data)));
		self::assertStringNotContainsString('alice-mi', (string) json_encode($response->data));
	}

	/**
	 * @return void
	 */
	public function testBoardFilterOnForeignTeamLooksLikeUnknownTeam(): void
	{
		self::assertSame(array(), $this->board($this->carol, array('team' => 'acme')));
		self::assertSame(array(), $this->board($this->carol, array('team' => 'no-such-team')));
		self::assertSame(array('carol-priv'), $this->board($this->carol, array('team' => 'private')));
	}

	/**
	 * @return void
	 */
	public function testBoardOfOthers(): void
	{
		self::assertSame(array('alice-mi', 'bob-mi'), $this->board($this->bob));
		self::assertSame(array('alice-mi', 'bob-mi', 'alice-beta', 'carol-beta', 'alice-priv'), $this->board($this->alice));
	}

	/**
	 * @return void
	 */
	public function testCheckNeverReportsOtherTeams(): void
	{
		$body = array('repo_base' => 'app', 'branch' => 'test', 'paths' => array('src/a.ts'));

		$carol = $this->request('POST', '/check', $this->carol, array('session' => 'carol-beta') + $body)->data['conflicts'];
		self::assertSame(array('alice-beta'), array_column($carol, 'session'));

		self::assertSame(array(), $this->request('POST', '/check', $this->carol, array('session' => 'carol-priv') + $body)->data['conflicts']);

		$bob = $this->request('POST', '/check', $this->bob, array('session' => 'bob-mi') + $body)->data['conflicts'];
		self::assertSame(array('alice-mi'), array_column($bob, 'session'));
	}

	/**
	 * @return void
	 */
	public function testCheckWithForeignSessionLooksLikeUnknownSession(): void
	{
		$body = array('repo_base' => 'app', 'branch' => 'test', 'paths' => array());
		self::assertSameAsMissing(
			$this->request('POST', '/check', $this->carol, array('session' => 'alice-mi') + $body),
			$this->request('POST', '/check', $this->carol, array('session' => 'alice-nothing') + $body)
		);
	}

	/**
	 * @return void
	 */
	public function testMessageToMovingInLooksLikeUnknownRecipient(): void
	{
		$missingSession = $this->message($this->carol, 'carol-beta', 'nobody-1');
		$missingPerson = $this->message($this->carol, 'carol-beta', 'nobody');
		self::assertSame($missingSession->data, $missingPerson->data);

		self::assertSameAsMissing($this->message($this->carol, 'carol-beta', 'bob-mi'), $missingSession);
		self::assertSameAsMissing($this->message($this->carol, 'carol-beta', 'alice-mi'), $missingSession);
		self::assertSameAsMissing($this->message($this->carol, 'carol-beta', 'bob'), $missingPerson);
		self::assertSameAsMissing($this->message($this->carol, 'carol-beta', 'BOB'), $missingPerson);
		self::assertSameAsMissing($this->message($this->carol, 'carol-beta', 'alice-priv'), $missingSession);
		self::assertSameAsMissing($this->message($this->carol, 'carol-beta', 'bob-mi', 'question'), $missingSession);
		self::assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM message')->fetchColumn());

		//What Carol may reach in beta:
		self::assertSame(201, $this->message($this->carol, 'carol-beta', 'alice-beta')->status);
		self::assertSame(201, $this->message($this->carol, 'carol-beta', 'alice')->status);
	}

	/**
	 * @return void
	 */
	public function testMessageStaysInsideTheFromSessionsTeam(): void
	{
		//Alice may see both sessions, but a message never crosses teams:
		$missing = $this->message($this->alice, 'alice-beta', 'nobody-1');
		self::assertSameAsMissing($this->message($this->alice, 'alice-beta', 'bob-mi'), $missing);
		self::assertSameAsMissing($this->message($this->alice, 'alice-beta', 'bob'), $missing);
		self::assertSameAsMissing($this->message($this->alice, 'alice-mi', 'carol-beta'), $missing);
		self::assertSameAsMissing($this->message($this->alice, 'alice-mi', 'carol'), $missing);
		self::assertSameAsMissing($this->message($this->alice, 'alice-beta', 'alice-mi'), $missing);
		self::assertSameAsMissing($this->message($this->alice, 'alice-priv', 'alice-beta'), $missing);
		self::assertSameAsMissing($this->message($this->carol, 'carol-beta', 'carol-priv'), $missing);
	}

	/**
	 * @return void
	 */
	public function testFromForeignSessionLooksLikeUnknownSession(): void
	{
		self::assertSameAsMissing($this->message($this->carol, 'alice-mi', 'carol-beta'), $this->message($this->carol, 'alice-nothing', 'carol-beta'));
		self::assertSameAsMissing($this->message($this->carol, 'alice-beta', 'carol-beta'), $this->message($this->carol, 'alice-nothing', 'carol-beta'));
	}

	/**
	 * @return void
	 */
	public function testAnswerToMovingInMessageLooksLikeUnknownMessage(): void
	{
		$question = $this->request('POST', '/message', $this->alice, array('from' => 'alice-mi', 'to' => 'bob', 'kind' => 'question', 'text' => 'secret?'))->data['id'];
		$answer = fn(int $id): Response => $this->request('POST', '/message', $this->carol, array('from' => 'carol-beta', 'kind' => 'answer', 'reply_to' => $id, 'text' => 'x'));

		self::assertSameAsMissing($answer($question), $answer($question + 1000));

		//Alice himself cannot answer it from another team either:
		$fromGti = $this->request('POST', '/message', $this->alice, array('from' => 'alice-beta', 'kind' => 'answer', 'reply_to' => $question, 'text' => 'x'));
		self::assertSameAsMissing($fromGti, $answer($question + 1000));
	}

	/**
	 * @return void
	 */
	public function testRegisteringInForeignTeamLooksLikeUnknownTeam(): void
	{
		$body = fn(string $name, string $team): array => array('name' => $name, 'team' => $team, 'machine' => 'pc', 'repo' => 'app', 'repo_base' => 'app');
		$missing = $this->request('POST', '/session', $this->carol, $body('carol-2', 'no-such-team'));
		self::assertSame(array('error' => 'Unknown team.'), $missing->data);

		self::assertSameAsMissing($this->request('POST', '/session', $this->carol, $body('carol-2', 'acme')), $missing);
		self::assertSameAsMissing($this->request('POST', '/session', $this->carol, $body('carol-beta', 'acme')), $missing);
		self::assertSame(array('carol-beta', 'carol-priv'), array_column(
			$this->pdo->query("SELECT name FROM session WHERE person_id = (SELECT id FROM person WHERE name = 'carol') ORDER BY name")->fetchAll(),
			'name'
		));
	}

	/**
	 * @return void
	 */
	public function testReRegisteringForeignSessionNameLooksLikeUnknownName(): void
	{
		$body = fn(string $name): array => array('name' => $name, 'team' => 'beta', 'machine' => 'pc', 'repo' => 'app', 'repo_base' => 'app');
		$missing = $this->request('POST', '/session', $this->carol, $body('alice-nothing'));

		self::assertSameAsMissing($this->request('POST', '/session', $this->carol, $body('alice-mi')), $missing);
		self::assertSameAsMissing($this->request('POST', '/session', $this->carol, $body('alice-priv')), $missing);
		self::assertSameAsMissing($this->request('POST', '/session', $this->carol, $body('bob-mi')), $missing);

		//A teammate's session may be called taken:
		self::assertSame(409, $this->request('POST', '/session', $this->carol, $body('alice-beta'))->status);

		//And nothing was changed:
		self::assertSame('acme', $this->pdo->query("SELECT team FROM session WHERE name = 'alice-mi'")->fetchColumn());
	}

	/**
	 * @return void
	 */
	public function testDeletingForeignSessionLooksLikeUnknownName(): void
	{
		$missing = $this->request('DELETE', '/session/alice-nothing', $this->carol);
		self::assertSameAsMissing($this->request('DELETE', '/session/alice-mi', $this->carol), $missing);
		self::assertSameAsMissing($this->request('DELETE', '/session/alice-priv', $this->bob), $this->request('DELETE', '/session/alice-nothing', $this->bob));
		self::assertSame(6, (int) $this->pdo->query('SELECT count(*) FROM session')->fetchColumn());
	}

	/**
	 * @return void
	 */
	public function testInboxOfForeignSessionLooksLikeUnknownName(): void
	{
		$this->message($this->bob, 'bob-mi', 'alice-mi');
		$inbox = fn(string $token, string $session): Response => $this->request('GET', '/inbox', $token, array(), array('session' => $session));

		self::assertSameAsMissing($inbox($this->carol, 'alice-mi'), $inbox($this->carol, 'alice-nothing'));
		self::assertSameAsMissing($inbox($this->bob, 'alice-priv'), $inbox($this->bob, 'alice-nothing'));

		//And the message is still unread for Alice:
		self::assertSame(array('hi'), $this->inbox($this->alice, 'alice-mi'));
	}

	/**
	 * @return void
	 */
	public function testPersonMessageStaysInItsTeam(): void
	{
		$this->register($this->alice, 'alice-gti2', array('team' => 'beta'));
		$this->register($this->alice, 'alice-mi2', array('team' => 'acme'));
		$send = fn(string $token, string $from, string $text): int => $this->request('POST', '/message', $token, array('from' => $from, 'to' => 'alice', 'kind' => 'note', 'text' => $text))->status;

		self::assertSame(201, $send($this->alice, 'alice-beta', 'from beta'));
		self::assertSame(201, $send($this->alice, 'alice-mi', 'from acme'));
		self::assertSame(201, $send($this->carol, 'carol-beta', 'from carol'));
		self::assertSame(201, $send($this->alice, 'alice-priv', 'from private'));

		self::assertSame(array('from acme'), $this->inbox($this->alice, 'alice-mi2'));
		self::assertSame(array('from beta', 'from carol'), $this->inbox($this->alice, 'alice-gti2'));
		self::assertSame(array('from carol'), $this->inbox($this->alice, 'alice-beta'));
		self::assertSame(array(), $this->inbox($this->alice, 'alice-mi'));
		self::assertSame(array(), $this->inbox($this->alice, 'alice-priv'));
	}

	/**
	 * @return void
	 */
	public function testPrivateSessionsAreInvisibleToTeammates(): void
	{
		//Bob shares acme with Alice, Carol shares beta, neither sees alice-priv:
		self::assertNotContains('alice-priv', $this->board($this->bob));
		self::assertNotContains('alice-priv', $this->board($this->carol));
		self::assertNotContains('carol-priv', $this->board($this->alice));

		$missing = $this->message($this->bob, 'bob-mi', 'nobody-1');
		self::assertSameAsMissing($this->message($this->bob, 'bob-mi', 'alice-priv'), $missing);

		$body = array('repo_base' => 'app', 'branch' => 'test', 'paths' => array('src/a.ts'));
		self::assertNotContains('alice-priv', array_column($this->request('POST', '/check', $this->bob, array('session' => 'bob-mi') + $body)->data['conflicts'], 'session'));
		self::assertSame(array(), $this->request('POST', '/check', $this->alice, array('session' => 'alice-priv') + $body)->data['conflicts']);

		//A private session may only reach its own person:
		self::assertSameAsMissing($this->message($this->alice, 'alice-priv', 'bob'), $missing);
		self::assertSame(201, $this->message($this->alice, 'alice-priv', 'alice')->status);
	}

	/**
	 * Moving a session to another team must not carry its claim or its history along.
	 * @return void
	 */
	public function testSessionMovedToAnotherTeamLeavesClaimAndHistoryBehind(): void
	{
		$this->register($this->alice, 'alice-x', array('team' => 'acme', 'claim' => array('secret/acme-plan.md')));
		$this->message($this->bob, 'bob-mi', 'alice-x');
		$this->pdo->exec("UPDATE session SET started_at = now() - interval '1 hour' WHERE name = 'alice-x'");
		$this->pdo->exec("UPDATE message SET created_at = now() - interval '1 minute'");

		//Heartbeat into beta without a claim:
		$moved = $this->request('POST', '/session', $this->alice, array('name' => 'alice-x', 'team' => 'beta', 'machine' => 'pc', 'repo' => 'app', 'repo_base' => 'app'));
		self::assertSame(200, $moved->status);
		self::assertSame(array(), $moved->data['session']['claim']);

		$seen = array_values(array_filter($this->request('GET', '/board', $this->carol)->data['sessions'], static fn(array $s): bool => $s['name'] === 'alice-x'));
		self::assertSame(array(), $seen[0]['claim']);
		self::assertStringNotContainsString('secret', (string) json_encode($this->request('GET', '/board', $this->carol)->data));
		$age = (int) $this->pdo->query("SELECT extract(epoch FROM now() - started_at) FROM session WHERE name = 'alice-x'")->fetchColumn();
		self::assertLessThan(5, $age);

		$conflicts = $this->request('POST', '/check', $this->carol, array('session' => 'carol-beta', 'repo_base' => 'app', 'branch' => 'other', 'paths' => array('secret/acme-plan.md')))->data['conflicts'];
		self::assertSame(array(), $conflicts);

		//A claim sent along with the move is used:
		$withClaim = $this->register($this->alice, 'alice-y', array('team' => 'acme', 'claim' => array('a/')));
		self::assertSame(array('a/'), $withClaim['claim']);
		self::assertSame(array('b/'), $this->register($this->alice, 'alice-y', array('team' => 'beta', 'claim' => array('b/')))['claim']);

		//Same team without claim still keeps it:
		$this->request('POST', '/session', $this->alice, array('name' => 'alice-y', 'machine' => 'pc', 'repo' => 'app', 'repo_base' => 'app'));
		self::assertSame(array('b/'), $this->register($this->alice, 'alice-y', array('team' => 'beta', 'claim' => array('b/')))['claim']);
		self::assertSame(array('b/'), $this->request('POST', '/session', $this->alice, array('name' => 'alice-y', 'team' => 'beta', 'machine' => 'pc', 'repo' => 'app', 'repo_base' => 'app'))->data['session']['claim']);
	}

	/**
	 * The session limit only counts my own sessions and says nothing about anyone else.
	 * @return void
	 */
	public function testSessionLimitIsPerPerson(): void
	{
		for($i = 0; $i < 28; $i++) {
			$this->register($this->carol, "carol-n$i", array('team' => 'beta'));
		}
		$tooMany = $this->request('POST', '/session', $this->carol, array('name' => 'carol-extra', 'team' => 'beta', 'machine' => 'pc', 'repo' => 'app', 'repo_base' => 'app'));
		self::assertSame(422, $tooMany->status);
		self::assertSame(array('error' => 'Too many sessions.'), $tooMany->data);

		//Existing sessions keep their heartbeat, other persons are not affected:
		self::assertSame(200, $this->request('POST', '/session', $this->carol, array('name' => 'carol-n0', 'machine' => 'pc', 'repo' => 'app', 'repo_base' => 'app'))->status);
		$this->register($this->alice, 'alice-new', array('team' => 'beta'));

		//Expired sessions do not count:
		$this->pdo->exec("UPDATE session SET last_seen = now() - interval '11 minutes' WHERE name = 'carol-n1'");
		$this->register($this->carol, 'carol-extra', array('team' => 'beta'));
	}

	/**
	 * @return void
	 */
	public function testMeListsOnlyOwnTeams(): void
	{
		self::assertSame(array('person' => 'carol', 'teams' => array('beta')), $this->request('GET', '/me', $this->carol)->data);
		self::assertSame(array('person' => 'bob', 'teams' => array('acme')), $this->request('GET', '/me', $this->bob)->data);
		self::assertSame(array('person' => 'alice', 'teams' => array('acme', 'beta')), $this->request('GET', '/me', $this->alice)->data);
	}

	/**
	 * @return void
	 */
	public function testBoardPageOnlyUsesBoardAndMe(): void
	{
		$html = (string) $this->request('GET', '/', null)->html;
		preg_match_all("#fetch\\('([^'?]+)#", $html, $matches);
		self::assertEqualsCanonicalizing(array('/board', '/me'), array_values(array_unique($matches[1])));
		self::assertStringNotContainsString('innerHTML', $html);
		self::assertStringNotContainsString('/inbox', $html);
		self::assertStringNotContainsString('/message', $html);
	}
}
