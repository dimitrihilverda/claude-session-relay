<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/** Uses */

use Relay\Cleaner;

/**
 * Tests the cleanup of old sessions and messages.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class CleanerTest extends DbTestCase
{
	/**
	 * @return void
	 */
	public function testRemovesOnlyOldRows(): void
	{
		$alice = $this->person('Alice')['token'];
		$this->register($alice, 'alice-old');
		$this->register($alice, 'alice-new');
		$this->request('POST', '/message', $alice, array('from' => 'alice-new', 'to' => 'alice-old', 'kind' => 'note', 'text' => 'x'));
		$this->request('POST', '/message', $alice, array('from' => 'alice-new', 'to' => 'alice-old', 'kind' => 'note', 'text' => 'y'));
		$this->pdo->exec("UPDATE session SET last_seen = now() - interval '25 hours' WHERE name = 'alice-old'");
		$this->pdo->exec("UPDATE message SET created_at = now() - interval '15 days' WHERE text = 'x'");
		$this->pdo->exec("UPDATE message SET created_at = now() - interval '13 days' WHERE text = 'y'");

		self::assertSame(array('sessions' => 1, 'messages' => 1, 'oauth' => 0), (new Cleaner($this->pdo))->cleanUp());
		self::assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM session')->fetchColumn());
		self::assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM message')->fetchColumn());
	}
}
