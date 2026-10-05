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

/**
 * Basis voor toetsen die een echte PostgreSQL nodig hebben.
 * @author Alice Hilverda
 * @date 05-10-2026
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
		$this->pdo = Db::verbind(Config::laad('/nonexistent'));
		$this->pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public');
		(new Migrator($this->pdo, __DIR__ . '/../migrations'))->migreer();
	}

	/**
	 * @param string $naam
	 * @return array{id:int,naam:string,token:string}
	 */
	protected function persoon(string $naam): array
	{
		return (new Auth($this->pdo))->maakPersoon($naam);
	}

	/**
	 * @param string $methode
	 * @param string $pad
	 * @param string|null $token
	 * @param array<string, mixed> $body
	 * @param array<string, mixed> $query
	 * @return Response
	 */
	protected function verzoek(string $methode, string $pad, ?string $token, array $body = array(), array $query = array()): Response
	{
		return (new App($this->pdo, 2))->handle(new Request($methode, $pad, $query, $body, $token));
	}

	/**
	 * @param string $token
	 * @param string $naam
	 * @param array<string, mixed> $extra Overschrijft de standaardvelden.
	 * @return array<string, mixed> De sessie zoals de relay hem teruggeeft.
	 */
	protected function meldAan(string $token, string $naam, array $extra = array()): array
	{
		$body = $extra + array('naam' => $naam, 'machine' => 'pc', 'repo' => 'app', 'repo_basis' => 'app', 'branch' => 'test');
		$antwoord = $this->verzoek('POST', '/sessie', $token, $body);
		self::assertSame(200, $antwoord->status, (string) json_encode($antwoord->data));

		return $antwoord->data['sessie'];
	}
}
