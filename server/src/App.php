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
 * Routeert een verzoek naar de juiste store en vertaalt fouten naar JSON.
 * @author Dimitri Hilverda
 * @date 05-10-2026
 */
final class App
{
	/**
	 * @param PDO $pdo
	 * @param int $maxWacht Maximale long-poll in seconden.
	 */
	public function __construct(private PDO $pdo, private int $maxWacht = 25)
	{
	}

	/**
	 * @param Request $verzoek
	 * @return Response
	 */
	public function handle(Request $verzoek): Response
	{
		try {
			return $this->route($verzoek);
		} catch(HttpError $fout) {
			return new Response($fout->status, array('fout' => $fout->getMessage()));
		} catch(Throwable $fout) {
			error_log('sessie-relay: ' . $fout);

			return new Response(500, array('fout' => 'Internal error.'));
		}
	}

	/**
	 * @param Request $v
	 * @return Response
	 * @throws HttpError
	 */
	private function route(Request $v): Response
	{
		//Health check and the board page need no token (the page asks for one itself):
		if($v->methode === 'GET' && $v->pad === '/gezond') {
			return new Response(200, array('status' => 'ok'));
		}
		if($v->methode === 'GET' && ($v->pad === '/' || $v->pad === '/index.php')) {
			return new Response(200, array(), (string) file_get_contents(__DIR__ . '/Board.html'));
		}

		//Authenticate:
		$persoon = (new Auth($this->pdo))->persoonVoorToken($v->token);
		if($persoon === null) {
			throw new HttpError(401, 'Invalid or revoked token.');
		}
		if($v->body === null) {
			throw new HttpError(422, 'Body is not valid JSON.');
		}

		//Routes:
		$sessies = new SessieStore($this->pdo);
		if($v->methode === 'POST' && $v->pad === '/sessie') {
			return new Response(200, array('sessie' => $sessies->meld($persoon, $v->body)));
		}
		if($v->methode === 'DELETE' && preg_match('#^/sessie/([^/]+)$#', $v->pad, $m) === 1) {
			$sessies->meldAf($persoon, rawurldecode($m[1]));

			return new Response(204);
		}
		if($v->methode === 'GET' && $v->pad === '/bord') {
			return new Response(200, array('sessies' => $sessies->bord()));
		}
		if($v->methode === 'POST' && $v->pad === '/check') {
			return new Response(200, array('botsing' => $sessies->check($persoon, $v->body)));
		}
		$berichten = new BerichtStore($this->pdo);
		if($v->methode === 'POST' && $v->pad === '/bericht') {
			return new Response(201, array('id' => $berichten->stuur($persoon, $v->body)));
		}
		if($v->methode === 'GET' && $v->pad === '/inbox') {
			return new Response(200, array('berichten' => $this->inbox($persoon, $v->query, $sessies, $berichten)));
		}

		throw new HttpError(404, "Unknown endpoint {$v->methode} {$v->pad}.");
	}

	/**
	 * Inbox met long-poll: bij een lege inbox maximaal `wacht` seconden blijven vragen.
	 * @param array{id:int,naam:string} $persoon
	 * @param array<string, mixed> $query sessie, wacht
	 * @param SessieStore $sessies
	 * @param BerichtStore $berichten
	 * @return list<array<string, mixed>>
	 * @throws HttpError
	 */
	private function inbox(array $persoon, array $query, SessieStore $sessies, BerichtStore $berichten): array
	{
		//Heartbeat (also validates that the session is mine):
		$sessie = (string) ($query['sessie'] ?? '');
		$sessies->hartslag($persoon, $sessie);

		//Poll once per second until something arrives or time is up:
		$wacht = max(0, min($this->maxWacht, (int) ($query['wacht'] ?? 0)));
		$eind = microtime(true) + $wacht;
		while(true) {
			$lijst = $berichten->inbox($persoon, $sessie);
			if($lijst !== array() || microtime(true) >= $eind) {
				return $lijst;
			}
			usleep(1000000);
		}
	}
}
