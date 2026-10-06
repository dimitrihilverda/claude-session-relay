<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/** Uses */

use PDO;
use Relay\Http\HttpError;
use Relay\Http\Request;
use Relay\Http\Response;
use Throwable;

/**
 * Routes a request to the right store and turns errors into JSON.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class App
{
	/**
	 * @param PDO $pdo
	 * @param int $maxWait Longest long-poll in seconds.
	 * @param string $publicUrl Public base URL (config public_url); '' = the OAuth/MCP endpoints are off (503).
	 */
	public function __construct(private PDO $pdo, private int $maxWait = 25, private string $publicUrl = '')
	{
	}

	/**
	 * @param Request $request
	 * @return Response
	 */
	public function handle(Request $request): Response
	{
		try {
			return $this->route($request);
		} catch(HttpError $error) {
			return new Response($error->status, array('error' => $error->getMessage()));
		} catch(Throwable $error) {
			if($this->pdo->inTransaction() === true) {
				$this->pdo->rollBack();
			}
			error_log('session-relay: ' . $error);

			return new Response(500, array('error' => 'Internal error.'));
		}
	}

	/**
	 * @param Request $r
	 * @return Response
	 * @throws HttpError
	 */
	private function route(Request $r): Response
	{
		//Bodies over 1 MB are never parsed:
		if($r->tooLarge === true) {
			throw new HttpError(413, 'Request body too large.');
		}

		//Health check and the board page need no token (the page asks for one itself):
		if($r->method === 'GET' && $r->path === '/health') {
			return new Response(200, array('status' => 'ok'));
		}
		if($r->method === 'GET' && ($r->path === '/' || $r->path === '/index.php')) {
			return Response::page(200, (string) file_get_contents(__DIR__ . '/Board.html'));
		}

		//OAuth and MCP for the connector; their metadata must be built from the configured public URL:
		$connector = in_array($r->path, array('/mcp', '/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp', '/.well-known/oauth-authorization-server'), true) === true
			|| str_starts_with($r->path, '/oauth/') === true;
		if($connector === true) {
			if($this->publicUrl === '') {
				throw new HttpError(503, 'public_url is not configured');
			}

			return $this->connector($r, new OAuthServer($this->pdo, rtrim($this->publicUrl, '/')));
		}

		//Authenticate:
		$person = (new Auth($this->pdo))->personForToken($r->token);
		if($person === null) {
			throw new HttpError(401, 'Invalid or revoked token.');
		}
		if($r->body === null) {
			throw new HttpError(422, 'Body is not valid JSON.');
		}

		//Routes:
		$sessions = new SessionStore($this->pdo);
		$messages = new MessageStore($this->pdo);
		if($r->method === 'GET' && $r->path === '/me') {
			return new Response(200, array('person' => $person['name'], 'teams' => (new TeamStore($this->pdo))->teamsOf($person['id'])));
		}
		if($r->method === 'POST' && $r->path === '/session') {
			return new Response(200, array('session' => $sessions->register($person, $r->body)));
		}
		if($r->method === 'DELETE' && preg_match('#^/session/([^/]+)$#', $r->path, $m) === 1) {
			$sessions->unregister($person, rawurldecode($m[1]));

			return new Response(204);
		}
		if($r->method === 'GET' && $r->path === '/board') {
			return new Response(200, array('sessions' => $sessions->board($person, Input::text($r->query, 'team', 80, false))));
		}
		if($r->method === 'POST' && $r->path === '/check') {
			return new Response(200, array('conflicts' => $sessions->check($person, $r->body)));
		}
		if($r->method === 'POST' && $r->path === '/message') {
			return new Response(201, array('id' => $messages->send($person, $r->body)));
		}
		if($r->method === 'GET' && $r->path === '/inbox') {
			return new Response(200, array('messages' => $this->inbox($person, $r->query, $messages)));
		}

		throw new HttpError(404, 'Unknown endpoint.');
	}

	/**
	 * The OAuth endpoints (no relay token; the consent page asks for it) and /mcp.
	 * @param Request $r
	 * @param OAuthServer $oauth
	 * @return Response
	 * @throws HttpError
	 */
	private function connector(Request $r, OAuthServer $oauth): Response
	{
		if($r->method === 'GET' && ($r->path === '/.well-known/oauth-protected-resource' || $r->path === '/.well-known/oauth-protected-resource/mcp')) {
			return $oauth->protectedResource();
		}
		if($r->method === 'GET' && $r->path === '/.well-known/oauth-authorization-server') {
			return $oauth->authorizationServer();
		}
		if($r->method === 'POST' && $r->path === '/oauth/register') {
			return $oauth->register($r->body, $r->ip);
		}
		if(($r->method === 'GET' || $r->method === 'POST') && $r->path === '/oauth/authorize') {
			return $oauth->authorize($r);
		}
		if($r->method === 'POST' && $r->path === '/oauth/token') {
			return $oauth->token($r->form);
		}
		if($r->path !== '/mcp') {
			throw new HttpError(404, 'Unknown endpoint.');
		}

		//MCP: a browser page of another site may not call it (DNS rebinding, cross-site requests):
		if($r->origin !== '' && $r->origin !== $oauth->origin()) {
			throw new HttpError(403, 'Origin not allowed.');
		}
		if($r->method !== 'POST') {
			return new Response(405, array(), null, array('Allow' => 'POST'));
		}

		//An OAuth access token, or a relay token (e.g. Claude Code with a header):
		$person = (new OAuthStore($this->pdo))->personForAccessToken($r->token) ?? (new Auth($this->pdo))->personForToken($r->token);
		if($person === null) {
			return new Response(401, array('error' => 'Invalid or revoked token.'), null, array('WWW-Authenticate' => $oauth->challengeHeader()));
		}

		return (new Mcp($this->pdo, $this->maxWait))->handle($person, $r->body);
	}

	/**
	 * Inbox with long-poll: on an empty inbox keep asking for at most `wait` seconds.
	 * @param array{id:int,name:string} $person
	 * @param array<string, mixed> $query session, wait
	 * @param MessageStore $messages
	 * @return list<array<string, mixed>>
	 * @throws HttpError
	 */
	private function inbox(array $person, array $query, MessageStore $messages): array
	{
		$wait = max(0, min($this->maxWait, (int) ($query['wait'] ?? 0)));

		return $messages->poll($person, Input::text($query, 'session', 80), $wait);
	}
}
