<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/**
 * Loads the settings: environment variables win over config.php.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class Config
{
	/**
	 * @param string $file Path to config.php (may be missing).
	 * @return array{dsn:string,user:string,pass:string,public_url:string,oauth_extra_redirects:list<string>}
	 */
	public static function load(string $file): array
	{
		//Read the optional config file:
		$config = is_file($file) === true ? require $file : array();

		//Extra OAuth redirects: comma-separated string (env) or list (config.php); only exact absolute https URIs survive:
		$extra = getenv('RELAY_OAUTH_EXTRA_REDIRECTS') ?: ($config['oauth_extra_redirects'] ?? '');
		$extra = is_array($extra) === true ? $extra : explode(',', (string) $extra);
		$redirects = array();
		foreach($extra as $uri) {
			$uri = is_string($uri) === true ? trim($uri) : '';
			if($uri === '') {
				continue;
			}
			if(OAuthStore::validExtraRedirect($uri) === false) {
				error_log('session-relay: ignoring invalid oauth_extra_redirects entry (needs an absolute https:// URI without query or fragment)');
				continue;
			}
			$redirects[] = $uri;
		}

		return array(
			'dsn' => (string) (getenv('RELAY_DSN') ?: ($config['dsn'] ?? '')),
			'user' => (string) (getenv('RELAY_DB_USER') ?: ($config['user'] ?? '')),
			'pass' => (string) (getenv('RELAY_DB_PASS') ?: ($config['pass'] ?? '')),
			'public_url' => (string) (getenv('RELAY_PUBLIC_URL') ?: ($config['public_url'] ?? '')),
			'oauth_extra_redirects' => array_values(array_unique($redirects)),
		);
	}
}
