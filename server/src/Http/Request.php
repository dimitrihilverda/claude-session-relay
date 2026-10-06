<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Http;

/**
 * An incoming request, separate from the PHP superglobals (so it can be tested).
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class Request
{
	/**
	 * @param string $method
	 * @param string $path
	 * @param array<string, mixed> $query
	 * @param array<string, mixed>|null $body null = the body was not valid JSON.
	 * @param string|null $token Bearer token.
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $path,
		public readonly array $query = array(),
		public readonly ?array $body = array(),
		public readonly ?string $token = null,
	) {
	}

	/**
	 * @return Request
	 */
	public static function fromGlobals(): Request
	{
		//Path without query string:
		$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

		//JSON body (empty = empty object):
		$raw = (string) file_get_contents('php://input');
		$body = array();
		if(trim($raw) !== '') {
			$body = json_decode($raw, true);
			$body = is_array($body) === true ? $body : null;
		}

		//Bearer token (Apache passes it on via SetEnvIf):
		$header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
		$token = preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1 ? $m[1] : null;

		return new Request((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), is_string($path) === true ? $path : '/', $_GET, $body, $token);
	}
}
