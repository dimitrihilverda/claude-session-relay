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
	 * A new token also ends every OAuth grant of the person (connectors must authorize again).
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
		$id = (int) $st->fetchColumn();
		$this->revokeOAuth($id);

		return array('id' => $id, 'name' => $name, 'token' => $token);
	}

	/**
	 * Revokes the person's relay token and every OAuth token of the person.
	 * @param string $name
	 * @return bool Whether the person existed.
	 */
	public function revoke(string $name): bool
	{
		$st = $this->pdo->prepare('UPDATE person SET active = false WHERE lower(name) = lower(?) RETURNING id');
		$st->execute(array($name));
		$ids = $st->fetchAll(PDO::FETCH_COLUMN);
		foreach($ids as $id) {
			$this->revokeOAuth((int) $id);
		}

		return $ids !== array();
	}

	/**
	 * @param int $personId
	 * @return void
	 */
	private function revokeOAuth(int $personId): void
	{
		$this->pdo->prepare('UPDATE oauth_token SET revoked_at = now() WHERE person_id = ? AND revoked_at IS NULL')->execute(array($personId));
		$this->pdo->prepare('DELETE FROM oauth_code WHERE person_id = ?')->execute(array($personId));
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
