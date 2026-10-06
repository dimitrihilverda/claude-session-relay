<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/** Uses */

use InvalidArgumentException;
use Relay\Auth;

/**
 * Tests persons, tokens and revoking.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class AuthTest extends DbTestCase
{
	/**
	 * @return void
	 */
	public function testTokenBelongsToPerson(): void
	{
		$auth = new Auth($this->pdo);
		$alice = $auth->createPerson('Alice');

		self::assertSame(64, strlen($alice['token']));
		self::assertSame(array('id' => $alice['id'], 'name' => 'Alice'), $auth->personForToken($alice['token']));
		self::assertNull($auth->personForToken('wrong'));
		self::assertNull($auth->personForToken(null));
	}

	/**
	 * @return void
	 */
	public function testTokenIsNotStoredInPlainText(): void
	{
		$token = (new Auth($this->pdo))->createPerson('Bob')['token'];
		$stored = $this->pdo->query("SELECT token_hash FROM person WHERE name = 'Bob'")->fetchColumn();

		self::assertSame(hash('sha256', $token), $stored);
	}

	/**
	 * @return void
	 */
	public function testRevokeAndRecreate(): void
	{
		$auth = new Auth($this->pdo);
		$old = $auth->createPerson('Bob');

		self::assertTrue($auth->revoke('Bob'));
		self::assertFalse($auth->revoke('Nobody'));
		self::assertNull($auth->personForToken($old['token']));

		$new = $auth->createPerson('Bob');
		self::assertSame($old['id'], $new['id']);
		self::assertNull($auth->personForToken($old['token']));
		self::assertNotNull($auth->personForToken($new['token']));
	}

	/**
	 * @return void
	 */
	public function testInvalidName(): void
	{
		$this->expectException(InvalidArgumentException::class);
		(new Auth($this->pdo))->createPerson('1 x');
	}

	/**
	 * Session names start with "<person>-", so "dim" and "dim-x" would share session names.
	 * @return void
	 */
	public function testNameMayNotPrefixAnotherPerson(): void
	{
		$auth = new Auth($this->pdo);
		$auth->createPerson('dim');
		try {
			$auth->createPerson('Dim-x');
			self::fail('Expected a conflict for Dim-x.');
		} catch(InvalidArgumentException $e) {
			self::assertStringContainsString('dim', $e->getMessage());
		}

		$auth->createPerson('ab-c');
		$this->expectException(InvalidArgumentException::class);
		$auth->createPerson('AB');
	}
}
