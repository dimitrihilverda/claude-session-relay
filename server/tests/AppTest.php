<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/** Uses */

use Relay\App;
use Relay\Http\Request;

/**
 * Tests the general HTTP handling.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class AppTest extends DbTestCase
{
	/**
	 * @return void
	 */
	public function testHealthWithoutToken(): void
	{
		$response = $this->request('GET', '/health', null);
		self::assertSame(200, $response->status);
		self::assertSame(array('status' => 'ok'), $response->data);
	}

	/**
	 * @return void
	 */
	public function testWithoutValidTokenIs401(): void
	{
		self::assertSame(401, $this->request('GET', '/board', null)->status);
		self::assertSame(401, $this->request('GET', '/board', 'nonsense')->status);
		self::assertSame(array('error' => 'Invalid or revoked token.'), $this->request('GET', '/me', 'nonsense')->data);
	}

	/**
	 * @return void
	 */
	public function testUnknownEndpointIs404(): void
	{
		$token = $this->person('Alice')['token'];
		$response = $this->request('GET', '/does-not-exist', $token);
		self::assertSame(404, $response->status);
		self::assertArrayHasKey('error', $response->data);
	}

	/**
	 * @return void
	 */
	public function testOldDutchEndpointsAreGone(): void
	{
		$token = $this->person('Alice')['token'];
		self::assertSame(404, $this->request('GET', '/gezond', $token)->status);
		self::assertSame(404, $this->request('GET', '/bord', $token)->status);
		self::assertSame(404, $this->request('POST', '/sessie', $token)->status);
		self::assertSame(404, $this->request('POST', '/bericht', $token)->status);
	}

	/**
	 * @return void
	 */
	public function testInvalidJsonIs422(): void
	{
		$token = $this->person('Alice')['token'];
		$response = (new App($this->pdo))->handle(new Request('POST', '/session', array(), null, $token));
		self::assertSame(422, $response->status);
	}

	/**
	 * @return void
	 */
	public function testMeListsPersonAndOwnTeamsOnly(): void
	{
		$alice = $this->person('Alice')['token'];
		$this->person('Bob');
		$this->team('beta', 'Alice');
		$this->team('alpha', 'Alice', 'Bob');
		$this->team('gamma', 'Bob');

		$response = $this->request('GET', '/me', $alice);
		self::assertSame(200, $response->status);
		self::assertSame(array('person' => 'Alice', 'teams' => array('alpha', 'beta')), $response->data);
	}

	/**
	 * @return void
	 */
	public function testBoardPageWithoutToken(): void
	{
		$response = $this->request('GET', '/', null);
		self::assertSame(200, $response->status);
		self::assertNotNull($response->html);
		self::assertStringContainsString('<title>', $response->html);
		self::assertStringContainsString("fetch('/board", $response->html);
		self::assertSame(401, $this->request('GET', '/board', null)->status);
	}
}
