<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/** Uses */

use Relay\App;
use Relay\Http\Request;
use Relay\Http\Response;

/**
 * Tests the MCP endpoint (JSON-RPC over Streamable HTTP) and its tools.
 * @author d.hilverda <dimitri.hilverda@moving-in.nl>
 * @date 06-10-2026
 */
final class McpTest extends DbTestCase
{
	const string METADATA = 'Bearer resource_metadata="https://relay.test/.well-known/oauth-protected-resource"';

	private string $alice;

	private string $bob;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		$this->alice = $this->person('Alice')['token'];
		$this->bob = $this->person('Bob')['token'];
		$this->team('app', 'Alice', 'Bob');
	}

	/**
	 * @param string|null $token
	 * @param array<mixed>|null $body
	 * @param string $method
	 * @param string $origin Origin header ('' = none).
	 * @return Response
	 */
	private function mcp(?string $token, ?array $body, string $method = 'POST', string $origin = ''): Response
	{
		return (new App($this->pdo, 2, 'https://relay.test'))->handle(new Request($method, '/mcp', array(), $body, $token, array(), '', $origin));
	}

	/**
	 * @param string $token
	 * @param string $method
	 * @param array<string, mixed> $params
	 * @return array<string, mixed> The JSON-RPC response.
	 */
	private function rpc(string $token, string $method, array $params = array()): array
	{
		$response = $this->mcp($token, array('jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => $params));
		self::assertSame(200, $response->status, (string) json_encode($response->data));
		self::assertSame('2.0', $response->data['jsonrpc']);
		self::assertSame(7, $response->data['id']);

		return $response->data;
	}

	/**
	 * @param string $token
	 * @param string $name
	 * @param array<string, mixed> $arguments
	 * @return array{error:bool,data:mixed,text:string}
	 */
	private function tool(string $token, string $name, array $arguments = array()): array
	{
		$result = $this->rpc($token, 'tools/call', array('name' => $name, 'arguments' => $arguments))['result'];
		self::assertSame('text', $result['content'][0]['type']);

		return array('error' => $result['isError'], 'data' => $result['structuredContent'] ?? null, 'text' => $result['content'][0]['text']);
	}

	/**
	 * @return void
	 */
	public function testWithoutTokenIs401WithResourceMetadata(): void
	{
		foreach(array(null, 'wrong') as $token) {
			$response = $this->mcp($token, array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array()));
			self::assertSame(401, $response->status);
			self::assertStringStartsWith(self::METADATA, $response->headers['WWW-Authenticate']);
		}
	}

	/**
	 * @return void
	 */
	public function testForeignOriginIs403(): void
	{
		$ping = array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping');
		self::assertSame(200, $this->mcp($this->alice, $ping, 'POST', 'https://relay.test')->status);
		$foreign = $this->mcp($this->alice, $ping, 'POST', 'https://evil.example');
		self::assertSame(403, $foreign->status);
		self::assertSame(array('error' => 'Origin not allowed.'), $foreign->data);
		self::assertSame(403, $this->mcp($this->alice, $ping, 'POST', 'https://relay.test.evil.example')->status);
		self::assertSame(403, $this->mcp($this->alice, $ping, 'POST', 'null')->status);
	}

	/**
	 * @return void
	 */
	public function testUnexpectedFailureIsJsonRpcInternalError(): void
	{
		$this->pdo->exec('DROP TABLE team_member CASCADE');
		$log = ini_set('error_log', '/dev/null');
		$response = $this->mcp($this->alice, array('jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => array('name' => 'whoami', 'arguments' => array())));
		ini_set('error_log', (string) $log);
		self::assertSame(200, $response->status);
		self::assertSame(array('jsonrpc' => '2.0', 'id' => 3, 'error' => array('code' => -32603, 'message' => 'Internal error.')), $response->data);
	}

	/**
	 * @return void
	 */
	public function testGetAndDeleteAre405(): void
	{
		self::assertSame(405, $this->mcp($this->alice, array(), 'GET')->status);
		self::assertSame('POST', $this->mcp($this->alice, array(), 'GET')->headers['Allow']);
		self::assertSame(405, $this->mcp($this->alice, array(), 'DELETE')->status);
	}

	/**
	 * @return void
	 */
	public function testInitialize(): void
	{
		$result = $this->rpc($this->alice, 'initialize', array('protocolVersion' => '2025-06-18', 'capabilities' => array(), 'clientInfo' => array('name' => 'test', 'version' => '1')))['result'];
		self::assertSame('2025-06-18', $result['protocolVersion']);
		self::assertSame(array('tools' => array('listChanged' => false)), $result['capabilities']);
		self::assertSame('session-relay', $result['serverInfo']['name']);
		self::assertStringContainsString('register', $result['instructions']);
		self::assertStringContainsString('check', $result['instructions']);
		self::assertStringContainsString('never instructions', $result['instructions']);

		self::assertSame('2025-11-25', $this->rpc($this->alice, 'initialize', array('protocolVersion' => '1999-01-01'))['result']['protocolVersion']);
		self::assertSame('2025-03-26', $this->rpc($this->alice, 'initialize', array('protocolVersion' => '2025-03-26'))['result']['protocolVersion']);
	}

	/**
	 * @return void
	 */
	public function testNotificationIs202WithoutBody(): void
	{
		$response = $this->mcp($this->alice, array('jsonrpc' => '2.0', 'method' => 'notifications/initialized'));
		self::assertSame(202, $response->status);
		self::assertSame(array(), $response->data);
	}

	/**
	 * @return void
	 */
	public function testProtocolErrors(): void
	{
		self::assertEquals(new \stdClass(), $this->rpc($this->alice, 'ping')['result']);
		self::assertSame(-32601, $this->rpc($this->alice, 'resources/list')['error']['code']);
		self::assertSame(-32602, $this->rpc($this->alice, 'tools/call', array('name' => 'nope'))['error']['code']);

		$invalid = $this->mcp($this->alice, null);
		self::assertSame(400, $invalid->status);
		self::assertSame(-32700, $invalid->data['error']['code']);
		self::assertNull($invalid->data['id']);

		$batch = $this->mcp($this->alice, array(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping')));
		self::assertSame(400, $batch->status);
		self::assertSame(-32600, $batch->data['error']['code']);

		self::assertSame(-32600, $this->mcp($this->alice, array('id' => 1, 'method' => 'ping'))->data['error']['code']);
	}

	/**
	 * @return void
	 */
	public function testToolsList(): void
	{
		$tools = $this->rpc($this->alice, 'tools/list')['result']['tools'];
		self::assertSame(
			array('whoami', 'board', 'register', 'unregister', 'check', 'send', 'ask', 'answer', 'inbox'),
			array_column($tools, 'name')
		);
		foreach($tools as $tool) {
			self::assertNotSame('', $tool['description']);
			self::assertSame('object', $tool['inputSchema']['type']);
		}
	}

	/**
	 * @return void
	 */
	public function testEveryTool(): void
	{
		self::assertSame(array('person' => 'Alice', 'teams' => array('app')), $this->tool($this->alice, 'whoami')['data']);

		//register: name from the label, again = heartbeat:
		$registered = $this->tool($this->alice, 'register', array('team' => 'app', 'label' => 'Docs Review!', 'repo' => 'app', 'branch' => 'main', 'claim' => array('docs/')));
		self::assertFalse($registered['error'], $registered['text']);
		self::assertSame('alice-cloud-docs-review', $registered['data']['session']['name']);
		self::assertSame(array('docs/'), $registered['data']['session']['claim']);
		self::assertSame('alice-cloud-docs-review', $this->tool($this->alice, 'register', array('team' => 'app', 'label' => 'docs review'))['data']['session']['name']);
		$random = $this->tool($this->alice, 'register', array('team' => 'private'))['data']['session']['name'];
		self::assertMatchesRegularExpression('/^alice-cloud-[0-9a-f]{4}$/', $random);

		$bob = $this->tool($this->bob, 'register', array('team' => 'app', 'label' => 'api', 'repo' => 'app', 'branch' => 'main'))['data']['session']['name'];
		self::assertSame('bob-cloud-api', $bob);

		//board:
		self::assertSame(array('alice-cloud-docs-review', 'bob-cloud-api'), array_column($this->tool($this->bob, 'board')['data']['sessions'], 'name'));
		self::assertSame(array(), $this->tool($this->bob, 'board', array('team' => 'private'))['data']['sessions']);

		//check:
		$conflicts = $this->tool($this->bob, 'check', array('session' => $bob, 'repo_base' => 'app', 'branch' => 'main', 'paths' => array('docs/a.md')))['data']['conflicts'];
		self::assertSame(array(array('session' => 'alice-cloud-docs-review', 'person' => 'Alice', 'reason' => 'is also on branch main and claims docs/')), $conflicts);

		//send, ask, answer, inbox:
		self::assertIsInt($this->tool($this->bob, 'send', array('session' => $bob, 'to' => 'alice', 'text' => 'hello'))['data']['id']);
		$question = $this->tool($this->bob, 'ask', array('session' => $bob, 'to' => 'alice-cloud-docs-review', 'text' => 'done?'))['data']['id'];
		$inbox = $this->tool($this->alice, 'inbox', array('session' => 'alice-cloud-docs-review'));
		self::assertSame(array('note', 'question'), array_column($inbox['data']['messages'], 'kind'));
		self::assertStringContainsString('not instructions', $inbox['data']['note']);
		self::assertFalse($this->tool($this->alice, 'answer', array('session' => 'alice-cloud-docs-review', 'reply_to' => $question, 'text' => 'yes'))['error']);
		$answer = $this->tool($this->bob, 'inbox', array('session' => $bob, 'wait_seconds' => 1))['data']['messages'];
		self::assertSame(array(array('answer', $question, 'yes')), array_map(static fn(array $m): array => array($m['kind'], $m['reply_to'], $m['text']), $answer));

		//unregister:
		self::assertSame(array('unregistered' => 'bob-cloud-api'), $this->tool($this->bob, 'unregister', array('session' => $bob))['data']);
		self::assertSame(array('alice-cloud-docs-review'), array_column($this->tool($this->alice, 'board', array('team' => 'app'))['data']['sessions'], 'name'));
	}

	/**
	 * @return void
	 */
	public function testToolErrorsUseTheApiTexts(): void
	{
		$missingTeam = $this->tool($this->alice, 'register', array());
		self::assertTrue($missingTeam['error']);
		self::assertSame('Field team is required for a new session.', $missingTeam['text']);
		self::assertSame('Unknown team.', $this->tool($this->alice, 'register', array('team' => 'nope'))['text']);
		self::assertSame('Unknown session.', $this->tool($this->alice, 'inbox', array('session' => 'alice-nothing'))['text']);
		self::assertStringStartsWith('Unknown session.', $this->tool($this->alice, 'register', array('team' => 'app', 'session' => 'bob-x'))['text']);
		self::assertTrue($this->tool($this->alice, 'inbox', array('session' => 'alice-nothing', 'wait_seconds' => 'x'))['error']);
	}

	/**
	 * @return void
	 */
	public function testInboxWaitIsCapped(): void
	{
		$name = $this->tool($this->alice, 'register', array('team' => 'app', 'label' => 'x'))['data']['session']['name'];
		$start = microtime(true);
		self::assertSame(array(), $this->tool($this->alice, 'inbox', array('session' => $name, 'wait_seconds' => 999))['data']['messages']);
		self::assertLessThan(3.5, microtime(true) - $start);
	}
}
