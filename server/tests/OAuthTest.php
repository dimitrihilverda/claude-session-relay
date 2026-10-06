<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/** Uses */

use Relay\App;
use Relay\Auth;
use Relay\Cleaner;
use Relay\Http\Request;
use Relay\Http\Response;

/**
 * Tests the OAuth 2.1 server that lets Claude connect to /mcp as a custom connector.
 * @author d.hilverda <dimitri.hilverda@moving-in.nl>
 * @date 06-10-2026
 */
final class OAuthTest extends DbTestCase
{
	const string CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

	const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk-and-some-more-chars';

	private string $alice;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		$this->alice = $this->person('Alice')['token'];
	}

	/**
	 * @param string $method
	 * @param string $path
	 * @param array<string, mixed> $query
	 * @param array<string, mixed>|null $body
	 * @param array<string, mixed> $form
	 * @param string|null $token
	 * @param string $publicUrl
	 * @return Response
	 */
	private function http(string $method, string $path, array $query = array(), ?array $body = array(), array $form = array(), ?string $token = null, string $publicUrl = 'https://relay.test', string $ip = '203.0.113.1'): Response
	{
		return (new App($this->pdo, 2, $publicUrl))->handle(new Request($method, $path, $query, $body, $token, $form, $ip));
	}

	/**
	 * @param string $verifier
	 * @return string
	 */
	private static function challenge(string $verifier): string
	{
		return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
	}

	/**
	 * @param list<string> $redirectUris
	 * @return string client_id
	 */
	private function client(array $redirectUris = array(self::CALLBACK)): string
	{
		$response = $this->http('POST', '/oauth/register', array(), array('client_name' => 'Claude', 'redirect_uris' => $redirectUris));
		self::assertSame(201, $response->status, (string) json_encode($response->data));

		return $response->data['client_id'];
	}

	/**
	 * @param string $clientId
	 * @param string $redirectUri
	 * @param array<string, string> $extra
	 * @return array<string, string>
	 */
	private static function params(string $clientId, string $redirectUri = self::CALLBACK, array $extra = array()): array
	{
		return $extra + array(
			'response_type' => 'code',
			'client_id' => $clientId,
			'redirect_uri' => $redirectUri,
			'code_challenge' => self::challenge(self::VERIFIER),
			'code_challenge_method' => 'S256',
			'state' => 'xyz-state',
			'resource' => 'https://relay.test/mcp',
		);
	}

	/**
	 * Opens the consent page and returns the hidden CSRF token.
	 * @param array<string, string> $params
	 * @return string
	 */
	private function csrf(array $params): string
	{
		$page = $this->http('GET', '/oauth/authorize', $params);
		self::assertSame(200, $page->status);
		self::assertSame(1, preg_match('/name="csrf" value="([^"]+)"/', (string) $page->html, $m));

		return html_entity_decode($m[1]);
	}

	/**
	 * Approves on the consent page and returns the code from the redirect.
	 * @param string $clientId
	 * @param string $redirectUri
	 * @return string
	 */
	private function code(string $clientId, string $redirectUri = self::CALLBACK): string
	{
		$params = self::params($clientId, $redirectUri);
		$response = $this->http('POST', '/oauth/authorize', array(), null, $params + array('csrf' => $this->csrf($params), 'relay_token' => $this->alice, 'action' => 'approve'));
		self::assertSame(302, $response->status, (string) $response->html);
		$location = $response->headers['Location'];
		self::assertStringStartsWith($redirectUri . '?', $location);
		parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
		self::assertSame('xyz-state', $query['state']);

		return $query['code'];
	}

	/**
	 * @param array<string, string> $form
	 * @return Response
	 */
	private function token(array $form): Response
	{
		return $this->http('POST', '/oauth/token', array(), null, $form);
	}

	/**
	 * @param string $clientId
	 * @param string $code
	 * @param string $redirectUri
	 * @param string $verifier
	 * @return Response
	 */
	private function exchange(string $clientId, string $code, string $redirectUri = self::CALLBACK, string $verifier = self::VERIFIER): Response
	{
		return $this->token(array('grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri, 'client_id' => $clientId, 'code_verifier' => $verifier));
	}

	/**
	 * @param string $accessToken
	 * @return int Status of an MCP ping with this token.
	 */
	private function mcpStatus(string $accessToken): int
	{
		return $this->http('POST', '/mcp', array(), array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'), array(), $accessToken)->status;
	}

	/**
	 * @return void
	 */
	public function testProtectedResourceMetadata(): void
	{
		$expected = array(
			'resource' => 'https://relay.test/mcp',
			'authorization_servers' => array('https://relay.test'),
			'bearer_methods_supported' => array('header'),
			'resource_name' => 'Session relay',
		);
		self::assertSame($expected, $this->http('GET', '/.well-known/oauth-protected-resource')->data);
		self::assertSame($expected, $this->http('GET', '/.well-known/oauth-protected-resource/mcp')->data);
	}

	/**
	 * @return void
	 */
	public function testAuthorizationServerMetadata(): void
	{
		self::assertSame(array(
			'issuer' => 'https://relay.test',
			'authorization_endpoint' => 'https://relay.test/oauth/authorize',
			'token_endpoint' => 'https://relay.test/oauth/token',
			'registration_endpoint' => 'https://relay.test/oauth/register',
			'response_types_supported' => array('code'),
			'grant_types_supported' => array('authorization_code', 'refresh_token'),
			'code_challenge_methods_supported' => array('S256'),
			'token_endpoint_auth_methods_supported' => array('none'),
		), $this->http('GET', '/.well-known/oauth-authorization-server')->data);
	}

	/**
	 * @return void
	 */
	public function testPublicUrlFromConfigWins(): void
	{
		$data = $this->http('GET', '/.well-known/oauth-protected-resource', array(), array(), array(), null, 'https://relay.example.org/')->data;
		self::assertSame('https://relay.example.org/mcp', $data['resource']);
		self::assertSame(array('https://relay.example.org'), $data['authorization_servers']);
		self::assertSame('https://relay.example.org', $this->http('GET', '/.well-known/oauth-authorization-server', array(), array(), array(), null, 'https://relay.example.org')->data['issuer']);
	}

	/**
	 * @return void
	 */
	public function testRegisterClient(): void
	{
		$response = $this->http('POST', '/oauth/register', array(), array('client_name' => 'Claude', 'redirect_uris' => array(self::CALLBACK, 'http://localhost:33418/callback', 'http://127.0.0.1:5000/callback')));
		self::assertSame(201, $response->status);
		self::assertSame('Claude', $response->data['client_name']);
		self::assertSame('none', $response->data['token_endpoint_auth_method']);
		self::assertSame(array('authorization_code', 'refresh_token'), $response->data['grant_types']);
		self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $response->data['client_id']);
		self::assertArrayNotHasKey('client_secret', $response->data);
	}

	/**
	 * @return list<array{mixed}>
	 */
	public static function badRedirects(): array
	{
		return array(
			array(array('https://evil.example/api/mcp/auth_callback')),
			array(array('https://claude.ai/api/mcp/auth_callback/x')),
			array(array('https://claude.ai/api/mcp/auth_callback?x=1')),
			array(array('http://claude.ai/api/mcp/auth_callback')),
			array(array('http://localhost:1234/other')),
			array(array('http://localhost.evil.example:80/callback')),
			array(array('http://user@localhost:80/callback')),
			array(array('https://localhost:80/callback')),
			array(array('http://localhost:80/callback#x')),
			array(array("http://localhost:80/callback\n")),
			array(array("https://claude.ai/api/mcp/auth_callback\n")),
			array(array(self::CALLBACK, 'https://evil.example/cb')),
			array(array()),
			array('https://claude.ai/api/mcp/auth_callback'),
		);
	}

	/**
	 * @param mixed $uris
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('badRedirects')]
	public function testRegisterRejectsOtherRedirects(mixed $uris): void
	{
		$response = $this->http('POST', '/oauth/register', array(), array('client_name' => 'x', 'redirect_uris' => $uris));
		self::assertSame(400, $response->status);
		self::assertSame('invalid_redirect_uri', $response->data['error']);
		self::assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM oauth_client')->fetchColumn());
	}

	/**
	 * @return void
	 */
	public function testRegistrationIsRateLimitedPerAddress(): void
	{
		for($i = 0; $i < 20; $i++) {
			$this->client();
		}
		$limited = $this->http('POST', '/oauth/register', array(), array('redirect_uris' => array(self::CALLBACK)));
		self::assertSame(429, $limited->status);
		self::assertSame('temporarily_unavailable', $limited->data['error']);

		//Another address may still register, and an hour later this one may again:
		self::assertSame(201, $this->http('POST', '/oauth/register', array(), array('redirect_uris' => array(self::CALLBACK)), array(), null, 'https://relay.test', '198.51.100.7')->status);
		$this->pdo->exec("UPDATE oauth_client SET created_at = now() - interval '61 minutes'");
		self::assertSame(201, $this->http('POST', '/oauth/register', array(), array('redirect_uris' => array(self::CALLBACK)))->status);

		//The address itself is not stored:
		self::assertStringNotContainsString('203.0.113.1', (string) json_encode($this->pdo->query('SELECT * FROM oauth_client')->fetchAll()));
	}

	/**
	 * @return void
	 */
	public function testRegistrationHasAGlobalCap(): void
	{
		$this->pdo->exec("INSERT INTO oauth_client (id, name, redirect_uris, ip_hash) SELECT md5(i::text), 'x', '[]', md5(i::text) FROM generate_series(1, 500) i");
		$full = $this->http('POST', '/oauth/register', array(), array('redirect_uris' => array(self::CALLBACK)));
		self::assertSame(503, $full->status);
		self::assertSame('temporarily_unavailable', $full->data['error']);

		//Clients in use or older than a day do not count:
		$this->pdo->exec("UPDATE oauth_client SET created_at = now() - interval '25 hours' WHERE name = 'x' AND id IN (SELECT id FROM oauth_client LIMIT 1)");
		self::assertSame(201, $this->http('POST', '/oauth/register', array(), array('redirect_uris' => array(self::CALLBACK)))->status);
	}

	/**
	 * @return void
	 */
	public function testTrailingNewlinesNeverMatch(): void
	{
		$client = $this->client(array('http://localhost:5000/callback'));
		self::assertSame(400, $this->http('GET', '/oauth/authorize', self::params($client . "\n", 'http://localhost:5000/callback'))->status);
		self::assertSame(400, $this->http('GET', '/oauth/authorize', self::params($client, "http://localhost:5000/callback\n"))->status);
		$badChallenge = $this->http('GET', '/oauth/authorize', self::params($client, 'http://localhost:5000/callback', array('code_challenge' => self::challenge(self::VERIFIER) . "\n")));
		self::assertSame(302, $badChallenge->status);
		self::assertStringContainsString('error=invalid_request', $badChallenge->headers['Location']);
		self::assertSame('invalid_grant', $this->exchange($client, $this->code($client, 'http://localhost:5000/callback'), 'http://localhost:5000/callback', self::VERIFIER . "\n")->data['error']);
	}

	/**
	 * @return void
	 */
	public function testRegisterRejectsConfidentialClients(): void
	{
		$response = $this->http('POST', '/oauth/register', array(), array('redirect_uris' => array(self::CALLBACK), 'token_endpoint_auth_method' => 'client_secret_basic'));
		self::assertSame(400, $response->status);
		self::assertSame('invalid_client_metadata', $response->data['error']);
	}

	/**
	 * @return void
	 */
	public function testConsentPageShowsClientAndRedirectHost(): void
	{
		$client = $this->http('POST', '/oauth/register', array(), array('client_name' => '<b>Claude</b>', 'redirect_uris' => array(self::CALLBACK)))->data['client_id'];
		$page = $this->http('GET', '/oauth/authorize', self::params($client));

		self::assertSame(200, $page->status);
		self::assertStringContainsString('Unverified app name: &lt;b&gt;Claude&lt;/b&gt;', (string) $page->html);
		self::assertMatchesRegularExpression('#<strong class="host">claude\.ai</strong>#', (string) $page->html);
		self::assertStringNotContainsString('<b>Claude</b>', (string) $page->html);
		self::assertStringContainsString('claude.ai', (string) $page->html);
		self::assertSame('DENY', $page->headers['X-Frame-Options']);
		self::assertStringContainsString("frame-ancestors 'none'", $page->headers['Content-Security-Policy']);
		self::assertDoesNotMatchRegularExpression('#(src|href)="https?://#', (string) $page->html);
	}

	/**
	 * @return void
	 */
	public function testFullFlowWithRefreshRotationAndReuseDetection(): void
	{
		$client = $this->client();
		$tokens = $this->exchange($client, $this->code($client));
		self::assertSame(200, $tokens->status, (string) json_encode($tokens->data));
		self::assertSame('Bearer', $tokens->data['token_type']);
		self::assertSame(3600, $tokens->data['expires_in']);
		self::assertSame('no-store', $tokens->headers['Cache-Control']);
		self::assertSame(200, $this->mcpStatus($tokens->data['access_token']));

		//Only hashes are stored:
		$stored = (string) json_encode($this->pdo->query('SELECT * FROM oauth_token')->fetchAll());
		self::assertStringNotContainsString($tokens->data['access_token'], $stored);
		self::assertStringNotContainsString($tokens->data['refresh_token'], $stored);

		//Refresh rotates both tokens:
		$refreshed = $this->token(array('grant_type' => 'refresh_token', 'refresh_token' => $tokens->data['refresh_token'], 'client_id' => $client));
		self::assertSame(200, $refreshed->status, (string) json_encode($refreshed->data));
		self::assertNotSame($tokens->data['refresh_token'], $refreshed->data['refresh_token']);
		self::assertSame(401, $this->mcpStatus($tokens->data['access_token']));
		self::assertSame(200, $this->mcpStatus($refreshed->data['access_token']));

		//Reusing the old refresh token revokes the whole family:
		$reuse = $this->token(array('grant_type' => 'refresh_token', 'refresh_token' => $tokens->data['refresh_token'], 'client_id' => $client));
		self::assertSame(400, $reuse->status);
		self::assertSame('invalid_grant', $reuse->data['error']);
		self::assertSame(401, $this->mcpStatus($refreshed->data['access_token']));
		$again = $this->token(array('grant_type' => 'refresh_token', 'refresh_token' => $refreshed->data['refresh_token'], 'client_id' => $client));
		self::assertSame('invalid_grant', $again->data['error']);
	}

	/**
	 * @return void
	 */
	public function testLoopbackRedirectIsPortAgnostic(): void
	{
		$client = $this->client(array('http://127.0.0.1:1111/callback'));
		$code = $this->code($client, 'http://127.0.0.1:2222/callback');

		self::assertSame('invalid_grant', $this->exchange($client, $code, 'http://127.0.0.1:3333/callback')->data['error']);
		self::assertSame(200, $this->exchange($client, $this->code($client, 'http://127.0.0.1:2222/callback'), 'http://127.0.0.1:2222/callback')->status);
	}

	/**
	 * @return void
	 */
	public function testWrongRelayTokenShowsErrorAndDoesNotRedirect(): void
	{
		$client = $this->client();
		$params = self::params($client);
		$response = $this->http('POST', '/oauth/authorize', array(), null, $params + array('csrf' => $this->csrf($params), 'relay_token' => 'wrong', 'action' => 'approve'));

		self::assertSame(401, $response->status);
		self::assertArrayNotHasKey('Location', $response->headers);
		self::assertStringContainsString('not valid', (string) $response->html);
		self::assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM oauth_code')->fetchColumn());
	}

	/**
	 * @return void
	 */
	public function testDenyRedirectsWithAccessDenied(): void
	{
		$client = $this->client();
		$params = self::params($client);
		$response = $this->http('POST', '/oauth/authorize', array(), null, $params + array('csrf' => $this->csrf($params), 'action' => 'deny'));

		self::assertSame(302, $response->status);
		self::assertSame(self::CALLBACK . '?error=access_denied&state=xyz-state', $response->headers['Location']);
	}

	/**
	 * @return void
	 */
	public function testCsrfIsBoundToTheRequest(): void
	{
		$client = $this->client();
		$params = self::params($client);
		$csrf = $this->csrf($params);

		foreach(array('', 'garbage', $csrf . 'x') as $bad) {
			$response = $this->http('POST', '/oauth/authorize', array(), null, $params + array('csrf' => $bad, 'relay_token' => $this->alice, 'action' => 'approve'));
			self::assertSame(400, $response->status);
			self::assertArrayNotHasKey('Location', $response->headers);
		}

		//The same CSRF token with another challenge or state does not work:
		$other = array('code_challenge' => self::challenge('another-verifier-another-verifier-another-1')) + $params;
		self::assertSame(400, $this->http('POST', '/oauth/authorize', array(), null, $other + array('csrf' => $csrf, 'relay_token' => $this->alice, 'action' => 'approve'))->status);
		$other = array('state' => 'other') + $params;
		self::assertSame(400, $this->http('POST', '/oauth/authorize', array(), null, $other + array('csrf' => $csrf, 'relay_token' => $this->alice, 'action' => 'approve'))->status);
	}

	/**
	 * @return void
	 */
	public function testUnknownClientOrRedirectNeverRedirects(): void
	{
		$client = $this->client();
		foreach(array(self::params('0123456789abcdef0123456789abcdef'), self::params($client, 'http://localhost:9/callback'), self::params($client, 'https://evil.example/cb')) as $params) {
			$response = $this->http('GET', '/oauth/authorize', $params);
			self::assertSame(400, $response->status);
			self::assertArrayNotHasKey('Location', $response->headers);
			self::assertNotNull($response->html);
		}
	}

	/**
	 * @return void
	 */
	public function testInvalidRequestRedirectsWithError(): void
	{
		$client = $this->client();
		foreach(array(array('code_challenge' => ''), array('code_challenge_method' => 'plain'), array('response_type' => 'token'), array('resource' => 'https://other.example/mcp')) as $override) {
			$response = $this->http('GET', '/oauth/authorize', $override + self::params($client));
			self::assertSame(302, $response->status, json_encode($override) . (string) $response->html);
			parse_str((string) parse_url($response->headers['Location'], PHP_URL_QUERY), $query);
			self::assertContains($query['error'], array('invalid_request', 'unsupported_response_type', 'invalid_target'));
			self::assertSame('xyz-state', $query['state']);
		}
	}

	/**
	 * @return void
	 */
	public function testBadPkceWrongRedirectUsedAndExpiredCodes(): void
	{
		$client = $this->client(array(self::CALLBACK, 'http://localhost:5000/callback'));

		self::assertSame('invalid_grant', $this->exchange($client, $this->code($client), self::CALLBACK, str_repeat('a', 50))->data['error']);
		self::assertSame('invalid_grant', $this->exchange($client, $this->code($client), 'http://localhost:5000/callback')->data['error']);
		self::assertSame('invalid_grant', $this->exchange($this->client(), $this->code($client))->data['error']);

		//Replaying a used code fails and revokes what was issued from it:
		$code = $this->code($client);
		$issued = $this->exchange($client, $code);
		self::assertSame(200, $issued->status);
		self::assertSame(200, $this->mcpStatus($issued->data['access_token']));
		$used = $this->exchange($client, $code);
		self::assertSame(400, $used->status);
		self::assertSame('invalid_grant', $used->data['error']);
		self::assertSame(401, $this->mcpStatus($issued->data['access_token']));
		self::assertSame('invalid_grant', $this->token(array('grant_type' => 'refresh_token', 'refresh_token' => $issued->data['refresh_token'], 'client_id' => $client))->data['error']);

		$code = $this->code($client);
		$this->pdo->exec("UPDATE oauth_code SET expires_at = now() - interval '1 second'");
		self::assertSame('invalid_grant', $this->exchange($client, $code)->data['error']);

		self::assertSame('unsupported_grant_type', $this->token(array('grant_type' => 'password'))->data['error']);
		self::assertSame('invalid_request', $this->token(array('grant_type' => 'authorization_code'))->data['error']);
	}

	/**
	 * @return void
	 */
	public function testRevokingOrRecreatingPersonKillsTokens(): void
	{
		$client = $this->client();
		$first = $this->exchange($client, $this->code($client))->data;
		(new Auth($this->pdo))->revoke('Alice');
		self::assertSame(401, $this->mcpStatus($first['access_token']));
		self::assertSame('invalid_grant', $this->token(array('grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'], 'client_id' => $client))->data['error']);

		$this->alice = $this->person('Alice')['token'];
		$second = $this->exchange($client, $this->code($client))->data;
		self::assertSame(200, $this->mcpStatus($second['access_token']));
		$this->person('Alice');
		self::assertSame(401, $this->mcpStatus($second['access_token']));
	}

	/**
	 * @return void
	 */
	public function testCleanupRemovesExpiredOAuthRows(): void
	{
		$client = $this->client();
		$this->exchange($client, $this->code($client));
		$this->code($client);
		$this->client();
		$this->pdo->exec("UPDATE oauth_code SET expires_at = now() - interval '2 hours'");
		$this->pdo->exec("UPDATE oauth_token SET refresh_expires_at = now() - interval '1 second'");
		$this->pdo->exec("UPDATE oauth_client SET created_at = now() - interval '2 days'");

		self::assertSame(array('sessions' => 0, 'messages' => 0, 'oauth' => 5), (new Cleaner($this->pdo))->cleanUp());
		self::assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM oauth_client')->fetchColumn());
	}
}
