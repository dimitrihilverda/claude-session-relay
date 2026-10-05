<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/** Uses */

use PDO;

/**
 * Voert elk migrations/*.sql-bestand precies één keer uit, op naamvolgorde.
 * @author Dimitri Hilverda
 * @date 05-10-2026
 */
final class Migrator
{
	/**
	 * @param PDO $pdo
	 * @param string $map Map met .sql-bestanden.
	 */
	public function __construct(private PDO $pdo, private string $map)
	{
	}

	/**
	 * @return list<string> Namen van de nu uitgevoerde bestanden.
	 */
	public function migreer(): array
	{
		//Bookkeeping table:
		$this->pdo->exec('CREATE TABLE IF NOT EXISTS migratie (naam text PRIMARY KEY, uitgevoerd timestamptz NOT NULL DEFAULT now())');
		$gedaan = $this->pdo->query('SELECT naam FROM migratie')->fetchAll(PDO::FETCH_COLUMN);

		//Run each new file in its own transaction:
		$bestanden = glob($this->map . '/*.sql') ?: array();
		sort($bestanden);
		$nieuw = array();
		foreach($bestanden as $bestand) {
			$naam = basename($bestand);
			if(in_array($naam, $gedaan, true) === true) {
				continue;
			}
			$this->pdo->beginTransaction();
			$this->pdo->exec((string) file_get_contents($bestand));
			$this->pdo->prepare('INSERT INTO migratie (naam) VALUES (?)')->execute(array($naam));
			$this->pdo->commit();
			$nieuw[] = $naam;
		}

		return $nieuw;
	}
}
