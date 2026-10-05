<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/** Uses */

use InvalidArgumentException;
use PDO;

/**
 * Personen en hun tokens. Tokens staan alleen als SHA-256-hash in de database.
 * @author Dimitri Hilverda
 * @date 05-10-2026
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
	 * Maakt een persoon, of geeft een bestaande persoon een nieuw token (en zet hem weer actief).
	 * @param string $naam
	 * @return array{id:int,naam:string,token:string}
	 * @throws InvalidArgumentException
	 */
	public function maakPersoon(string $naam): array
	{
		//Validate input:
		$naam = trim($naam);
		if(preg_match('/^[A-Za-z][A-Za-z0-9_-]{1,39}$/', $naam) !== 1) {
			throw new InvalidArgumentException('Name must be 2-40 characters and start with a letter.');
		}

		//Upsert with a fresh token:
		$token = bin2hex(random_bytes(32));
		$st = $this->pdo->prepare(
			'INSERT INTO persoon (naam, token_hash) VALUES (:naam, :hash)
			 ON CONFLICT (naam) DO UPDATE SET token_hash = EXCLUDED.token_hash, actief = true
			 RETURNING id'
		);
		$st->execute(array('naam' => $naam, 'hash' => self::hash($token)));

		return array('id' => (int) $st->fetchColumn(), 'naam' => $naam, 'token' => $token);
	}

	/**
	 * @param string $naam
	 * @return bool Of de persoon bestond.
	 */
	public function trekIn(string $naam): bool
	{
		$st = $this->pdo->prepare('UPDATE persoon SET actief = false WHERE naam = ?');
		$st->execute(array($naam));

		return $st->rowCount() > 0;
	}

	/**
	 * @param string|null $token
	 * @return array{id:int,naam:string}|null
	 */
	public function persoonVoorToken(?string $token): ?array
	{
		if($token === null || $token === '') {
			return null;
		}
		$st = $this->pdo->prepare('SELECT id, naam FROM persoon WHERE token_hash = ? AND actief');
		$st->execute(array(self::hash($token)));
		$rij = $st->fetch();

		return $rij === false ? null : array('id' => (int) $rij['id'], 'naam' => (string) $rij['naam']);
	}
}
