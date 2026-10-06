<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Http;

/**
 * JSON (or HTML) response.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class Response
{
	/**
	 * @param int $status
	 * @param array<string, mixed> $data
	 * @param string|null $html An HTML page instead of JSON.
	 * @param array<string, string> $headers Extra headers.
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $data = array(),
		public readonly ?string $html = null,
		public readonly array $headers = array(),
	) {
	}

	/**
	 * Every HTML page: no framing, no external resources.
	 * @param int $status
	 * @param string $html
	 * @return Response
	 */
	public static function page(int $status, string $html): Response
	{
		return new Response($status, array(), $html, array(
			'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; connect-src 'self'; img-src data:; base-uri 'none'; frame-ancestors 'none'",
			'X-Frame-Options' => 'DENY',
			'X-Content-Type-Options' => 'nosniff',
			'Referrer-Policy' => 'no-referrer',
			'Cache-Control' => 'no-store',
		));
	}

	/**
	 * @param string $location
	 * @return Response
	 */
	public static function redirect(string $location): Response
	{
		return new Response(302, array(), null, array('Location' => $location, 'Cache-Control' => 'no-store'));
	}

	/**
	 * @return void
	 */
	public function send(): void
	{
		http_response_code($this->status);
		foreach($this->headers as $name => $value) {
			header("$name: $value");
		}
		if($this->html !== null) {
			header('Content-Type: text/html; charset=utf-8');
			echo $this->html;

			return;
		}
		if($this->data === array() && in_array($this->status, array(202, 204, 302, 405), true) === true) {
			return;
		}
		header('Content-Type: application/json; charset=utf-8');
		if(isset($this->headers['Cache-Control']) === false) {
			header('Cache-Control: no-store');
		}
		echo json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}
}
