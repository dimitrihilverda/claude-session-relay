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
	 */
	public function __construct(private PDO $pdo, private int $maxWait = 25)
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
		//Health check and the board page need no token (the page asks for one itself):
		if($r->method === 'GET' && $r->path === '/health') {
			return new Response(200, array('status' => 'ok'));
		}
		if($r->method === 'GET' && ($r->path === '/' || $r->path === '/index.php')) {
			return new Response(200, array(), (string) file_get_contents(__DIR__ . '/Board.html'));
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
			return new Response(200, array('messages' => $this->inbox($person, $r->query, $sessions, $messages)));
		}

		throw new HttpError(404, 'Unknown endpoint.');
	}

	/**
	 * Inbox with long-poll: on an empty inbox keep asking for at most `wait` seconds.
	 * @param array{id:int,name:string} $person
	 * @param array<string, mixed> $query session, wait
	 * @param SessionStore $sessions
	 * @param MessageStore $messages
	 * @return list<array<string, mixed>>
	 * @throws HttpError
	 */
	private function inbox(array $person, array $query, SessionStore $sessions, MessageStore $messages): array
	{
		//Heartbeat (also checks that the session is mine):
		$session = $sessions->heartbeat($person, Input::text($query, 'session', 80));

		//Poll once per second until something arrives or time is up:
		$wait = max(0, min($this->maxWait, (int) ($query['wait'] ?? 0)));
		$end = microtime(true) + $wait;
		while(true) {
			$list = $messages->inbox($person, $session['name']);
			if($list !== array() || microtime(true) >= $end) {
				return $list;
			}
			usleep(1000000);
		}
	}
}
