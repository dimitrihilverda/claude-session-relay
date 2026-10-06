<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/** Uses */

use PDO;
use Relay\Http\Request;
use Relay\Http\Response;

/**
 * The OAuth 2.1 endpoints Claude needs to add the relay as a custom connector: metadata
 * (RFC 9728, RFC 8414), dynamic client registration (RFC 7591), the consent page and the token endpoint.
 * The person proves who they are by pasting their relay token on the consent page.
 * @author d.hilverda <dimitri.hilverda@moving-in.nl>
 * @date 06-10-2026
 */
final class OAuthServer
{
	const int CSRF_SECONDS = 600;

	private OAuthStore $store;

	/**
	 * @param PDO $pdo
	 * @param string $baseUrl Public base URL without trailing slash, e.g. https://relay.example.org.
	 */
	public function __construct(private PDO $pdo, private string $baseUrl)
	{
		$this->store = new OAuthStore($pdo);
	}

	/**
	 * @return string The URL of the MCP endpoint (the protected resource).
	 */
	public function resource(): string
	{
		return $this->baseUrl . '/mcp';
	}

	/**
	 * @return string scheme://host[:port] of the public URL (the only Origin allowed on /mcp).
	 */
	public function origin(): string
	{
		$url = parse_url($this->baseUrl);

		return ($url['scheme'] ?? '') . '://' . ($url['host'] ?? '') . (isset($url['port']) === true ? ':' . $url['port'] : '');
	}

	/**
	 * @return string Value of the WWW-Authenticate header on a 401 from /mcp.
	 */
	public function challengeHeader(): string
	{
		return 'Bearer resource_metadata="' . $this->baseUrl . '/.well-known/oauth-protected-resource"';
	}

	/**
	 * @return Response RFC 9728 protected resource metadata.
	 */
	public function protectedResource(): Response
	{
		return new Response(200, array(
			'resource' => $this->resource(),
			'authorization_servers' => array($this->baseUrl),
			'bearer_methods_supported' => array('header'),
			'resource_name' => 'Session relay',
		));
	}

	/**
	 * @return Response RFC 8414 authorization server metadata.
	 */
	public function authorizationServer(): Response
	{
		return new Response(200, array(
			'issuer' => $this->baseUrl,
			'authorization_endpoint' => $this->baseUrl . '/oauth/authorize',
			'token_endpoint' => $this->baseUrl . '/oauth/token',
			'registration_endpoint' => $this->baseUrl . '/oauth/register',
			'response_types_supported' => array('code'),
			'grant_types_supported' => array('authorization_code', 'refresh_token'),
			'code_challenge_methods_supported' => array('S256'),
			'token_endpoint_auth_methods_supported' => array('none'),
		));
	}

	/**
	 * Dynamic client registration; public clients with an allow-listed redirect only, rate limited.
	 * @param array<mixed>|null $body
	 * @param string $ip Address of the caller (REMOTE_ADDR).
	 * @return Response
	 */
	public function register(?array $body, string $ip): Response
	{
		$refusal = $this->store->registrationRefusal($ip);
		if($refusal !== 0) {
			return self::error($refusal, 'temporarily_unavailable', 'Too many client registrations; try again later.');
		}
		$body = $body ?? array();
		$uris = $body['redirect_uris'] ?? null;
		if(is_array($uris) === false || array_is_list($uris) === false || $uris === array() || count($uris) > 5) {
			return self::error(400, 'invalid_redirect_uri', 'redirect_uris must be a list of 1 to 5 URIs.');
		}
		foreach($uris as $uri) {
			if(is_string($uri) === false || OAuthStore::allowedRedirect($uri) === false) {
				return self::error(400, 'invalid_redirect_uri', 'Only ' . OAuthStore::CLAUDE_CALLBACK . ' and http://localhost:<port>/callback or http://127.0.0.1:<port>/callback are allowed.');
			}
		}
		$method = $body['token_endpoint_auth_method'] ?? 'none';
		if($method !== 'none') {
			return self::error(400, 'invalid_client_metadata', 'Only public clients (token_endpoint_auth_method none) are supported.');
		}
		$name = $body['client_name'] ?? 'Unnamed client';
		$name = is_string($name) === true && trim($name) !== '' ? mb_substr(trim($name), 0, 100) : 'Unnamed client';

		$id = $this->store->createClient($name, array_values(array_unique($uris)), $ip);

		return new Response(201, array(
			'client_id' => $id,
			'client_id_issued_at' => time(),
			'client_name' => $name,
			'redirect_uris' => array_values(array_unique($uris)),
			'token_endpoint_auth_method' => 'none',
			'grant_types' => array('authorization_code', 'refresh_token'),
			'response_types' => array('code'),
		), null, array('Cache-Control' => 'no-store'));
	}

	/**
	 * GET shows the consent page, POST approves or denies it.
	 * @param Request $request
	 * @return Response
	 */
	public function authorize(Request $request): Response
	{
		$in = $request->method === 'POST' ? $request->form : $request->query;
		$params = array();
		foreach(array('response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'resource', 'scope') as $key) {
			$params[$key] = is_string($in[$key] ?? null) === true ? $in[$key] : '';
		}

		//Without a known client and a registered redirect we never redirect anywhere:
		$client = preg_match('/^[0-9a-f]{32}\z/', $params['client_id']) === 1 ? $this->store->client($params['client_id']) : null;
		if($client === null) {
			return self::errorPage(400, 'Unknown client. Remove the connector and add it again.');
		}
		$registered = array_filter($client['redirect_uris'], static fn(string $uri): bool => OAuthStore::redirectMatches($uri, $params['redirect_uri']));
		if($registered === array() || OAuthStore::allowedRedirect($params['redirect_uri']) === false) {
			return self::errorPage(400, 'This redirect address is not registered for this client.');
		}

		//Other errors go back to the client:
		if($params['response_type'] !== 'code') {
			return $this->back($params, array('error' => 'unsupported_response_type'));
		}
		if(preg_match('/^[A-Za-z0-9_-]{43}\z/', $params['code_challenge']) !== 1 || $params['code_challenge_method'] !== 'S256') {
			return $this->back($params, array('error' => 'invalid_request', 'error_description' => 'PKCE with S256 is required.'));
		}
		if($params['resource'] !== '' && rtrim($params['resource'], '/') !== $this->resource()) {
			return $this->back($params, array('error' => 'invalid_target'));
		}

		if($request->method !== 'POST') {
			return $this->consent(200, $client['name'], $params, '');
		}

		//POST: the signed token proves these are the parameters our consent page showed (integrity, not
		//browser binding: the relay token the person types is what authenticates the approval):
		if($this->csrfValid((string) ($request->form['csrf'] ?? ''), $params) === false) {
			return self::errorPage(400, 'This approval form has expired or was changed. Start connecting again.');
		}
		if(($request->form['action'] ?? '') !== 'approve') {
			return $this->back($params, array('error' => 'access_denied'));
		}
		$token = $request->form['relay_token'] ?? '';
		$person = (new Auth($this->pdo))->personForToken(is_string($token) === true ? trim($token) : '');
		if($person === null) {
			return $this->consent(401, $client['name'], $params, 'That relay token is not valid (any more).');
		}

		return $this->back($params, array('code' => $this->store->createCode($client['id'], $person['id'], $params['redirect_uri'], $params['code_challenge'])));
	}

	/**
	 * The token endpoint (form encoded): authorization_code with PKCE, or refresh_token (rotating).
	 * @param array<string, mixed> $form
	 * @return Response
	 */
	public function token(array $form): Response
	{
		$get = static fn(string $key): string => is_string($form[$key] ?? null) === true ? $form[$key] : '';
		$grant = $get('grant_type');
		$tokens = null;
		if($grant === 'authorization_code') {
			if($get('code') === '' || $get('client_id') === '' || $get('redirect_uri') === '' || $get('code_verifier') === '') {
				return self::error(400, 'invalid_request', 'code, client_id, redirect_uri and code_verifier are required.');
			}
			$tokens = $this->store->redeemCode($get('code'), $get('client_id'), $get('redirect_uri'), $get('code_verifier'));
		} elseif($grant === 'refresh_token') {
			if($get('refresh_token') === '' || $get('client_id') === '') {
				return self::error(400, 'invalid_request', 'refresh_token and client_id are required.');
			}
			$tokens = $this->store->refresh($get('refresh_token'), $get('client_id'));
		} else {
			return self::error(400, 'unsupported_grant_type', 'Use authorization_code or refresh_token.');
		}
		if($tokens === null) {
			return self::error(400, 'invalid_grant', 'The code or refresh token is invalid, expired or already used.');
		}

		return new Response(200, array(
			'access_token' => $tokens['access_token'],
			'token_type' => 'Bearer',
			'expires_in' => OAuthStore::ACCESS_SECONDS,
			'refresh_token' => $tokens['refresh_token'],
		), null, array('Cache-Control' => 'no-store', 'Pragma' => 'no-cache'));
	}

	/**
	 * @param array<string, string> $params
	 * @param array<string, string> $result
	 * @return Response Redirect back to the client with the result and the state.
	 */
	private function back(array $params, array $result): Response
	{
		if($params['state'] !== '') {
			$result['state'] = $params['state'];
		}

		return Response::redirect($params['redirect_uri'] . '?' . http_build_query($result, '', '&', PHP_QUERY_RFC3986));
	}

	/**
	 * @param int $status
	 * @param string $clientName
	 * @param array<string, string> $params
	 * @param string $error
	 * @return Response
	 */
	private function consent(int $status, string $clientName, array $params, string $error): Response
	{
		$fields = '';
		foreach($params + array('csrf' => $this->csrf($params, time() + self::CSRF_SECONDS)) as $name => $value) {
			$fields .= '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($value) . '">';
		}
		$html = strtr((string) file_get_contents(__DIR__ . '/Consent.html'), array(
			'{{client}}' => self::e($clientName),
			'{{host}}' => self::e((string) parse_url($params['redirect_uri'], PHP_URL_HOST)),
			'{{redirect}}' => self::e($params['redirect_uri']),
			'{{error}}' => $error === '' ? '' : '<p class="error">' . self::e($error) . '</p>',
			'{{fields}}' => $fields,
		));

		return Response::page($status, $html);
	}

	/**
	 * @param array<string, string> $params
	 * @param int $expires Unix time.
	 * @return string HMAC over the request parameters and the expiry time: the parameters of an approval
	 *     cannot be changed after the page was shown. It does not bind the form to a browser.
	 */
	private function csrf(array $params, int $expires): string
	{
		ksort($params);

		return $expires . '.' . hash_hmac('sha256', $expires . "\n" . json_encode($params, JSON_THROW_ON_ERROR), $this->secret());
	}

	/**
	 * @param string $token
	 * @param array<string, string> $params
	 * @return bool
	 */
	private function csrfValid(string $token, array $params): bool
	{
		if(preg_match('/^([0-9]{1,12})\.[0-9a-f]{64}\z/', $token, $m) !== 1 || (int) $m[1] < time()) {
			return false;
		}

		return hash_equals($this->csrf($params, (int) $m[1]), $token);
	}

	/**
	 * @return string The server's signing key, created on first use.
	 */
	private function secret(): string
	{
		$this->pdo->prepare("INSERT INTO relay_secret (name, value) VALUES ('csrf', ?) ON CONFLICT (name) DO NOTHING")->execute(array(bin2hex(random_bytes(32))));

		return (string) $this->pdo->query("SELECT value FROM relay_secret WHERE name = 'csrf'")->fetchColumn();
	}

	/**
	 * @param int $status
	 * @param string $error
	 * @param string $description
	 * @return Response OAuth error as JSON.
	 */
	private static function error(int $status, string $error, string $description): Response
	{
		return new Response($status, array('error' => $error, 'error_description' => $description), null, array('Cache-Control' => 'no-store'));
	}

	/**
	 * @param int $status
	 * @param string $message
	 * @return Response A minimal HTML error page (never a redirect).
	 */
	private static function errorPage(int $status, string $message): Response
	{
		return Response::page($status, '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>Session relay</title><link rel="icon" href="data:,"><style>body{margin:0;padding:48px 16px;font:16px/1.5 system-ui,sans-serif;background:#f7f9fd;color:#313a45}'
			. '@media (prefers-color-scheme: dark){body{background:#161b22;color:#d9dee6}}main{max-width:560px;margin:0 auto}</style></head>'
			. '<body><main><h1>Cannot connect</h1><p>' . self::e($message) . '</p></main></body></html>');
	}

	/**
	 * @param string $text
	 * @return string HTML-escaped.
	 */
	private static function e(string $text): string
	{
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
	}
}
