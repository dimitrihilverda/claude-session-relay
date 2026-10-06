<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/** Uses */

use PDO;

/**
 * Removes sessions without a heartbeat for 24 hours and messages older than 30 days.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class Cleaner
{
	/**
	 * @param PDO $pdo
	 */
	public function __construct(private PDO $pdo)
	{
	}

	/**
	 * @return array{sessions:int,messages:int}
	 */
	public function cleanUp(): array
	{
		$sessions = (int) $this->pdo->exec("DELETE FROM session WHERE last_seen < now() - interval '24 hours'");
		$messages = (int) $this->pdo->exec("DELETE FROM message WHERE created_at < now() - interval '30 days'");

		return array('sessions' => $sessions, 'messages' => $messages);
	}
}
