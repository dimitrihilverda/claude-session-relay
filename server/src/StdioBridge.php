<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/** Uses */

use JsonException;

/**
 * MCP over stdio for clients that start a local process (Claude Desktop config, MCP directories, inspectors).
 *
 * initialize, ping and tools/list are answered locally from Mcp, so the tool list works without a relay.
 * tools/call is forwarded to <RELAY_URL>/mcp with the relay token as Bearer, so isolation stays on the server.
 * No database, no state: one JSON-RPC message per line in, one per line out.
 * @author Dimitri Hilverda
 * @date 07-10-2026
 */
final class StdioBridge
{
	/**
	 * @param string $url Base URL of the relay ('' = not configured).
	 * @param string $token Relay token ('' = not configured).
	 * @param int $timeout HTTP timeout in seconds (above the longest inbox wait).
	 */
	public function __construct(private string $url, private string $token, private int $timeout = 40)
	{
	}

	/**
	 * Reads messages until stdin closes.
	 * @param resource $in
	 * @param resource $out
	 * @return void
	 */
	public function run($in, $out): void
	{
		while(($line = fgets($in)) !== false) {
			if(trim($line) === '') {
				continue;
			}
			$reply = $this->handleLine($line);
			if($reply !== null) {
				fwrite($out, json_encode($reply, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
				fflush($out);
			}
		}
	}

	/**
	 * @param string $line One JSON-RPC message.
	 * @return array<string, mixed>|null The answer, or null for a notification.
	 */
	public function handleLine(string $line): ?array
	{
		try {
			$body = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
		} catch(JsonException) {
			return self::failure(null, -32700, 'Parse error.');
		}
		if(is_array($body) === false || array_is_list($body) === true) {
			return self::failure(null, -32600, 'Invalid request.');
		}
		$id = $body['id'] ?? null;
		if(($body['jsonrpc'] ?? null) !== '2.0' || is_string($body['method'] ?? null) === false
			|| (array_key_exists('id', $body) === true && is_int($id) === false && is_string($id) === false)) {
			return self::failure(is_int($id) === true || is_string($id) === true ? $id : null, -32600, 'Invalid request.');
		}

		//A notification (no id) gets no answer:
		if(array_key_exists('id', $body) === false) {
			return null;
		}
		$params = is_array($body['params'] ?? null) === true ? $body['params'] : array();

		return match($body['method']) {
			'initialize' => self::success($id, $this->initialize($params)),
			'ping' => self::success($id, new \stdClass()),
			'tools/list' => self::success($id, array('tools' => Mcp::tools())),
			'tools/call' => $this->forward($id, $body),
			default => self::failure($id, -32601, 'Method not found.'),
		};
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	private function initialize(array $params): array
	{
		$asked = $params['protocolVersion'] ?? '';
		$instructions = Mcp::INSTRUCTIONS;
		if($this->configured() === false) {
			$instructions .= "\nThis bridge is not configured yet: set RELAY_URL and RELAY_TOKEN, otherwise every tool call fails.";
		}

		return array(
			'protocolVersion' => in_array($asked, Mcp::PROTOCOL_VERSIONS, true) === true ? $asked : Mcp::PROTOCOL_VERSIONS[0],
			'capabilities' => array('tools' => array('listChanged' => false)),
			'serverInfo' => array('name' => 'session-relay', 'title' => 'Session relay', 'version' => '1.0.0'),
			'instructions' => $instructions,
		);
	}

	/**
	 * Sends a tools/call to the relay and passes its answer on (with our id).
	 * @param int|string $id
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function forward(int|string $id, array $body): array
	{
		if($this->configured() === false) {
			return self::toolError($id, 'The session relay is not configured: set RELAY_URL (e.g. https://relay.example.com) and RELAY_TOKEN (from `relay person:create`).');
		}
		//The token only travels over https; plain http only to this machine (testing):
		if(preg_match('#^(https://[^/\s]+|http://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?(/|\z))#', $this->url) !== 1) {
			return self::toolError($id, 'RELAY_URL must start with https:// (plain http:// only for localhost), so the token is never sent unencrypted.');
		}

		$context = stream_context_create(array('http' => array(
			'method' => 'POST',
			'header' => "Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: Bearer {$this->token}\r\n",
			'content' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
			'timeout' => $this->timeout,
			'ignore_errors' => true,
		)));
		$raw = @file_get_contents(rtrim($this->url, '/') . '/mcp', false, $context);
		$status = self::status($http_response_header ?? array());
		if($raw === false || $status === 0) {
			return self::toolError($id, 'The session relay is unreachable. Carry on with the work and tell the user.');
		}
		if($status === 401) {
			return self::toolError($id, 'The relay rejected the token (invalid or revoked). Ask the user for a new RELAY_TOKEN.');
		}

		$reply = json_decode($raw, true);
		if(is_array($reply) === false || (isset($reply['result']) === false && isset($reply['error']) === false)) {
			return self::toolError($id, "The session relay answered with HTTP $status.");
		}
		$reply['id'] = $id;

		return $reply;
	}

	/**
	 * @return bool
	 */
	private function configured(): bool
	{
		return $this->url !== '' && $this->token !== '';
	}

	/**
	 * @param list<string> $headers $http_response_header
	 * @return int The last status code (after redirects), 0 = none.
	 */
	private static function status(array $headers): int
	{
		$status = 0;
		foreach($headers as $header) {
			if(preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
				$status = (int) $m[1];
			}
		}

		return $status;
	}

	/**
	 * @param int|string $id
	 * @param string $text
	 * @return array<string, mixed>
	 */
	private static function toolError(int|string $id, string $text): array
	{
		return self::success($id, array('content' => array(array('type' => 'text', 'text' => $text)), 'isError' => true));
	}

	/**
	 * @param int|string $id
	 * @param mixed $result
	 * @return array<string, mixed>
	 */
	private static function success(int|string $id, mixed $result): array
	{
		return array('jsonrpc' => '2.0', 'id' => $id, 'result' => $result);
	}

	/**
	 * @param int|string|null $id
	 * @param int $code
	 * @param string $message
	 * @return array<string, mixed>
	 */
	private static function failure(int|string|null $id, int $code, string $message): array
	{
		return array('jsonrpc' => '2.0', 'id' => $id, 'error' => array('code' => $code, 'message' => $message));
	}
}
