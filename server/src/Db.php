<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/** Uses */

use PDO;

/**
 * Creates the PDO connection to PostgreSQL.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class Db
{
	/**
	 * @param array{dsn:string,user:string,pass:string} $config
	 * @return PDO
	 */
	public static function connect(array $config): PDO
	{
		//Connect with exceptions and associative rows:
		$pdo = new PDO($config['dsn'], $config['user'], $config['pass'], array(
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		));
		$pdo->exec("SET TIME ZONE 'UTC'");

		return $pdo;
	}
}
