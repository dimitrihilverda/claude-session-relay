<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/** Uses */

use InvalidArgumentException;
use Relay\TeamStore;

/**
 * Tests team administration (the CLI uses it).
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class TeamStoreTest extends DbTestCase
{
	private TeamStore $teams;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		$this->teams = new TeamStore($this->pdo);
		$this->person('Alice');
		$this->person('Bob');
	}

	/**
	 * @return void
	 */
	public function testCreateAddAndList(): void
	{
		$this->teams->create('gti');
		$this->teams->create('moving-in');
		$this->teams->add('gti', 'alice');
		$this->teams->add('gti', 'Bob');
		$this->teams->add('gti', 'Bob');

		self::assertSame(
			array(array('name' => 'gti', 'members' => array('Alice', 'Bob')), array('name' => 'moving-in', 'members' => array())),
			$this->teams->list()
		);
	}

	/**
	 * @return list<array{string}>
	 */
	public static function invalidNames(): array
	{
		return array(array('private'), array('Upper'), array('-dash'), array(''), array('a b'), array(str_repeat('a', 41)));
	}

	/**
	 * @param string $name
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('invalidNames')]
	public function testInvalidTeamName(string $name): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->teams->create($name);
	}

	/**
	 * @return void
	 */
	public function testDuplicateTeam(): void
	{
		$this->teams->create('gti');
		$this->expectException(InvalidArgumentException::class);
		$this->teams->create('gti');
	}

	/**
	 * @return void
	 */
	public function testUnknownTeamOrPerson(): void
	{
		$this->teams->create('gti');
		foreach(array(array('nope', 'Alice'), array('gti', 'Nobody')) as [$team, $person]) {
			try {
				$this->teams->add($team, $person);
				self::fail("Expected an error for $team/$person.");
			} catch(InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
		$this->expectException(InvalidArgumentException::class);
		$this->teams->rename('nope', 'other');
	}

	/**
	 * @return void
	 */
	public function testRenameMovesSessionsAndMessages(): void
	{
		$alice = $this->person('Alice')['token'];
		$this->team('default', 'Alice');
		$this->register($alice, 'alice-1', array('team' => 'default'));
		$this->register($alice, 'alice-2', array('team' => 'private'));
		$this->request('POST', '/message', $alice, array('from' => 'alice-1', 'to' => 'alice', 'kind' => 'note', 'text' => 'x'));

		$this->teams->rename('default', 'moving-in');

		self::assertSame(array('moving-in', 'private'), $this->pdo->query('SELECT team FROM session ORDER BY name')->fetchAll(\PDO::FETCH_COLUMN));
		self::assertSame(array('moving-in'), $this->pdo->query('SELECT team FROM message')->fetchAll(\PDO::FETCH_COLUMN));
		self::assertSame(array('person' => 'Alice', 'teams' => array('moving-in')), $this->request('GET', '/me', $alice)->data);
		self::assertSame(array('alice-1', 'alice-2'), array_column($this->request('GET', '/board', $alice)->data['sessions'], 'name'));
	}

	/**
	 * @return void
	 */
	public function testRenameToInvalidOrExistingNameFails(): void
	{
		$this->teams->create('a');
		$this->teams->create('b');
		foreach(array('b', 'private', 'Not ok') as $name) {
			try {
				$this->teams->rename('a', $name);
				self::fail("Expected an error for $name.");
			} catch(InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}

	/**
	 * @return void
	 */
	public function testRemoveEndsAccessAndDropsSessionsInThatTeam(): void
	{
		$alice = $this->person('Alice')['token'];
		$bob = $this->person('Bob')['token'];
		$this->team('gti', 'Alice', 'Bob');
		$this->register($bob, 'bob-1', array('team' => 'gti'));
		$this->register($bob, 'bob-2', array('team' => 'private'));
		$this->register($alice, 'alice-1', array('team' => 'gti'));

		self::assertTrue($this->teams->remove('gti', 'bob'));
		self::assertFalse($this->teams->remove('gti', 'bob'));

		self::assertSame(array('alice-1'), array_column($this->request('GET', '/board', $alice)->data['sessions'], 'name'));
		self::assertSame(array('bob-2'), array_column($this->request('GET', '/board', $bob)->data['sessions'], 'name'));
		self::assertSame(404, $this->request('POST', '/session', $bob, array('name' => 'bob-1', 'team' => 'gti', 'machine' => 'pc', 'repo' => 'app', 'repo_base' => 'app'))->status);
		self::assertSame(404, $this->request('POST', '/message', $bob, array('from' => 'bob-2', 'to' => 'alice', 'kind' => 'note', 'text' => 'x'))->status);
	}

	/**
	 * @return void
	 */
	public function testRemoveRollsBackOnError(): void
	{
		$this->team('gti', 'Alice');
		$this->pdo->exec("CREATE FUNCTION fail() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'boom'; END \$\$");
		$this->pdo->exec('CREATE TRIGGER fail BEFORE DELETE ON session FOR EACH STATEMENT EXECUTE FUNCTION fail()');

		try {
			$this->teams->remove('gti', 'Alice');
			self::fail('Expected the trigger to fail.');
		} catch(\PDOException) {
			self::assertFalse($this->pdo->inTransaction());
		}
		self::assertSame(array('Alice'), $this->teams->list()[0]['members']);
	}
}
