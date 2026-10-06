<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/**
 * Tests registering, the board, unregistering, expiry and the conflict check.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class SessionApiTest extends DbTestCase
{
	private string $alice;

	private string $bob;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		$this->alice = $this->person('Alice')['token'];
		$this->bob = $this->person('Bob')['token'];
		$this->team('app', 'Alice', 'Bob');
	}

	/**
	 * @return void
	 */
	public function testRegisterAndBoard(): void
	{
		$session = $this->register($this->alice, 'Alice-App-A3F1', array('team' => 'app', 'claim' => array('src/stores/userStore.ts'), 'ticket' => 'SYN-1'));

		self::assertSame(
			array('name', 'person', 'team', 'machine', 'repo', 'repo_base', 'branch', 'ticket', 'claim', 'started_at', 'last_seen'),
			array_keys($session)
		);
		self::assertSame('alice-app-a3f1', $session['name']);
		self::assertSame('Alice', $session['person']);
		self::assertSame('app', $session['team']);
		self::assertSame('app', $session['repo_base']);
		self::assertSame(array('src/stores/userStore.ts'), $session['claim']);
		self::assertSame('SYN-1', $session['ticket']);
		self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $session['started_at']);

		$board = $this->request('GET', '/board', $this->bob)->data['sessions'];
		self::assertSame(array($session['name']), array_column($board, 'name'));
		self::assertSame('app', $board[0]['team']);
	}

	/**
	 * @return void
	 */
	public function testHeartbeatWithoutClaimOrTeamKeepsThem(): void
	{
		$this->register($this->alice, 'alice-1', array('team' => 'app', 'claim' => array('src/a.ts')));
		$response = $this->request('POST', '/session', $this->alice, array('name' => 'alice-1', 'machine' => 'pc', 'repo' => 'app', 'repo_base' => 'app', 'branch' => 'feature/x'));
		self::assertSame(200, $response->status, (string) json_encode($response->data));

		self::assertSame(array('src/a.ts'), $response->data['session']['claim']);
		self::assertSame('app', $response->data['session']['team']);
		self::assertSame('feature/x', $response->data['session']['branch']);
	}

	/**
	 * @return void
	 */
	public function testNewSessionNeedsTeam(): void
	{
		$response = $this->request('POST', '/session', $this->alice, array('name' => 'alice-1', 'machine' => 'pc', 'repo' => 'app', 'repo_base' => 'app'));
		self::assertSame(422, $response->status);
		self::assertSame(array('error' => 'Field team is required for a new session.'), $response->data);
	}

	/**
	 * @return void
	 */
	public function testSessionCanMoveToAnotherTeam(): void
	{
		$this->register($this->alice, 'alice-1', array('team' => 'app'));
		self::assertSame('private', $this->register($this->alice, 'alice-1', array('team' => 'private'))['team']);
		self::assertSame(array(), $this->request('GET', '/board', $this->bob)->data['sessions']);
	}

	/**
	 * @return void
	 */
	public function testBoardFiltersOnTeam(): void
	{
		$this->team('other', 'Alice');
		$this->register($this->alice, 'alice-1', array('team' => 'app'));
		$this->register($this->alice, 'alice-2', array('team' => 'other'));
		$this->register($this->alice, 'alice-3', array('team' => 'private'));

		$board = fn(array $query): array => array_column($this->request('GET', '/board', $this->alice, array(), $query)->data['sessions'], 'name');
		self::assertSame(array('alice-1', 'alice-2', 'alice-3'), $board(array()));
		self::assertSame(array('alice-2'), $board(array('team' => 'other')));
		self::assertSame(array('alice-3'), $board(array('team' => 'private')));
		self::assertSame(array(), $board(array('team' => 'nope')));
	}

	/**
	 * @return void
	 */
	public function testVisibleSessionOfSomeoneElseIs409(): void
	{
		$this->register($this->alice, 'alice-1', array('team' => 'app'));

		$takeover = $this->request('POST', '/session', $this->bob, array('name' => 'alice-1', 'team' => 'app', 'machine' => 'x', 'repo' => 'app', 'repo_base' => 'app'));
		self::assertSame(409, $takeover->status);
		self::assertSame(array('error' => 'Session name is taken.'), $takeover->data);
		self::assertSame(404, $this->request('DELETE', '/session/alice-1', $this->bob)->status);
		self::assertCount(1, $this->request('GET', '/board', $this->alice)->data['sessions']);
	}

	/**
	 * @return void
	 */
	public function testNameMustStartWithOwnPerson(): void
	{
		$foreign = $this->request('POST', '/session', $this->bob, array('name' => 'alice-app-a3f1', 'team' => 'app', 'machine' => 'x', 'repo' => 'app', 'repo_base' => 'app'));
		self::assertSame(404, $foreign->status);
		self::assertSame(array('error' => 'Unknown session. Your session names must start with bob-.'), $foreign->data);
		self::assertSame('bob-app-9b2c', $this->register($this->bob, 'Bob-App-9B2C')['name']);
	}

	/**
	 * @return void
	 */
	public function testUnregister(): void
	{
		$this->register($this->alice, 'alice-1');

		self::assertSame(204, $this->request('DELETE', '/session/alice-1', $this->alice)->status);
		self::assertSame(array(), $this->request('GET', '/board', $this->alice)->data['sessions']);
		self::assertSame(204, $this->request('DELETE', '/session/alice-1', $this->alice)->status);
	}

	/**
	 * @return void
	 */
	public function testExpiredSessionIsNotOnBoard(): void
	{
		$this->register($this->alice, 'alice-1');
		$this->pdo->exec("UPDATE session SET last_seen = now() - interval '11 minutes'");

		self::assertSame(array(), $this->request('GET', '/board', $this->alice)->data['sessions']);
	}

	/**
	 * @return void
	 */
	public function testInvalidInputIs422(): void
	{
		$base = array('name' => 'alice-1', 'team' => 'app', 'machine' => 'pc', 'repo' => 'app', 'repo_base' => 'app');
		self::assertSame(422, $this->request('POST', '/session', $this->alice, array('name' => 'd 1') + $base)->status);
		self::assertSame(422, $this->request('POST', '/session', $this->alice, array_diff_key($base, array('repo_base' => 1)))->status);
		self::assertSame(422, $this->request('POST', '/session', $this->alice, array('claim' => 'src') + $base)->status);
		self::assertSame(422, $this->request('POST', '/session', $this->alice, array('team' => array('app')) + $base)->status);
	}

	/**
	 * @return void
	 */
	public function testClaimIsLimitedTo1000Paths(): void
	{
		$base = array('name' => 'alice-1', 'team' => 'app', 'machine' => 'pc', 'repo' => 'app', 'repo_base' => 'app');
		$paths = array_map(static fn(int $i): string => "src/file$i.ts", range(1, 1001));

		$response = $this->request('POST', '/session', $this->alice, array('claim' => $paths) + $base);
		self::assertSame(422, $response->status);
		self::assertSame(array('error' => 'Field claim must be a list of at most 1000 paths.'), $response->data);
		self::assertCount(1000, $this->register($this->alice, 'alice-1', array('team' => 'app', 'claim' => array_slice($paths, 0, 1000)))['claim']);
	}

	/**
	 * @return void
	 */
	public function testCheckConflictsOnBranchOfOtherPersonInSameTeam(): void
	{
		$this->register($this->alice, 'alice-1', array('team' => 'app'));
		$this->register($this->bob, 'bob-1', array('team' => 'app'));

		$response = $this->request('POST', '/check', $this->alice, array('session' => 'alice-1', 'repo_base' => 'APP', 'branch' => 'test', 'paths' => array()));
		self::assertSame(200, $response->status, (string) json_encode($response->data));
		self::assertSame(array('conflicts' => array(array('session' => 'bob-1', 'person' => 'Bob', 'reason' => 'is also on branch test'))), $response->data);
	}

	/**
	 * @return void
	 */
	public function testCheckConflictsOnClaimAcrossWorktrees(): void
	{
		$this->register($this->alice, 'alice-1', array('team' => 'app'));
		$this->register($this->bob, 'bob-1', array('team' => 'app', 'repo' => 'wt-x-app', 'branch' => 'feature/x', 'claim' => array('src/stores/')));

		$hit = $this->request('POST', '/check', $this->alice, array('session' => 'alice-1', 'repo_base' => 'app', 'branch' => 'test', 'paths' => array('src\\stores\\userStore.ts')))->data['conflicts'];
		self::assertSame('claims src/stores/', $hit[0]['reason']);

		$free = $this->request('POST', '/check', $this->alice, array('session' => 'alice-1', 'repo_base' => 'app', 'branch' => 'test', 'paths' => array('src/pages/A.vue')))->data['conflicts'];
		self::assertSame(array(), $free);
	}

	/**
	 * @return void
	 */
	public function testCheckIgnoresOwnSessionsOtherReposExpiredAndOtherTeams(): void
	{
		$this->team('other', 'Alice', 'Bob');
		$this->register($this->alice, 'alice-1', array('team' => 'app'));
		$this->register($this->alice, 'alice-2', array('team' => 'app'));
		$this->register($this->bob, 'bob-1', array('team' => 'app', 'repo' => 'foundation', 'repo_base' => 'foundation'));
		$this->register($this->bob, 'bob-2', array('team' => 'app'));
		$this->register($this->bob, 'bob-3', array('team' => 'other'));
		$this->register($this->bob, 'bob-4', array('team' => 'private'));
		$this->pdo->exec("UPDATE session SET last_seen = now() - interval '11 minutes' WHERE name = 'bob-2'");

		$conflicts = $this->request('POST', '/check', $this->alice, array('session' => 'alice-1', 'repo_base' => 'app', 'branch' => 'test', 'paths' => array('.')))->data['conflicts'];
		self::assertSame(array(), $conflicts);
	}

	/**
	 * @return void
	 */
	public function testCheckFromPrivateSessionIsAlwaysEmpty(): void
	{
		$this->register($this->alice, 'alice-1', array('team' => 'private'));
		$this->register($this->bob, 'bob-1', array('team' => 'app'));

		$conflicts = $this->request('POST', '/check', $this->alice, array('session' => 'alice-1', 'repo_base' => 'app', 'branch' => 'test', 'paths' => array('.')))->data['conflicts'];
		self::assertSame(array(), $conflicts);
	}

	/**
	 * @return void
	 */
	public function testCheckNeedsOwnSession(): void
	{
		$this->register($this->bob, 'bob-1', array('team' => 'app'));
		$body = array('repo_base' => 'app', 'branch' => 'test', 'paths' => array());

		self::assertSame(422, $this->request('POST', '/check', $this->alice, $body)->status);
		self::assertSame(array('error' => 'Unknown session.'), $this->request('POST', '/check', $this->alice, array('session' => 'bob-1') + $body)->data);
		self::assertSame(array('error' => 'Unknown session.'), $this->request('POST', '/check', $this->alice, array('session' => 'alice-9') + $body)->data);
	}
}
