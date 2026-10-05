<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/** Uses */

use InvalidArgumentException;
use Relay\Auth;

/**
 * Toetst personen, tokens en intrekken.
 * @author Alice Hilverda
 * @date 05-10-2026
 */
final class AuthTest extends DbTestCase
{
	/**
	 * @return void
	 */
	public function testTokenHoortBijPersoon(): void
	{
		$auth = new Auth($this->pdo);
		$alice = $auth->maakPersoon('Alice');

		self::assertSame(64, strlen($alice['token']));
		self::assertSame(array('id' => $alice['id'], 'naam' => 'Alice'), $auth->persoonVoorToken($alice['token']));
		self::assertNull($auth->persoonVoorToken('fout'));
		self::assertNull($auth->persoonVoorToken(null));
	}

	/**
	 * @return void
	 */
	public function testTokenWordtNietPlatOpgeslagen(): void
	{
		$token = (new Auth($this->pdo))->maakPersoon('Bob')['token'];
		$opgeslagen = $this->pdo->query("SELECT token_hash FROM persoon WHERE naam = 'Bob'")->fetchColumn();

		self::assertSame(hash('sha256', $token), $opgeslagen);
	}

	/**
	 * @return void
	 */
	public function testIntrekkenEnOpnieuwMaken(): void
	{
		$auth = new Auth($this->pdo);
		$oud = $auth->maakPersoon('Bob');

		self::assertTrue($auth->trekIn('Bob'));
		self::assertNull($auth->persoonVoorToken($oud['token']));

		$nieuw = $auth->maakPersoon('Bob');
		self::assertSame($oud['id'], $nieuw['id']);
		self::assertNull($auth->persoonVoorToken($oud['token']));
		self::assertNotNull($auth->persoonVoorToken($nieuw['token']));
	}

	/**
	 * @return void
	 */
	public function testOngeldigeNaam(): void
	{
		$this->expectException(InvalidArgumentException::class);
		(new Auth($this->pdo))->maakPersoon('1 x');
	}
}
