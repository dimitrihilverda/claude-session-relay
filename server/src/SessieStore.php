<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/** Uses */

use PDO;
use Relay\Http\HttpError;

/**
 * Sessies: aanmelden (ook als hartslag), afmelden, het bord en de botsingscheck.
 * @author Dimitri Hilverda
 * @date 05-10-2026
 */
final class SessieStore
{
	const string LEVEND = "s.laatst_gezien > now() - interval '10 minutes'";

	const string KOLOMMEN = "s.naam, p.naam AS persoon, s.machine, s.repo, s.repo_basis, s.branch, s.ticket, s.claim,
		to_char(s.sinds AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS sinds,
		to_char(s.laatst_gezien AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS laatst_gezien";

	/**
	 * @param PDO $pdo
	 */
	public function __construct(private PDO $pdo)
	{
	}

	/**
	 * Meldt een sessie aan of werkt hem bij; zonder `claim` blijft de bestaande claim staan.
	 * @param array{id:int,naam:string} $persoon
	 * @param array<string, mixed> $in
	 * @return array<string, mixed>
	 * @throws HttpError
	 */
	public function meld(array $persoon, array $in): array
	{
		//Validate input:
		$naam = Invoer::naam($in, 'naam');
		$machine = Invoer::tekst($in, 'machine', 200);
		$repo = Invoer::tekst($in, 'repo', 200);
		$repoBasis = Invoer::tekst($in, 'repo_basis', 200);
		$branch = Invoer::tekst($in, 'branch', 200, false);
		$ticket = Invoer::tekst($in, 'ticket', 100, false);
		$claimGezet = array_key_exists('claim', $in);
		$claim = $claimGezet === true ? Invoer::padLijst($in, 'claim') : array();

		//A session name always starts with its person, so nobody can take over another's name:
		$prefix = strtolower($persoon['naam']) . '-';
		if(str_starts_with($naam, $prefix) === false) {
			throw new HttpError(422, "Session name must start with $prefix.");
		}
		$this->eigenaar($persoon, $naam, true);

		//Upsert:
		$st = $this->pdo->prepare(
			'INSERT INTO sessie (naam, persoon_id, machine, repo, repo_basis, branch, ticket, claim)
			 VALUES (:naam, :persoon, :machine, :repo, :repo_basis, :branch, :ticket, CAST(:claim AS jsonb))
			 ON CONFLICT (naam) DO UPDATE SET machine = EXCLUDED.machine, repo = EXCLUDED.repo,
				repo_basis = EXCLUDED.repo_basis, branch = EXCLUDED.branch, ticket = EXCLUDED.ticket,
				claim = CASE WHEN CAST(:claim_gezet AS boolean) THEN EXCLUDED.claim ELSE sessie.claim END,
				laatst_gezien = now()'
		);
		$st->execute(array(
			'naam' => $naam,
			'persoon' => $persoon['id'],
			'machine' => $machine,
			'repo' => $repo,
			'repo_basis' => $repoBasis,
			'branch' => $branch,
			'ticket' => $ticket === '' ? null : $ticket,
			'claim' => json_encode($claim, JSON_THROW_ON_ERROR),
			'claim_gezet' => $claimGezet === true ? 'true' : 'false',
		));

		return $this->haal($naam);
	}

	/**
	 * Werkt `laatst_gezien` bij.
	 * @param array{id:int,naam:string} $persoon
	 * @param string $naam
	 * @return void
	 * @throws HttpError 404 als de sessie niet bestaat, 403 als hij van een ander is.
	 */
	public function hartslag(array $persoon, string $naam): void
	{
		$naam = Invoer::naam(array('sessie' => $naam), 'sessie');
		$this->eigenaar($persoon, $naam, false);
		$this->pdo->prepare('UPDATE sessie SET laatst_gezien = now() WHERE naam = ?')->execute(array($naam));
	}

	/**
	 * @param array{id:int,naam:string} $persoon
	 * @param string $naam
	 * @return void
	 * @throws HttpError
	 */
	public function meldAf(array $persoon, string $naam): void
	{
		$naam = strtolower($naam);
		$this->eigenaar($persoon, $naam, true);
		$this->pdo->prepare('DELETE FROM sessie WHERE naam = ?')->execute(array($naam));
	}

	/**
	 * @return list<array<string, mixed>> Alle levende sessies van iedereen.
	 */
	public function bord(): array
	{
		$rijen = $this->pdo->query(
			'SELECT ' . self::KOLOMMEN . ' FROM sessie s JOIN persoon p ON p.id = s.persoon_id
			 WHERE ' . self::LEVEND . ' ORDER BY p.naam, s.naam'
		)->fetchAll();

		return array_map(array($this, 'formatteer'), $rijen);
	}

	/**
	 * @param array{id:int,naam:string} $persoon
	 * @param array<string, mixed> $in repo_basis, branch, paden
	 * @return list<array{sessie:string,persoon:string,reden:string}>
	 * @throws HttpError
	 */
	public function check(array $persoon, array $in): array
	{
		//Validate input:
		$repoBasis = Invoer::tekst($in, 'repo_basis', 200);
		$ik = array('branch' => Invoer::tekst($in, 'branch', 200, false), 'paden' => Invoer::padLijst($in, 'paden'));

		//Living sessions of others in the same underlying repo:
		$st = $this->pdo->prepare(
			'SELECT s.naam, p.naam AS persoon, s.branch, s.claim FROM sessie s JOIN persoon p ON p.id = s.persoon_id
			 WHERE ' . self::LEVEND . ' AND s.persoon_id <> :persoon AND lower(s.repo_basis) = lower(:repo_basis)
			 ORDER BY s.naam'
		);
		$st->execute(array('persoon' => $persoon['id'], 'repo_basis' => $repoBasis));

		$botsingen = array();
		foreach($st->fetchAll() as $rij) {
			$reden = Botsing::reden($ik, array('branch' => (string) $rij['branch'], 'claim' => json_decode((string) $rij['claim'], true)));
			if($reden !== null) {
				$botsingen[] = array('sessie' => (string) $rij['naam'], 'persoon' => (string) $rij['persoon'], 'reden' => $reden);
			}
		}

		return $botsingen;
	}

	/**
	 * @param array{id:int,naam:string} $persoon
	 * @param string $naam
	 * @param bool $magOntbreken
	 * @return void
	 * @throws HttpError
	 */
	private function eigenaar(array $persoon, string $naam, bool $magOntbreken): void
	{
		$st = $this->pdo->prepare('SELECT persoon_id FROM sessie WHERE naam = ?');
		$st->execute(array($naam));
		$eigenaar = $st->fetchColumn();
		if($eigenaar === false) {
			if($magOntbreken === false) {
				throw new HttpError(404, "Session $naam is unknown or expired; register again.");
			}

			return;
		}
		if((int) $eigenaar !== $persoon['id']) {
			throw new HttpError(403, "Session $naam belongs to someone else.");
		}
	}

	/**
	 * @param string $naam
	 * @return array<string, mixed>
	 */
	private function haal(string $naam): array
	{
		$st = $this->pdo->prepare('SELECT ' . self::KOLOMMEN . ' FROM sessie s JOIN persoon p ON p.id = s.persoon_id WHERE s.naam = ?');
		$st->execute(array($naam));

		return $this->formatteer($st->fetch());
	}

	/**
	 * @param array<string, mixed> $rij
	 * @return array<string, mixed>
	 */
	private function formatteer(array $rij): array
	{
		$rij['claim'] = json_decode((string) $rij['claim'], true);

		return $rij;
	}
}
