<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/** Uses */

use Relay\Opruimer;

/**
 * Toetst het opruimen van oude sessies en berichten.
 * @author Alice Hilverda
 * @date 05-10-2026
 */
final class OpruimerTest extends DbTestCase
{
	/**
	 * @return void
	 */
	public function testRuimtAlleenOudeRijenOp(): void
	{
		$alice = $this->persoon('Alice')['token'];
		$this->meldAan($alice, 'alice-oud');
		$this->meldAan($alice, 'alice-nieuw');
		$this->verzoek('POST', '/bericht', $alice, array('van' => 'alice-nieuw', 'aan' => 'alice-oud', 'soort' => 'melding', 'tekst' => 'x'));
		$this->verzoek('POST', '/bericht', $alice, array('van' => 'alice-nieuw', 'aan' => 'alice-oud', 'soort' => 'melding', 'tekst' => 'y'));
		$this->pdo->exec("UPDATE sessie SET laatst_gezien = now() - interval '25 hours' WHERE naam = 'alice-oud'");
		$this->pdo->exec("UPDATE bericht SET aangemaakt = now() - interval '31 days' WHERE tekst = 'x'");

		self::assertSame(array('sessies' => 1, 'berichten' => 1), (new Opruimer($this->pdo))->ruimOp());
		self::assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM sessie')->fetchColumn());
		self::assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM bericht')->fetchColumn());
	}
}
