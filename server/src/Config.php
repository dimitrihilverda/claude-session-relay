<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/**
 * Laadt de database-instellingen: omgevingsvariabelen gaan vóór config.php.
 * @author Dimitri Hilverda
 * @date 05-10-2026
 */
final class Config
{
	/**
	 * @param string $bestand Pad naar config.php (mag ontbreken).
	 * @return array{dsn:string,user:string,pass:string}
	 */
	public static function laad(string $bestand): array
	{
		//Read the optional config file:
		$config = is_file($bestand) === true ? require $bestand : array();

		return array(
			'dsn' => (string) (getenv('RELAY_DSN') ?: ($config['dsn'] ?? '')),
			'user' => (string) (getenv('RELAY_DB_USER') ?: ($config['user'] ?? '')),
			'pass' => (string) (getenv('RELAY_DB_PASS') ?: ($config['pass'] ?? '')),
		);
	}
}
