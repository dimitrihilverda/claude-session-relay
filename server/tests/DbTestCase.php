<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/** Uses */

use PDO;
use PHPUnit\Framework\TestCase;
use Relay\App;
use Relay\Auth;
use Relay\Config;
use Relay\Db;
use Relay\Http\Request;
use Relay\Http\Response;
use Relay\Migrator;
use Relay\TeamStore;

/**
 * Base for tests that need a real PostgreSQL database.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
abstract class DbTestCase extends TestCase
{
	protected PDO $pdo;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		//Fresh, empty schema per test:
		$this->pdo = Db::connect(Config::load('/nonexistent'));
		$this->pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public');
		(new Migrator($this->pdo, __DIR__ . '/../migrations'))->migrate();
	}

	/**
	 * @param string $name
	 * @return array{id:int,name:string,token:string}
	 */
	protected function person(string $name): array
	{
		return (new Auth($this->pdo))->createPerson($name);
	}

	/**
	 * Creates a team (if needed) and adds the given persons to it.
	 * @param string $team
	 * @param string ...$persons
	 * @return void
	 */
	protected function team(string $team, string ...$persons): void
	{
		$teams = new TeamStore($this->pdo);
		if(in_array($team, array_column($teams->list(), 'name'), true) === false) {
			$teams->create($team);
		}
		foreach($persons as $person) {
			$teams->add($team, $person);
		}
	}

	/**
	 * @param string $method
	 * @param string $path
	 * @param string|null $token
	 * @param array<string, mixed> $body
	 * @param array<string, mixed> $query
	 * @return Response
	 */
	protected function request(string $method, string $path, ?string $token, array $body = array(), array $query = array()): Response
	{
		return (new App($this->pdo, 2))->handle(new Request($method, $path, $query, $body, $token));
	}

	/**
	 * Registers a session and asserts that it worked.
	 * @param string $token
	 * @param string $name
	 * @param array<string, mixed> $extra Overrides the default fields.
	 * @return array<string, mixed> The session as the relay returns it.
	 */
	protected function register(string $token, string $name, array $extra = array()): array
	{
		$body = $extra + array('name' => $name, 'team' => 'private', 'machine' => 'pc', 'repo' => 'app', 'repo_base' => 'app', 'branch' => 'test');
		$response = $this->request('POST', '/session', $token, $body);
		self::assertSame(200, $response->status, (string) json_encode($response->data));

		return $response->data['session'];
	}
}
