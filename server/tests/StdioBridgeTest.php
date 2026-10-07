<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/** Uses */

use PHPUnit\Framework\TestCase;
use Relay\Mcp;
use Relay\StdioBridge;

/**
 * The stdio bridge answers the handshake and tool list itself and needs a relay only for tool calls.
 * @author Dimitri Hilverda
 * @date 07-10-2026
 */
final class StdioBridgeTest extends TestCase
{
	/**
	 * @return void
	 */
	public function testHandshakeAndToolListWorkWithoutRelay(): void
	{
		$bridge = new StdioBridge('', '');

		$init = $bridge->handleLine('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}');
		$this->assertSame('2025-06-18', $init['result']['protocolVersion']);
		$this->assertStringContainsString('RELAY_URL', $init['result']['instructions']);

		$this->assertNull($bridge->handleLine('{"jsonrpc":"2.0","method":"notifications/initialized"}'));

		$list = $bridge->handleLine('{"jsonrpc":"2.0","id":2,"method":"tools/list"}');
		$this->assertSame(array_column(Mcp::tools(), 'name'), array_column($list['result']['tools'], 'name'));
	}

	/**
	 * @return void
	 */
	public function testToolCallWithoutConfigurationIsAToolError(): void
	{
		$reply = (new StdioBridge('', ''))->handleLine('{"jsonrpc":"2.0","id":"x","method":"tools/call","params":{"name":"board","arguments":{}}}');

		$this->assertSame('x', $reply['id']);
		$this->assertTrue($reply['result']['isError']);
	}

	/**
	 * @return void
	 */
	public function testUnreachableRelayIsAToolError(): void
	{
		$reply = (new StdioBridge('http://127.0.0.1:9', 'token', 2))->handleLine('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"board","arguments":{}}}');

		$this->assertTrue($reply['result']['isError']);
		$this->assertStringContainsString('unreachable', $reply['result']['content'][0]['text']);
	}

	/**
	 * @return void
	 */
	public function testTokenIsNeverSentOverPlainHttpToAnotherHost(): void
	{
		$reply = (new StdioBridge('http://relay.example.com', 'token', 2))->handleLine('{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"board","arguments":{}}}');

		$this->assertTrue($reply['result']['isError']);
		$this->assertStringContainsString('https://', $reply['result']['content'][0]['text']);
	}

	/**
	 * @return void
	 */
	public function testInvalidInput(): void
	{
		$bridge = new StdioBridge('', '');

		$this->assertSame(-32700, $bridge->handleLine('not json')['error']['code']);
		$this->assertSame(-32600, $bridge->handleLine('[]')['error']['code']);
		$this->assertSame(-32601, $bridge->handleLine('{"jsonrpc":"2.0","id":4,"method":"resources/list"}')['error']['code']);
	}
}
