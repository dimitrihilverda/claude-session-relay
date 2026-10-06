<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/** Uses */

use InvalidArgumentException;
use PDO;

/**
 * Persons and their tokens. Tokens are only stored as a SHA-256 hash.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class Auth
{
	/**
	 * @param PDO $pdo
	 */
	public function __construct(private PDO $pdo)
	{
	}

	/**
	 * @param string $token
	 * @return string
	 */
	public static function hash(string $token): string
	{
		return hash('sha256', $token);
	}

	/**
	 * Creates a person, or gives an existing person a new token (and makes them active again).
	 * @param string $name
	 * @return array{id:int,name:string,token:string}
	 * @throws InvalidArgumentException
	 */
	public function createPerson(string $name): array
	{
		//Validate input:
		$name = trim($name);
		if(preg_match('/^[A-Za-z][A-Za-z0-9_-]{1,39}$/', $name) !== 1) {
			throw new InvalidArgumentException('Name must be 2-40 characters and start with a letter.');
		}

		//Session names start with "<person>-", so no two persons may share that name space:
		$st = $this->pdo->prepare(
			"SELECT name FROM person WHERE name <> :name AND (lower(name) = lower(:same)
				OR starts_with(lower(name), lower(:prefix) || '-') OR starts_with(lower(:longer), lower(name) || '-'))"
		);
		$st->execute(array('name' => $name, 'same' => $name, 'prefix' => $name, 'longer' => $name));
		$clash = $st->fetchColumn();
		if($clash !== false) {
			throw new InvalidArgumentException("Name $name clashes with existing person $clash (session names start with the person name).");
		}

		//Upsert with a fresh token:
		$token = bin2hex(random_bytes(32));
		$st = $this->pdo->prepare(
			'INSERT INTO person (name, token_hash) VALUES (:name, :hash)
			 ON CONFLICT (name) DO UPDATE SET token_hash = EXCLUDED.token_hash, active = true
			 RETURNING id'
		);
		$st->execute(array('name' => $name, 'hash' => self::hash($token)));

		return array('id' => (int) $st->fetchColumn(), 'name' => $name, 'token' => $token);
	}

	/**
	 * @param string $name
	 * @return bool Whether the person existed.
	 */
	public function revoke(string $name): bool
	{
		$st = $this->pdo->prepare('UPDATE person SET active = false WHERE lower(name) = lower(?)');
		$st->execute(array($name));

		return $st->rowCount() > 0;
	}

	/**
	 * @param string|null $token
	 * @return array{id:int,name:string}|null
	 */
	public function personForToken(?string $token): ?array
	{
		if($token === null || $token === '') {
			return null;
		}
		$st = $this->pdo->prepare('SELECT id, name FROM person WHERE token_hash = ? AND active');
		$st->execute(array(self::hash($token)));
		$row = $st->fetch();

		return $row === false ? null : array('id' => (int) $row['id'], 'name' => (string) $row['name']);
	}
}
