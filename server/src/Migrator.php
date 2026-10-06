<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/** Uses */

use PDO;

/**
 * Runs every migrations/*.sql file exactly once, in name order.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class Migrator
{
	/**
	 * @param PDO $pdo
	 * @param string $directory Directory with the .sql files.
	 */
	public function __construct(private PDO $pdo, private string $directory)
	{
	}

	/**
	 * @return list<string> Names of the files that ran now.
	 */
	public function migrate(): array
	{
		//An old Dutch relay keeps its bookkeeping in migratie(naam, uitgevoerd): rename it first:
		$this->pdo->exec(
			"DO \$\$ BEGIN
				IF to_regclass('migratie') IS NOT NULL AND to_regclass('migration') IS NULL THEN
					ALTER TABLE migratie RENAME TO migration;
					ALTER TABLE migration RENAME COLUMN naam TO name;
					ALTER TABLE migration RENAME COLUMN uitgevoerd TO executed_at;
					IF EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'migration'::regclass AND conname = 'migratie_pkey') THEN
						ALTER TABLE migration RENAME CONSTRAINT migratie_pkey TO migration_pkey;
					END IF;
					IF EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'migration'::regclass AND conname = 'migratie_naam_not_null') THEN
						ALTER TABLE migration RENAME CONSTRAINT migratie_naam_not_null TO migration_name_not_null;
					END IF;
					IF EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'migration'::regclass AND conname = 'migratie_uitgevoerd_not_null') THEN
						ALTER TABLE migration RENAME CONSTRAINT migratie_uitgevoerd_not_null TO migration_executed_at_not_null;
					END IF;
				END IF;
			END \$\$"
		);

		//Bookkeeping table:
		$this->pdo->exec('CREATE TABLE IF NOT EXISTS migration (name text PRIMARY KEY, executed_at timestamptz NOT NULL DEFAULT now())');
		$done = $this->pdo->query('SELECT name FROM migration')->fetchAll(PDO::FETCH_COLUMN);

		//Run each new file in its own transaction:
		$files = glob($this->directory . '/*.sql') ?: array();
		sort($files);
		$new = array();
		foreach($files as $file) {
			$name = basename($file);
			if(in_array($name, $done, true) === true) {
				continue;
			}
			$this->pdo->beginTransaction();
			$this->pdo->exec((string) file_get_contents($file));
			$this->pdo->prepare('INSERT INTO migration (name) VALUES (?)')->execute(array($name));
			$this->pdo->commit();
			$new[] = $name;
		}

		return $new;
	}
}
