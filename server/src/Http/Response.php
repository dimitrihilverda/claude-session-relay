<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Http;

/**
 * JSON-antwoord.
 * @author Dimitri Hilverda
 * @date 05-10-2026
 */
final class Response
{
	/**
	 * @param int $status
	 * @param array<string, mixed> $data
	 * @param string|null $html An HTML page instead of JSON.
	 */
	public function __construct(public readonly int $status, public readonly array $data = array(), public readonly ?string $html = null)
	{
	}

	/**
	 * @return void
	 */
	public function stuur(): void
	{
		http_response_code($this->status);
		if($this->status === 204) {
			return;
		}
		if($this->html !== null) {
			header('Content-Type: text/html; charset=utf-8');
			header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'");
			echo $this->html;

			return;
		}
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}
}
