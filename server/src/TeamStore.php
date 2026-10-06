<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/** Uses */

use InvalidArgumentException;
use PDO;
use PDOException;

/**
 * Teams and their members. Administered from the server CLI only, never via the API.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class TeamStore
{
	/**
	 * @param PDO $pdo
	 */
	public function __construct(private PDO $pdo)
	{
	}

	/**
	 * @param string $name
	 * @return void
	 * @throws InvalidArgumentException
	 */
	public function create(string $name): void
	{
		$name = self::validName($name);
		if($this->teamId($name) !== null) {
			throw new InvalidArgumentException("Team $name already exists.");
		}
		$this->pdo->prepare('INSERT INTO team (name) VALUES (?)')->execute(array($name));
	}

	/**
	 * Renames a team; its sessions and messages move along.
	 * @param string $old
	 * @param string $new
	 * @return void
	 * @throws InvalidArgumentException
	 */
	public function rename(string $old, string $new): void
	{
		$new = self::validName($new);
		$id = $this->requireTeam($old);
		if($this->teamId($new) !== null) {
			throw new InvalidArgumentException("Team $new already exists.");
		}

		//One transaction, so no session is ever in a team that does not exist:
		$this->pdo->beginTransaction();
		try {
			$this->pdo->prepare('UPDATE team SET name = ? WHERE id = ?')->execute(array($new, $id));
			$this->pdo->prepare('UPDATE session SET team = ? WHERE team = ?')->execute(array($new, $old));
			$this->pdo->prepare('UPDATE message SET team = ? WHERE team = ?')->execute(array($new, $old));
			$this->pdo->commit();
		} catch(PDOException $e) {
			$this->pdo->rollBack();
			throw $e;
		}
	}

	/**
	 * @param string $team
	 * @param string $person
	 * @return void
	 * @throws InvalidArgumentException
	 */
	public function add(string $team, string $person): void
	{
		$this->pdo->prepare('INSERT INTO team_member (team_id, person_id) VALUES (?, ?) ON CONFLICT DO NOTHING')
			->execute(array($this->requireTeam($team), $this->requirePerson($person)));
	}

	/**
	 * Removes a member; that person's sessions in the team are removed too, so they lose access at once.
	 * @param string $team
	 * @param string $person
	 * @return bool Whether the person was a member.
	 * @throws InvalidArgumentException
	 */
	public function remove(string $team, string $person): bool
	{
		$teamId = $this->requireTeam($team);
		$personId = $this->requirePerson($person);

		$this->pdo->beginTransaction();
		try {
			$st = $this->pdo->prepare('DELETE FROM team_member WHERE team_id = ? AND person_id = ?');
			$st->execute(array($teamId, $personId));
			$this->pdo->prepare('DELETE FROM session WHERE team = (SELECT name FROM team WHERE id = ?) AND person_id = ?')->execute(array($teamId, $personId));
			$this->pdo->commit();
		} catch(PDOException $e) {
			$this->pdo->rollBack();
			throw $e;
		}

		return $st->rowCount() > 0;
	}

	/**
	 * @return list<array{name:string,members:list<string>}>
	 */
	public function list(): array
	{
		$rows = $this->pdo->query(
			"SELECT t.name, coalesce(string_agg(p.name, ',' ORDER BY lower(p.name)), '') AS members
			 FROM team t LEFT JOIN team_member m ON m.team_id = t.id LEFT JOIN person p ON p.id = m.person_id
			 GROUP BY t.name ORDER BY t.name"
		)->fetchAll();

		return array_map(static fn(array $row): array => array(
			'name' => (string) $row['name'],
			'members' => $row['members'] === '' ? array() : explode(',', (string) $row['members']),
		), $rows);
	}

	/**
	 * @param int $personId
	 * @return list<string> Names of the teams the person is a member of.
	 */
	public function teamsOf(int $personId): array
	{
		$st = $this->pdo->prepare('SELECT t.name FROM team t JOIN team_member m ON m.team_id = t.id WHERE m.person_id = ? ORDER BY t.name');
		$st->execute(array($personId));

		return $st->fetchAll(PDO::FETCH_COLUMN);
	}

	/**
	 * @param string $name
	 * @return string
	 * @throws InvalidArgumentException
	 */
	private static function validName(string $name): string
	{
		$name = trim($name);
		if($name === 'private') {
			throw new InvalidArgumentException('A team cannot be called private; that name is reserved for private sessions.');
		}
		if(preg_match('/^[a-z0-9][a-z0-9._-]{0,39}$/', $name) !== 1) {
			throw new InvalidArgumentException('Team name must be 1-40 characters: lower-case letters, digits, dots, dashes and underscores.');
		}

		return $name;
	}

	/**
	 * @param string $name
	 * @return int|null
	 */
	private function teamId(string $name): ?int
	{
		$st = $this->pdo->prepare('SELECT id FROM team WHERE name = ?');
		$st->execute(array($name));
		$id = $st->fetchColumn();

		return $id === false ? null : (int) $id;
	}

	/**
	 * @param string $name
	 * @return int
	 * @throws InvalidArgumentException
	 */
	private function requireTeam(string $name): int
	{
		$id = $this->teamId(trim($name));
		if($id === null) {
			throw new InvalidArgumentException("Unknown team $name.");
		}

		return $id;
	}

	/**
	 * @param string $name
	 * @return int
	 * @throws InvalidArgumentException
	 */
	private function requirePerson(string $name): int
	{
		$st = $this->pdo->prepare('SELECT id FROM person WHERE lower(name) = lower(?)');
		$st->execute(array(trim($name)));
		$id = $st->fetchColumn();
		if($id === false) {
			throw new InvalidArgumentException("Unknown person $name.");
		}

		return (int) $id;
	}
}
