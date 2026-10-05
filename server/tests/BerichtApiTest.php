<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/**
 * Toetst berichten sturen, inbox, antwoorden en long-poll.
 * @author Alice Hilverda
 * @date 05-10-2026
 */
final class BerichtApiTest extends DbTestCase
{
	private string $alice;

	private string $bob;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		$this->alice = $this->persoon('Alice')['token'];
		$this->bob = $this->persoon('Bob')['token'];
		$this->meldAan($this->alice, 'alice-1');
		$this->meldAan($this->bob, 'bob-1');
	}

	/**
	 * @param string $token
	 * @param array<string, mixed> $body
	 * @return int
	 */
	private function stuur(string $token, array $body): int
	{
		$antwoord = $this->verzoek('POST', '/bericht', $token, $body);
		self::assertSame(201, $antwoord->status, (string) json_encode($antwoord->data));

		return $antwoord->data['id'];
	}

	/**
	 * @param string $token
	 * @param string $sessie
	 * @param int $wacht
	 * @return list<array<string, mixed>>
	 */
	private function inbox(string $token, string $sessie, int $wacht = 0): array
	{
		$antwoord = $this->verzoek('GET', '/inbox', $token, array(), array('sessie' => $sessie, 'wacht' => (string) $wacht));
		self::assertSame(200, $antwoord->status, (string) json_encode($antwoord->data));

		return $antwoord->data['berichten'];
	}

	/**
	 * @return void
	 */
	public function testMeldingAanSessieKomtEenKeerAan(): void
	{
		$id = $this->stuur($this->alice, array('van' => 'alice-1', 'aan' => 'Bob-1', 'soort' => 'melding', 'tekst' => 'Ik push één keer naar test'));

		$berichten = $this->inbox($this->bob, 'bob-1');
		self::assertCount(1, $berichten);
		self::assertSame($id, $berichten[0]['id']);
		self::assertSame('alice-1', $berichten[0]['van']);
		self::assertSame('Alice', $berichten[0]['van_persoon']);
		self::assertSame('Ik push één keer naar test', $berichten[0]['tekst']);
		self::assertSame(array(), $this->inbox($this->bob, 'bob-1'));
	}

	/**
	 * @return void
	 */
	public function testVraagEnAntwoord(): void
	{
		$vraag = $this->stuur($this->alice, array('van' => 'alice-1', 'aan' => 'bob-1', 'soort' => 'vraag', 'tekst' => 'Klaar met UserEditDialog?'));
		$this->inbox($this->bob, 'bob-1');
		$this->stuur($this->bob, array('van' => 'bob-1', 'soort' => 'antwoord', 'antwoord_op' => $vraag, 'tekst' => 'Ja'));

		$berichten = $this->inbox($this->alice, 'alice-1');
		self::assertSame('antwoord', $berichten[0]['soort']);
		self::assertSame($vraag, $berichten[0]['antwoord_op']);
	}

	/**
	 * @return void
	 */
	public function testAntwoordAlleenOpEigenBericht(): void
	{
		$this->meldAan($this->alice, 'alice-2');
		$vraag = $this->stuur($this->alice, array('van' => 'alice-1', 'aan' => 'alice-2', 'soort' => 'vraag', 'tekst' => 'x'));

		$antwoord = $this->verzoek('POST', '/bericht', $this->bob, array('van' => 'bob-1', 'soort' => 'antwoord', 'antwoord_op' => $vraag, 'tekst' => 'y'));
		self::assertSame(403, $antwoord->status);
	}

	/**
	 * @return void
	 */
	public function testAanPersoonBereiktAlleSessiesBehalveAfzender(): void
	{
		$this->meldAan($this->bob, 'bob-2');
		$this->stuur($this->bob, array('van' => 'bob-1', 'aan' => 'bob', 'soort' => 'melding', 'tekst' => 'eigen'));
		$this->stuur($this->alice, array('van' => 'alice-1', 'aan' => 'Bob', 'soort' => 'melding', 'tekst' => 'aan allen'));

		self::assertSame(array('aan allen'), array_column($this->inbox($this->bob, 'bob-1'), 'tekst'));
		self::assertSame(array('eigen', 'aan allen'), array_column($this->inbox($this->bob, 'bob-2'), 'tekst'));
	}

	/**
	 * @return void
	 */
	public function testAanPersoonVervaltNaEenUur(): void
	{
		$this->stuur($this->alice, array('van' => 'alice-1', 'aan' => 'Bob', 'soort' => 'melding', 'tekst' => 'alice-oud'));
		$this->pdo->exec("UPDATE bericht SET aangemaakt = now() - interval '61 minutes'");

		self::assertSame(array(), $this->inbox($this->bob, 'bob-1'));
	}

	/**
	 * @return void
	 */
	public function testOnbekendeOntvangerIs404(): void
	{
		$this->pdo->exec("UPDATE sessie SET laatst_gezien = now() - interval '11 minutes' WHERE naam = 'bob-1'");
		$antwoord = $this->verzoek('POST', '/bericht', $this->alice, array('van' => 'alice-1', 'aan' => 'bob-1', 'soort' => 'melding', 'tekst' => 'x'));
		self::assertSame(404, $antwoord->status);
		self::assertSame(404, $this->verzoek('POST', '/bericht', $this->alice, array('van' => 'alice-1', 'aan' => 'niemand', 'soort' => 'melding', 'tekst' => 'x'))->status);
	}

	/**
	 * @return void
	 */
	public function testOngeldigBerichtIs422(): void
	{
		self::assertSame(422, $this->verzoek('POST', '/bericht', $this->alice, array('van' => 'alice-1', 'aan' => 'bob-1', 'soort' => 'roddel', 'tekst' => 'x'))->status);
		self::assertSame(422, $this->verzoek('POST', '/bericht', $this->alice, array('van' => 'alice-1', 'aan' => 'bob-1', 'soort' => 'melding', 'tekst' => str_repeat('a', 4001)))->status);
	}

	/**
	 * @return void
	 */
	public function testInboxVanAndermansSessieIs403EnOnbekendIs404(): void
	{
		self::assertSame(403, $this->verzoek('GET', '/inbox', $this->alice, array(), array('sessie' => 'bob-1'))->status);
		self::assertSame(404, $this->verzoek('GET', '/inbox', $this->alice, array(), array('sessie' => 'alice-9'))->status);
	}

	/**
	 * @return void
	 */
	public function testInboxIsHartslag(): void
	{
		$this->pdo->exec("UPDATE sessie SET laatst_gezien = now() - interval '9 minutes' WHERE naam = 'bob-1'");
		$this->inbox($this->bob, 'bob-1');

		$leeftijd = (int) $this->pdo->query("SELECT extract(epoch FROM now() - laatst_gezien) FROM sessie WHERE naam = 'bob-1'")->fetchColumn();
		self::assertLessThan(5, $leeftijd);
	}

	/**
	 * @return void
	 */
	public function testLongPollWachtBijLegeInbox(): void
	{
		$start = microtime(true);
		self::assertSame(array(), $this->inbox($this->bob, 'bob-1', 2));
		self::assertGreaterThanOrEqual(1.9, microtime(true) - $start);

		$this->stuur($this->alice, array('van' => 'alice-1', 'aan' => 'bob-1', 'soort' => 'melding', 'tekst' => 'x'));
		$start = microtime(true);
		self::assertCount(1, $this->inbox($this->bob, 'bob-1', 2));
		self::assertLessThan(0.5, microtime(true) - $start);
	}

	/**
	 * @return void
	 */
	public function testNieuweSessieMetZelfdeNaamZietOudeBerichtenNiet(): void
	{
		$this->stuur($this->alice, array('van' => 'alice-1', 'aan' => 'bob-1', 'soort' => 'vraag', 'tekst' => 'voor de oude sessie'));
		$this->pdo->exec("UPDATE bericht SET aangemaakt = now() - interval '1 minute'");
		$this->verzoek('DELETE', '/sessie/bob-1', $this->bob);
		$this->meldAan($this->bob, 'bob-1');

		self::assertSame(array(), $this->inbox($this->bob, 'bob-1'));
	}
}
