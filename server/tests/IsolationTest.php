<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/** Uses */

use Relay\Http\Response;

/**
 * Team isolation: Mez (team gti only) must not see or infer anything of team moving-in, and
 * private sessions are invisible to everybody but their own person.
 *
 * Dimitri: moving-in + gti. Chantal: moving-in. Mez: gti.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class IsolationTest extends DbTestCase
{
	private string $dimitri;

	private string $chantal;

	private string $mez;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		$this->dimitri = $this->person('dimitri')['token'];
		$this->chantal = $this->person('chantal')['token'];
		$this->mez = $this->person('mez')['token'];
		$this->team('moving-in', 'dimitri', 'chantal');
		$this->team('gti', 'dimitri', 'mez');

		//Everybody works in the same repo on the same branch, so only the team keeps them apart:
		$this->register($this->dimitri, 'dimitri-mi', array('team' => 'moving-in', 'claim' => array('src/')));
		$this->register($this->dimitri, 'dimitri-gti', array('team' => 'gti', 'claim' => array('src/')));
		$this->register($this->dimitri, 'dimitri-priv', array('team' => 'private', 'claim' => array('src/')));
		$this->register($this->chantal, 'chantal-mi', array('team' => 'moving-in', 'claim' => array('src/')));
		$this->register($this->mez, 'mez-gti', array('team' => 'gti', 'claim' => array('src/')));
		$this->register($this->mez, 'mez-priv', array('team' => 'private', 'claim' => array('src/')));
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
		$response = $this->request('GET', '/board', $this->mez);
		self::assertSame(array('dimitri-gti', 'mez-gti', 'mez-priv'), array_column($response->data['sessions'], 'name'));
		self::assertSame(array('gti', 'gti', 'private'), array_column($response->data['sessions'], 'team'));
		self::assertStringNotContainsString('moving-in', (string) json_encode($response->data));
		self::assertStringNotContainsString('chantal', strtolower((string) json_encode($response->data)));
		self::assertStringNotContainsString('dimitri-mi', (string) json_encode($response->data));
	}

	/**
	 * @return void
	 */
	public function testBoardFilterOnForeignTeamLooksLikeUnknownTeam(): void
	{
		self::assertSame(array(), $this->board($this->mez, array('team' => 'moving-in')));
		self::assertSame(array(), $this->board($this->mez, array('team' => 'no-such-team')));
		self::assertSame(array('mez-priv'), $this->board($this->mez, array('team' => 'private')));
	}

	/**
	 * @return void
	 */
	public function testBoardOfOthers(): void
	{
		self::assertSame(array('chantal-mi', 'dimitri-mi'), $this->board($this->chantal));
		self::assertSame(array('dimitri-gti', 'mez-gti', 'chantal-mi', 'dimitri-mi', 'dimitri-priv'), $this->board($this->dimitri));
	}

	/**
	 * @return void
	 */
	public function testCheckNeverReportsOtherTeams(): void
	{
		$body = array('repo_base' => 'app', 'branch' => 'test', 'paths' => array('src/a.ts'));

		$mez = $this->request('POST', '/check', $this->mez, array('session' => 'mez-gti') + $body)->data['conflicts'];
		self::assertSame(array('dimitri-gti'), array_column($mez, 'session'));

		self::assertSame(array(), $this->request('POST', '/check', $this->mez, array('session' => 'mez-priv') + $body)->data['conflicts']);

		$chantal = $this->request('POST', '/check', $this->chantal, array('session' => 'chantal-mi') + $body)->data['conflicts'];
		self::assertSame(array('dimitri-mi'), array_column($chantal, 'session'));
	}

	/**
	 * @return void
	 */
	public function testCheckWithForeignSessionLooksLikeUnknownSession(): void
	{
		$body = array('repo_base' => 'app', 'branch' => 'test', 'paths' => array());
		self::assertSameAsMissing(
			$this->request('POST', '/check', $this->mez, array('session' => 'dimitri-mi') + $body),
			$this->request('POST', '/check', $this->mez, array('session' => 'dimitri-nothing') + $body)
		);
	}

	/**
	 * @return void
	 */
	public function testMessageToMovingInLooksLikeUnknownRecipient(): void
	{
		$missingSession = $this->message($this->mez, 'mez-gti', 'nobody-1');
		$missingPerson = $this->message($this->mez, 'mez-gti', 'nobody');
		self::assertSame($missingSession->data, $missingPerson->data);

		self::assertSameAsMissing($this->message($this->mez, 'mez-gti', 'chantal-mi'), $missingSession);
		self::assertSameAsMissing($this->message($this->mez, 'mez-gti', 'dimitri-mi'), $missingSession);
		self::assertSameAsMissing($this->message($this->mez, 'mez-gti', 'chantal'), $missingPerson);
		self::assertSameAsMissing($this->message($this->mez, 'mez-gti', 'CHANTAL'), $missingPerson);
		self::assertSameAsMissing($this->message($this->mez, 'mez-gti', 'dimitri-priv'), $missingSession);
		self::assertSameAsMissing($this->message($this->mez, 'mez-gti', 'chantal-mi', 'question'), $missingSession);
		self::assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM message')->fetchColumn());

		//What Mez may reach in gti:
		self::assertSame(201, $this->message($this->mez, 'mez-gti', 'dimitri-gti')->status);
		self::assertSame(201, $this->message($this->mez, 'mez-gti', 'dimitri')->status);
	}

	/**
	 * @return void
	 */
	public function testMessageStaysInsideTheFromSessionsTeam(): void
	{
		//Dimitri may see both sessions, but a message never crosses teams:
		$missing = $this->message($this->dimitri, 'dimitri-gti', 'nobody-1');
		self::assertSameAsMissing($this->message($this->dimitri, 'dimitri-gti', 'chantal-mi'), $missing);
		self::assertSameAsMissing($this->message($this->dimitri, 'dimitri-gti', 'chantal'), $missing);
		self::assertSameAsMissing($this->message($this->dimitri, 'dimitri-mi', 'mez-gti'), $missing);
		self::assertSameAsMissing($this->message($this->dimitri, 'dimitri-mi', 'mez'), $missing);
		self::assertSameAsMissing($this->message($this->dimitri, 'dimitri-gti', 'dimitri-mi'), $missing);
		self::assertSameAsMissing($this->message($this->dimitri, 'dimitri-priv', 'dimitri-gti'), $missing);
		self::assertSameAsMissing($this->message($this->mez, 'mez-gti', 'mez-priv'), $missing);
	}

	/**
	 * @return void
	 */
	public function testFromForeignSessionLooksLikeUnknownSession(): void
	{
		self::assertSameAsMissing($this->message($this->mez, 'dimitri-mi', 'mez-gti'), $this->message($this->mez, 'dimitri-nothing', 'mez-gti'));
		self::assertSameAsMissing($this->message($this->mez, 'dimitri-gti', 'mez-gti'), $this->message($this->mez, 'dimitri-nothing', 'mez-gti'));
	}

	/**
	 * @return void
	 */
	public function testAnswerToMovingInMessageLooksLikeUnknownMessage(): void
	{
		$question = $this->request('POST', '/message', $this->dimitri, array('from' => 'dimitri-mi', 'to' => 'chantal', 'kind' => 'question', 'text' => 'secret?'))->data['id'];
		$answer = fn(int $id): Response => $this->request('POST', '/message', $this->mez, array('from' => 'mez-gti', 'kind' => 'answer', 'reply_to' => $id, 'text' => 'x'));

		self::assertSameAsMissing($answer($question), $answer($question + 1000));

		//Dimitri himself cannot answer it from another team either:
		$fromGti = $this->request('POST', '/message', $this->dimitri, array('from' => 'dimitri-gti', 'kind' => 'answer', 'reply_to' => $question, 'text' => 'x'));
		self::assertSameAsMissing($fromGti, $answer($question + 1000));
	}

	/**
	 * @return void
	 */
	public function testRegisteringInForeignTeamLooksLikeUnknownTeam(): void
	{
		$body = fn(string $name, string $team): array => array('name' => $name, 'team' => $team, 'machine' => 'pc', 'repo' => 'app', 'repo_base' => 'app');
		$missing = $this->request('POST', '/session', $this->mez, $body('mez-2', 'no-such-team'));
		self::assertSame(array('error' => 'Unknown team.'), $missing->data);

		self::assertSameAsMissing($this->request('POST', '/session', $this->mez, $body('mez-2', 'moving-in')), $missing);
		self::assertSameAsMissing($this->request('POST', '/session', $this->mez, $body('mez-gti', 'moving-in')), $missing);
		self::assertSame(array('mez-gti', 'mez-priv'), array_column(
			$this->pdo->query("SELECT name FROM session WHERE person_id = (SELECT id FROM person WHERE name = 'mez') ORDER BY name")->fetchAll(),
			'name'
		));
	}

	/**
	 * @return void
	 */
	public function testReRegisteringForeignSessionNameLooksLikeUnknownName(): void
	{
		$body = fn(string $name): array => array('name' => $name, 'team' => 'gti', 'machine' => 'pc', 'repo' => 'app', 'repo_base' => 'app');
		$missing = $this->request('POST', '/session', $this->mez, $body('dimitri-nothing'));

		self::assertSameAsMissing($this->request('POST', '/session', $this->mez, $body('dimitri-mi')), $missing);
		self::assertSameAsMissing($this->request('POST', '/session', $this->mez, $body('dimitri-priv')), $missing);
		self::assertSameAsMissing($this->request('POST', '/session', $this->mez, $body('chantal-mi')), $missing);

		//A teammate's session may be called taken:
		self::assertSame(409, $this->request('POST', '/session', $this->mez, $body('dimitri-gti'))->status);

		//And nothing was changed:
		self::assertSame('moving-in', $this->pdo->query("SELECT team FROM session WHERE name = 'dimitri-mi'")->fetchColumn());
	}

	/**
	 * @return void
	 */
	public function testDeletingForeignSessionLooksLikeUnknownName(): void
	{
		$missing = $this->request('DELETE', '/session/dimitri-nothing', $this->mez);
		self::assertSameAsMissing($this->request('DELETE', '/session/dimitri-mi', $this->mez), $missing);
		self::assertSameAsMissing($this->request('DELETE', '/session/dimitri-priv', $this->chantal), $this->request('DELETE', '/session/dimitri-nothing', $this->chantal));
		self::assertSame(6, (int) $this->pdo->query('SELECT count(*) FROM session')->fetchColumn());
	}

	/**
	 * @return void
	 */
	public function testInboxOfForeignSessionLooksLikeUnknownName(): void
	{
		$this->message($this->chantal, 'chantal-mi', 'dimitri-mi');
		$inbox = fn(string $token, string $session): Response => $this->request('GET', '/inbox', $token, array(), array('session' => $session));

		self::assertSameAsMissing($inbox($this->mez, 'dimitri-mi'), $inbox($this->mez, 'dimitri-nothing'));
		self::assertSameAsMissing($inbox($this->chantal, 'dimitri-priv'), $inbox($this->chantal, 'dimitri-nothing'));

		//And the message is still unread for Dimitri:
		self::assertSame(array('hi'), $this->inbox($this->dimitri, 'dimitri-mi'));
	}

	/**
	 * @return void
	 */
	public function testPersonMessageStaysInItsTeam(): void
	{
		$this->register($this->dimitri, 'dimitri-gti2', array('team' => 'gti'));
		$this->register($this->dimitri, 'dimitri-mi2', array('team' => 'moving-in'));
		$send = fn(string $token, string $from, string $text): int => $this->request('POST', '/message', $token, array('from' => $from, 'to' => 'dimitri', 'kind' => 'note', 'text' => $text))->status;

		self::assertSame(201, $send($this->dimitri, 'dimitri-gti', 'from gti'));
		self::assertSame(201, $send($this->dimitri, 'dimitri-mi', 'from moving-in'));
		self::assertSame(201, $send($this->mez, 'mez-gti', 'from mez'));
		self::assertSame(201, $send($this->dimitri, 'dimitri-priv', 'from private'));

		self::assertSame(array('from moving-in'), $this->inbox($this->dimitri, 'dimitri-mi2'));
		self::assertSame(array('from gti', 'from mez'), $this->inbox($this->dimitri, 'dimitri-gti2'));
		self::assertSame(array('from mez'), $this->inbox($this->dimitri, 'dimitri-gti'));
		self::assertSame(array(), $this->inbox($this->dimitri, 'dimitri-mi'));
		self::assertSame(array(), $this->inbox($this->dimitri, 'dimitri-priv'));
	}

	/**
	 * @return void
	 */
	public function testPrivateSessionsAreInvisibleToTeammates(): void
	{
		//Chantal shares moving-in with Dimitri, Mez shares gti, neither sees dimitri-priv:
		self::assertNotContains('dimitri-priv', $this->board($this->chantal));
		self::assertNotContains('dimitri-priv', $this->board($this->mez));
		self::assertNotContains('mez-priv', $this->board($this->dimitri));

		$missing = $this->message($this->chantal, 'chantal-mi', 'nobody-1');
		self::assertSameAsMissing($this->message($this->chantal, 'chantal-mi', 'dimitri-priv'), $missing);

		$body = array('repo_base' => 'app', 'branch' => 'test', 'paths' => array('src/a.ts'));
		self::assertNotContains('dimitri-priv', array_column($this->request('POST', '/check', $this->chantal, array('session' => 'chantal-mi') + $body)->data['conflicts'], 'session'));
		self::assertSame(array(), $this->request('POST', '/check', $this->dimitri, array('session' => 'dimitri-priv') + $body)->data['conflicts']);

		//A private session may only reach its own person:
		self::assertSameAsMissing($this->message($this->dimitri, 'dimitri-priv', 'chantal'), $missing);
		self::assertSame(201, $this->message($this->dimitri, 'dimitri-priv', 'dimitri')->status);
	}

	/**
	 * Moving a session to another team must not carry its claim or its history along.
	 * @return void
	 */
	public function testSessionMovedToAnotherTeamLeavesClaimAndHistoryBehind(): void
	{
		$this->register($this->dimitri, 'dimitri-x', array('team' => 'moving-in', 'claim' => array('secret/moving-in-plan.md')));
		$this->message($this->chantal, 'chantal-mi', 'dimitri-x');
		$this->pdo->exec("UPDATE session SET started_at = now() - interval '1 hour' WHERE name = 'dimitri-x'");
		$this->pdo->exec("UPDATE message SET created_at = now() - interval '1 minute'");

		//Heartbeat into gti without a claim:
		$moved = $this->request('POST', '/session', $this->dimitri, array('name' => 'dimitri-x', 'team' => 'gti', 'machine' => 'pc', 'repo' => 'app', 'repo_base' => 'app'));
		self::assertSame(200, $moved->status);
		self::assertSame(array(), $moved->data['session']['claim']);

		$seen = array_values(array_filter($this->request('GET', '/board', $this->mez)->data['sessions'], static fn(array $s): bool => $s['name'] === 'dimitri-x'));
		self::assertSame(array(), $seen[0]['claim']);
		self::assertStringNotContainsString('secret', (string) json_encode($this->request('GET', '/board', $this->mez)->data));
		$age = (int) $this->pdo->query("SELECT extract(epoch FROM now() - started_at) FROM session WHERE name = 'dimitri-x'")->fetchColumn();
		self::assertLessThan(5, $age);

		$conflicts = $this->request('POST', '/check', $this->mez, array('session' => 'mez-gti', 'repo_base' => 'app', 'branch' => 'other', 'paths' => array('secret/moving-in-plan.md')))->data['conflicts'];
		self::assertSame(array(), $conflicts);

		//A claim sent along with the move is used:
		$withClaim = $this->register($this->dimitri, 'dimitri-y', array('team' => 'moving-in', 'claim' => array('a/')));
		self::assertSame(array('a/'), $withClaim['claim']);
		self::assertSame(array('b/'), $this->register($this->dimitri, 'dimitri-y', array('team' => 'gti', 'claim' => array('b/')))['claim']);

		//Same team without claim still keeps it:
		$this->request('POST', '/session', $this->dimitri, array('name' => 'dimitri-y', 'machine' => 'pc', 'repo' => 'app', 'repo_base' => 'app'));
		self::assertSame(array('b/'), $this->register($this->dimitri, 'dimitri-y', array('team' => 'gti', 'claim' => array('b/')))['claim']);
		self::assertSame(array('b/'), $this->request('POST', '/session', $this->dimitri, array('name' => 'dimitri-y', 'team' => 'gti', 'machine' => 'pc', 'repo' => 'app', 'repo_base' => 'app'))->data['session']['claim']);
	}

	/**
	 * The session limit only counts my own sessions and says nothing about anyone else.
	 * @return void
	 */
	public function testSessionLimitIsPerPerson(): void
	{
		for($i = 0; $i < 28; $i++) {
			$this->register($this->mez, "mez-n$i", array('team' => 'gti'));
		}
		$tooMany = $this->request('POST', '/session', $this->mez, array('name' => 'mez-extra', 'team' => 'gti', 'machine' => 'pc', 'repo' => 'app', 'repo_base' => 'app'));
		self::assertSame(422, $tooMany->status);
		self::assertSame(array('error' => 'Too many sessions.'), $tooMany->data);

		//Existing sessions keep their heartbeat, other persons are not affected:
		self::assertSame(200, $this->request('POST', '/session', $this->mez, array('name' => 'mez-n0', 'machine' => 'pc', 'repo' => 'app', 'repo_base' => 'app'))->status);
		$this->register($this->dimitri, 'dimitri-new', array('team' => 'gti'));

		//Expired sessions do not count:
		$this->pdo->exec("UPDATE session SET last_seen = now() - interval '11 minutes' WHERE name = 'mez-n1'");
		$this->register($this->mez, 'mez-extra', array('team' => 'gti'));
	}

	/**
	 * @return void
	 */
	public function testMeListsOnlyOwnTeams(): void
	{
		self::assertSame(array('person' => 'mez', 'teams' => array('gti')), $this->request('GET', '/me', $this->mez)->data);
		self::assertSame(array('person' => 'chantal', 'teams' => array('moving-in')), $this->request('GET', '/me', $this->chantal)->data);
		self::assertSame(array('person' => 'dimitri', 'teams' => array('gti', 'moving-in')), $this->request('GET', '/me', $this->dimitri)->data);
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
