<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/** Uses */

use PDO;

/**
 * Removes sessions without a heartbeat for 24 hours, messages older than 14 days and expired OAuth rows.
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
	 * @return array{sessions:int,messages:int,oauth:int}
	 */
	public function cleanUp(): array
	{
		$sessions = (int) $this->pdo->exec("DELETE FROM session WHERE last_seen < now() - interval '24 hours'");
		$messages = (int) $this->pdo->exec("DELETE FROM message WHERE created_at < now() - interval '14 days'");

		//OAuth: expired codes, token families past their refresh lifetime, and clients nobody uses:
		$oauth = (int) $this->pdo->exec("DELETE FROM oauth_code WHERE expires_at < now() - interval '1 hour'");
		$oauth += (int) $this->pdo->exec('DELETE FROM oauth_token WHERE refresh_expires_at < now()');
		$oauth += (int) $this->pdo->exec(
			"DELETE FROM oauth_client c WHERE c.created_at < now() - interval '1 day'
				AND NOT EXISTS (SELECT 1 FROM oauth_token t WHERE t.client_id = c.id)
				AND NOT EXISTS (SELECT 1 FROM oauth_code k WHERE k.client_id = c.id)"
		);

		return array('sessions' => $sessions, 'messages' => $messages, 'oauth' => $oauth);
	}
}
