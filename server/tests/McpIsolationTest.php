<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/** Uses */

use Relay\App;
use Relay\Http\Request;

/**
 * Team isolation through MCP is exactly the isolation of the HTTP API.
 *
 * Dimitri: moving-in + gti. Chantal: moving-in. Mez: gti (connects through OAuth).
 * @author d.hilverda <dimitri.hilverda@moving-in.nl>
 * @date 06-10-2026
 */
final class McpIsolationTest extends DbTestCase
{
	private string $dimitri;

	private string $chantal;

	private string $mez;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		$this->dimitri = $this->person('dimitri')['token'];
		$this->chantal = $this->person('chantal')['token'];
		$mezRelayToken = $this->person('mez')['token'];
		$this->team('moving-in', 'dimitri', 'chantal');
		$this->team('gti', 'dimitri', 'mez');

		$this->register($this->dimitri, 'dimitri-mi', array('team' => 'moving-in', 'claim' => array('src/')));
		$this->register($this->dimitri, 'dimitri-gti', array('team' => 'gti', 'claim' => array('src/')));
		$this->register($this->chantal, 'chantal-mi', array('team' => 'moving-in', 'claim' => array('src/')));
		$this->tool($this->dimitri, 'register', array('team' => 'private', 'label' => 'hobby'));

		//Mez uses an OAuth access token, like claude.ai would:
		$this->mez = $this->oauthToken($mezRelayToken);
		$this->tool($this->mez, 'register', array('team' => 'gti', 'label' => 'touch', 'repo' => 'app', 'branch' => 'test'));
	}

	/**
	 * @param string $relayToken
	 * @return string An OAuth access token for that person.
	 */
	private function oauthToken(string $relayToken): string
	{
		$app = new App($this->pdo, 2, 'https://relay.test');
		$request = static fn(string $method, string $path, array $query, ?array $body, array $form): Request => new Request($method, $path, $query, $body, null, $form, '203.0.113.9');
		$client = $app->handle($request('POST', '/oauth/register', array(), array('client_name' => 'Claude', 'redirect_uris' => array('https://claude.ai/api/mcp/auth_callback')), array()))->data['client_id'];
		$verifier = str_repeat('v', 64);
		$params = array(
			'response_type' => 'code', 'client_id' => $client, 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback', 'state' => 's',
			'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
		);
		preg_match('/name="csrf" value="([^"]+)"/', (string) $app->handle($request('GET', '/oauth/authorize', $params, array(), array()))->html, $m);
		$location = $app->handle($request('POST', '/oauth/authorize', array(), null, $params + array('csrf' => html_entity_decode($m[1]), 'relay_token' => $relayToken, 'action' => 'approve')))->headers['Location'];
		parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
		$tokens = $app->handle($request('POST', '/oauth/token', array(), null, array('grant_type' => 'authorization_code', 'code' => $query['code'], 'redirect_uri' => $params['redirect_uri'], 'client_id' => $client, 'code_verifier' => $verifier)));

		return $tokens->data['access_token'];
	}

	/**
	 * @param string $token
	 * @param string $name
	 * @param array<string, mixed> $arguments
	 * @return array{error:bool,data:mixed,text:string}
	 */
	private function tool(string $token, string $name, array $arguments = array()): array
	{
		$response = (new App($this->pdo, 2, 'https://relay.test'))->handle(new Request('POST', '/mcp', array(), array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array('name' => $name, 'arguments' => $arguments)), $token));
		self::assertSame(200, $response->status, (string) json_encode($response->data));
		$result = $response->data['result'];

		return array('error' => $result['isError'], 'data' => $result['structuredContent'] ?? null, 'text' => $result['content'][0]['text']);
	}

	/**
	 * @param array{error:bool,data:mixed,text:string} $hidden
	 * @param array{error:bool,data:mixed,text:string} $missing
	 * @return void
	 */
	private static function assertSameAsMissing(array $hidden, array $missing): void
	{
		self::assertTrue($missing['error']);
		self::assertSame($missing, $hidden);
	}

	/**
	 * @return void
	 */
	public function testMezSeesOnlyGti(): void
	{
		self::assertSame(array('person' => 'mez', 'teams' => array('gti')), $this->tool($this->mez, 'whoami')['data']);
		$board = $this->tool($this->mez, 'board');
		self::assertSame(array('dimitri-gti', 'mez-cloud-touch'), array_column($board['data']['sessions'], 'name'));
		self::assertStringNotContainsString('moving-in', $board['text']);
		self::assertStringNotContainsString('chantal', $board['text']);
		self::assertStringNotContainsString('hobby', $board['text']);
		self::assertSame(array(), $this->tool($this->mez, 'board', array('team' => 'moving-in'))['data']['sessions']);
	}

	/**
	 * @return void
	 */
	public function testCheckNeverReportsMovingIn(): void
	{
		$conflicts = $this->tool($this->mez, 'check', array('session' => 'mez-cloud-touch', 'repo_base' => 'app', 'branch' => 'test', 'paths' => array('src/a.ts')))['data']['conflicts'];
		self::assertSame(array('dimitri-gti'), array_column($conflicts, 'session'));
	}

	/**
	 * @return void
	 */
	public function testMessagesToMovingInLookLikeNonExistent(): void
	{
		$send = fn(string $to): array => $this->tool($this->mez, 'send', array('session' => 'mez-cloud-touch', 'to' => $to, 'text' => 'hi'));
		$missing = $send('nobody');
		self::assertSame('No such session or person.', $missing['text']);
		self::assertSameAsMissing($send('chantal'), $missing);
		self::assertSameAsMissing($send('chantal-mi'), $missing);
		self::assertSameAsMissing($send('dimitri-mi'), $missing);
		self::assertSameAsMissing($send('dimitri-cloud-hobby'), $missing);
		self::assertFalse($send('dimitri')['error']);
	}

	/**
	 * @return void
	 */
	public function testForeignSessionsAndTeamsLookLikeNonExistent(): void
	{
		$missingTeam = $this->tool($this->mez, 'register', array('team' => 'no-such-team'));
		self::assertSameAsMissing($this->tool($this->mez, 'register', array('team' => 'moving-in')), $missingTeam);

		foreach(array('inbox', 'unregister') as $tool) {
			$missing = $this->tool($this->mez, $tool, array('session' => 'dimitri-nothing'));
			self::assertSameAsMissing($this->tool($this->mez, $tool, array('session' => 'dimitri-mi')), $missing);
			self::assertSameAsMissing($this->tool($this->mez, $tool, array('session' => 'dimitri-cloud-hobby')), $missing);
		}
		$check = fn(string $session): array => $this->tool($this->mez, 'check', array('session' => $session, 'repo_base' => 'app', 'branch' => 'test', 'paths' => array()));
		self::assertSameAsMissing($check('dimitri-mi'), $check('dimitri-nothing'));
		$register = fn(string $session): array => $this->tool($this->mez, 'register', array('team' => 'gti', 'session' => $session));
		self::assertSameAsMissing($register('dimitri-mi'), $register('dimitri-nothing'));

		$answer = $this->tool($this->dimitri, 'ask', array('session' => 'dimitri-mi', 'to' => 'chantal', 'text' => 'secret?'))['data']['id'];
		$reply = fn(int $id): array => $this->tool($this->mez, 'answer', array('session' => 'mez-cloud-touch', 'reply_to' => $id, 'text' => 'x'));
		self::assertSameAsMissing($reply($answer), $reply($answer + 1000));
		self::assertSame(3, (int) $this->pdo->query("SELECT count(*) FROM session WHERE name LIKE 'dimitri-%'")->fetchColumn());
	}

	/**
	 * @return void
	 */
	public function testPrivateCloudSessionStaysPrivate(): void
	{
		self::assertNotContains('dimitri-cloud-hobby', array_column($this->tool($this->chantal, 'board')['data']['sessions'], 'name'));
		self::assertContains('dimitri-cloud-hobby', array_column($this->tool($this->dimitri, 'board')['data']['sessions'], 'name'));
		$missing = $this->tool($this->chantal, 'send', array('session' => 'chantal-mi', 'to' => 'nobody', 'text' => 'x'));
		self::assertSameAsMissing($this->tool($this->chantal, 'send', array('session' => 'chantal-mi', 'to' => 'dimitri-cloud-hobby', 'text' => 'x')), $missing);
	}
}
