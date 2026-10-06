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
	const int MAX_BODY = 1048576;

	/**
	 * @param string $method
	 * @param string $path
	 * @param array<string, mixed> $query
	 * @param array<string, mixed>|null $body null = the body was not valid JSON.
	 * @param string|null $token Bearer token.
	 * @param array<string, mixed> $form Fields of a form post (application/x-www-form-urlencoded).
	 * @param string $ip Address of the caller (REMOTE_ADDR).
	 * @param string $origin Origin header ('' = none).
	 * @param bool $tooLarge The body was larger than MAX_BODY and was not read.
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $path,
		public readonly array $query = array(),
		public readonly ?array $body = array(),
		public readonly ?string $token = null,
		public readonly array $form = array(),
		public readonly string $ip = '',
		public readonly string $origin = '',
		public readonly bool $tooLarge = false,
	) {
	}

	/**
	 * @return Request
	 */
	public static function fromGlobals(): Request
	{
		//Path without query string:
		$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

		//Never read more than MAX_BODY bytes:
		$raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
		$tooLarge = strlen($raw) > self::MAX_BODY || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY;

		//JSON body (empty = empty object); a form post fills $_POST instead:
		$body = array();
		$form = array();
		if($tooLarge === false && trim($raw) !== '') {
			$body = json_decode($raw, true);
			$body = is_array($body) === true ? $body : null;
			if($body === null && $_POST !== array()) {
				$form = $_POST;
			}
		}

		//Bearer token (Apache passes it on via SetEnvIf or CGIPassAuth):
		$header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
		$token = preg_match('/^Bearer\s+(\S+)\z/i', $header, $m) === 1 ? $m[1] : null;

		return new Request(
			(string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
			is_string($path) === true ? $path : '/',
			$_GET,
			$body,
			$token,
			$form,
			(string) ($_SERVER['REMOTE_ADDR'] ?? ''),
			(string) ($_SERVER['HTTP_ORIGIN'] ?? ''),
			$tooLarge,
		);
	}
}
