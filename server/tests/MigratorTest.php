<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/** Uses */

use Relay\Migrator;

/**
 * Toetst dat migraties precies één keer draaien.
 * @author Alice Hilverda
 * @date 05-10-2026
 */
final class MigratorTest extends DbTestCase
{
	/**
	 * @return void
	 */
	public function testMigreertEenKeer(): void
	{
		$this->pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public');
		$migrator = new Migrator($this->pdo, __DIR__ . '/../migrations');

		self::assertSame(array('001_schema.sql'), $migrator->migreer());
		self::assertSame(array(), $migrator->migreer());

		$tabellen = $this->pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY 1")->fetchAll(\PDO::FETCH_COLUMN);
		self::assertSame(array('bericht', 'bericht_gelezen', 'migratie', 'persoon', 'sessie'), $tabellen);
	}
}
