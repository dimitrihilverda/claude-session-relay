<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Http;

/**
 * Een binnenkomend verzoek, los van de PHP-superglobals (zodat het toetsbaar is).
 * @author Dimitri Hilverda
 * @date 05-10-2026
 */
final class Request
{
	/**
	 * @param string $methode
	 * @param string $pad
	 * @param array<string, mixed> $query
	 * @param array<string, mixed>|null $body null = de body was geen geldige JSON.
	 * @param string|null $token Bearer-token.
	 */
	public function __construct(
		public readonly string $methode,
		public readonly string $pad,
		public readonly array $query = array(),
		public readonly ?array $body = array(),
		public readonly ?string $token = null,
	) {
	}

	/**
	 * @return Request
	 */
	public static function uitGlobals(): Request
	{
		//Path without query string:
		$pad = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

		//JSON body (empty = empty object):
		$ruw = (string) file_get_contents('php://input');
		$body = array();
		if(trim($ruw) !== '') {
			$body = json_decode($ruw, true);
			$body = is_array($body) === true ? $body : null;
		}

		//Bearer token (Apache passes it via CGIPassAuth):
		$kop = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
		$token = preg_match('/^Bearer\s+(\S+)$/i', $kop, $m) === 1 ? $m[1] : null;

		return new Request((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), is_string($pad) === true ? $pad : '/', $_GET, $body, $token);
	}
}
