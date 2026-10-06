<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/**
 * Loads the database settings: environment variables win over config.php.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class Config
{
	/**
	 * @param string $file Path to config.php (may be missing).
	 * @return array{dsn:string,user:string,pass:string}
	 */
	public static function load(string $file): array
	{
		//Read the optional config file:
		$config = is_file($file) === true ? require $file : array();

		return array(
			'dsn' => (string) (getenv('RELAY_DSN') ?: ($config['dsn'] ?? '')),
			'user' => (string) (getenv('RELAY_DB_USER') ?: ($config['user'] ?? '')),
			'pass' => (string) (getenv('RELAY_DB_PASS') ?: ($config['pass'] ?? '')),
		);
	}
}
