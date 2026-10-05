<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/** Uses */

use PDO;
use Relay\Http\HttpError;

/**
 * Berichten tussen sessies: sturen en de inbox (gelezen per ontvangende sessie).
 * @author Dimitri Hilverda
 * @date 05-10-2026
 */
final class BerichtStore
{
	const array SOORTEN = array('melding', 'vraag', 'antwoord');

	/**
	 * @param PDO $pdo
	 */
	public function __construct(private PDO $pdo)
	{
	}

	/**
	 * @param array{id:int,naam:string} $persoon
	 * @param array<string, mixed> $in
	 * @return int Id van het nieuwe bericht.
	 * @throws HttpError
	 */
	public function stuur(array $persoon, array $in): int
	{
		//Validate input:
		$van = Invoer::naam($in, 'van');
		(new SessieStore($this->pdo))->hartslag($persoon, $van);
		$soort = Invoer::tekst($in, 'soort', 20);
		if(in_array($soort, self::SOORTEN, true) === false) {
			throw new HttpError(422, 'Field soort must be melding, vraag or antwoord.');
		}
		$tekst = Invoer::tekst($in, 'tekst', 4000);

		//Resolve the recipient:
		$antwoordOp = null;
		$aanPersoon = null;
		if($soort === 'antwoord') {
			$antwoordOp = (int) ($in['antwoord_op'] ?? 0);
			$aanSessie = $this->afzenderVanVraag($persoon, $van, $antwoordOp);
		} else {
			[$aanSessie, $aanPersoon] = $this->ontvanger(Invoer::tekst($in, 'aan', 80));
		}

		//Store:
		$st = $this->pdo->prepare(
			'INSERT INTO bericht (van_sessie, van_persoon_id, aan_sessie, aan_persoon_id, soort, tekst, antwoord_op)
			 VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id'
		);
		$st->execute(array($van, $persoon['id'], $aanSessie, $aanPersoon, $soort, $tekst, $antwoordOp));

		return (int) $st->fetchColumn();
	}

	/**
	 * Ongelezen berichten voor deze sessie; markeert ze direct als gelezen.
	 * @param array{id:int,naam:string} $persoon
	 * @param string $sessie Al gecontroleerd door SessieStore::hartslag().
	 * @return list<array<string, mixed>>
	 */
	public function inbox(array $persoon, string $sessie): array
	{
		$sessie = strtolower($sessie);
		$this->pdo->beginTransaction();

		//Unread messages to this session, or to this person (max 1 hour old, not sent by me):
		$st = $this->pdo->prepare(
			"SELECT b.id, b.soort, b.van_sessie AS van, vp.naam AS van_persoon, b.tekst, b.antwoord_op,
				to_char(b.aangemaakt AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS aangemaakt
			 FROM bericht b JOIN persoon vp ON vp.id = b.van_persoon_id
			 WHERE (b.aan_sessie = :sessie
				OR (b.aan_persoon_id = :persoon AND b.aangemaakt > now() - interval '1 hour' AND b.van_sessie <> :afzender))
			 AND NOT EXISTS (SELECT 1 FROM bericht_gelezen g WHERE g.bericht_id = b.id AND g.sessie = :gelezen)
			 AND b.aangemaakt >= (SELECT s.sinds FROM sessie s WHERE s.naam = :sinds)
			 ORDER BY b.id"
		);
		$st->execute(array('sessie' => $sessie, 'persoon' => $persoon['id'], 'afzender' => $sessie, 'gelezen' => $sessie, 'sinds' => $sessie));
		$berichten = $st->fetchAll();

		//Mark as read:
		$markeer = $this->pdo->prepare('INSERT INTO bericht_gelezen (bericht_id, sessie) VALUES (?, ?) ON CONFLICT DO NOTHING');
		foreach($berichten as &$bericht) {
			$markeer->execute(array($bericht['id'], $sessie));
			$bericht['id'] = (int) $bericht['id'];
			$bericht['antwoord_op'] = $bericht['antwoord_op'] === null ? null : (int) $bericht['antwoord_op'];
		}
		unset($bericht);
		$this->pdo->commit();

		return $berichten;
	}

	/**
	 * @param string $aan Sessienaam of persoonsnaam.
	 * @return array{0:string|null,1:int|null} [aan_sessie, aan_persoon_id]
	 * @throws HttpError
	 */
	private function ontvanger(string $aan): array
	{
		//A living session:
		$st = $this->pdo->prepare('SELECT s.naam FROM sessie s WHERE s.naam = lower(?) AND ' . SessieStore::LEVEND);
		$st->execute(array($aan));
		$sessie = $st->fetchColumn();
		if($sessie !== false) {
			return array((string) $sessie, null);
		}

		//Or an active person:
		$st = $this->pdo->prepare('SELECT id FROM persoon WHERE lower(naam) = lower(?) AND actief');
		$st->execute(array($aan));
		$persoonId = $st->fetchColumn();
		if($persoonId !== false) {
			return array(null, (int) $persoonId);
		}

		throw new HttpError(404, "No active session or person named $aan.");
	}

	/**
	 * @param array{id:int,naam:string} $persoon
	 * @param string $van Mijn sessie.
	 * @param int $vraagId
	 * @return string De sessie die de vraag stelde.
	 * @throws HttpError
	 */
	private function afzenderVanVraag(array $persoon, string $van, int $vraagId): string
	{
		$st = $this->pdo->prepare('SELECT van_sessie, aan_sessie, aan_persoon_id FROM bericht WHERE id = ?');
		$st->execute(array($vraagId));
		$vraag = $st->fetch();
		if($vraag === false) {
			throw new HttpError(404, "Message $vraagId does not exist.");
		}
		if($vraag['aan_sessie'] !== $van && (int) $vraag['aan_persoon_id'] !== $persoon['id']) {
			throw new HttpError(403, "Message $vraagId was not addressed to you.");
		}

		return (string) $vraag['van_sessie'];
	}
}
