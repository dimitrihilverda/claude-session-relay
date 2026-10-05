<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/**
 * Rooktoets: de toetsdatabase is bereikbaar.
 * @author Alice Hilverda
 * @date 05-10-2026
 */
final class VerbindingTest extends DbTestCase
{
	/**
	 * @return void
	 */
	public function testDatabaseAntwoordt(): void
	{
		self::assertSame(1, (int) $this->pdo->query('SELECT 1')->fetchColumn());
	}
}
