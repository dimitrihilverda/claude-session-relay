<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/** Uses */

use InvalidArgumentException;
use PDO;

/**
 * Service tokens: a trusted service that may list the teams in its scope and their members.
 * Tokens are only stored as a SHA-256 hash. Administered from the server CLI only.
 * @author Dimitri Hilverda
 * @date 10-11-2026
 */
final class ServiceTokenStore
{
	/**
	 * @param PDO $pdo
	 */
	public function __construct(private PDO $pdo)
	{
	}

	/**
	 * Creates a service token, or gives an existing service a new token, scope and makes it active again.
	 * @param string $name
	 * @param list<string> $teams Teams the token may list; they do not have to exist yet.
	 * @return string The token (shown once).
	 * @throws InvalidArgumentException
	 */
	public function create(string $name, array $teams): string
	{
		//Validate input:
		$name = trim($name);
		if(preg_match('/^[A-Za-z][A-Za-z0-9_-]{1,39}$/', $name) !== 1) {
			throw new InvalidArgumentException('Name must be 2-40 characters and start with a letter.');
		}
		$teams = array_values(array_unique(array_map('trim', $teams)));
		if($teams === array()) {
			throw new InvalidArgumentException('Give at least one team.');
		}
		foreach($teams as $team) {
			if(preg_match('/^[a-z0-9][a-z0-9._-]{0,39}$/', $team) !== 1) {
				throw new InvalidArgumentException("Invalid team name $team.");
			}
		}

		//Upsert with a fresh token (team names contain no commas, so a comma list is safe):
		$token = bin2hex(random_bytes(32));
		$st = $this->pdo->prepare(
			'INSERT INTO service_token (name, token_hash, teams) VALUES (:name, :hash, string_to_array(:teams, \',\'))
			 ON CONFLICT (name) DO UPDATE SET token_hash = EXCLUDED.token_hash, teams = EXCLUDED.teams, revoked_at = NULL, created_at = now()'
		);
		$st->execute(array('name' => $name, 'hash' => Auth::hash($token), 'teams' => implode(',', $teams)));

		return $token;
	}

	/**
	 * @param string $name
	 * @return bool Whether the service existed.
	 */
	public function revoke(string $name): bool
	{
		$st = $this->pdo->prepare('UPDATE service_token SET revoked_at = now() WHERE name = ?');
		$st->execute(array(trim($name)));

		return $st->rowCount() > 0;
	}

	/**
	 * @param string|null $token
	 * @return list<string>|null The team scope of the token; null = unknown or revoked token.
	 */
	public function teamsFor(?string $token): ?array
	{
		if($token === null || $token === '') {
			return null;
		}
		$st = $this->pdo->prepare('SELECT array_to_json(teams) FROM service_token WHERE token_hash = ? AND revoked_at IS NULL');
		$st->execute(array(Auth::hash($token)));
		$json = $st->fetchColumn();

		return $json === false ? null : array_values(json_decode((string) $json, true));
	}

	/**
	 * @param list<string> $teams
	 * @return list<array{name:string,members:list<string>}> The given teams that exist, with their members.
	 */
	public function members(array $teams): array
	{
		$st = $this->pdo->prepare(
			"SELECT t.name, coalesce(string_agg(p.name, ',' ORDER BY lower(p.name)), '') AS members
			 FROM team t LEFT JOIN team_member m ON m.team_id = t.id LEFT JOIN person p ON p.id = m.person_id
			 WHERE t.name = ANY(string_to_array(?, ','))
			 GROUP BY t.name ORDER BY t.name"
		);
		$st->execute(array(implode(',', $teams)));

		return array_map(static fn(array $row): array => array(
			'name' => (string) $row['name'],
			'members' => $row['members'] === '' ? array() : explode(',', (string) $row['members']),
		), $st->fetchAll());
	}
}
