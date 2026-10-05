<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/**
 * Toetst melden, bord, afmelden, verlopen en de botsingscheck.
 * @author Alice Hilverda
 * @date 05-10-2026
 */
final class SessieApiTest extends DbTestCase
{
	/**
	 * @return void
	 */
	public function testMeldenEnBord(): void
	{
		$alice = $this->persoon('Alice')['token'];
		$sessie = $this->meldAan($alice, 'Alice-App-A3F1', array('claim' => array('src/stores/userStore.ts'), 'ticket' => 'SYN-1'));

		self::assertSame('alice-app-a3f1', $sessie['naam']);
		self::assertSame('Alice', $sessie['persoon']);
		self::assertSame(array('src/stores/userStore.ts'), $sessie['claim']);
		self::assertSame('SYN-1', $sessie['ticket']);
		self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $sessie['sinds']);

		$bord = $this->verzoek('GET', '/bord', $alice)->data['sessies'];
		self::assertCount(1, $bord);
		self::assertSame('alice-app-a3f1', $bord[0]['naam']);
	}

	/**
	 * @return void
	 */
	public function testHartslagZonderClaimBehoudtClaim(): void
	{
		$alice = $this->persoon('Alice')['token'];
		$this->meldAan($alice, 'alice-1', array('claim' => array('src/a.ts')));
		$sessie = $this->meldAan($alice, 'alice-1', array('branch' => 'feature/x'));

		self::assertSame(array('src/a.ts'), $sessie['claim']);
		self::assertSame('feature/x', $sessie['branch']);
	}

	/**
	 * @return void
	 */
	public function testSessieVanAnderIs403(): void
	{
		$alice = $this->persoon('Alice')['token'];
		$bob = $this->persoon('Bob')['token'];
		$this->meldAan($alice, 'alice-1');

		$overname = $this->verzoek('POST', '/sessie', $bob, array('naam' => 'alice-1', 'machine' => 'x', 'repo' => 'app', 'repo_basis' => 'app'));
		self::assertSame(422, $overname->status);
		self::assertSame(403, $this->verzoek('DELETE', '/sessie/alice-1', $bob)->status);
	}

	/**
	 * @return void
	 */
	public function testAfmelden(): void
	{
		$alice = $this->persoon('Alice')['token'];
		$this->meldAan($alice, 'alice-1');

		self::assertSame(204, $this->verzoek('DELETE', '/sessie/alice-1', $alice)->status);
		self::assertSame(array(), $this->verzoek('GET', '/bord', $alice)->data['sessies']);
		self::assertSame(204, $this->verzoek('DELETE', '/sessie/alice-1', $alice)->status);
	}

	/**
	 * @return void
	 */
	public function testVerlopenSessieStaatNietOpBord(): void
	{
		$alice = $this->persoon('Alice')['token'];
		$this->meldAan($alice, 'alice-1');
		$this->pdo->exec("UPDATE sessie SET laatst_gezien = now() - interval '11 minutes'");

		self::assertSame(array(), $this->verzoek('GET', '/bord', $alice)->data['sessies']);
	}

	/**
	 * @return void
	 */
	public function testOngeldigeInvoerIs422(): void
	{
		$alice = $this->persoon('Alice')['token'];
		self::assertSame(422, $this->verzoek('POST', '/sessie', $alice, array('naam' => 'd 1', 'machine' => 'pc', 'repo' => 'app', 'repo_basis' => 'app'))->status);
		self::assertSame(422, $this->verzoek('POST', '/sessie', $alice, array('naam' => 'alice-1', 'machine' => 'pc', 'repo' => 'app'))->status);
		self::assertSame(422, $this->verzoek('POST', '/sessie', $alice, array('naam' => 'alice-1', 'machine' => 'pc', 'repo' => 'app', 'repo_basis' => 'app', 'claim' => 'src'))->status);
	}

	/**
	 * @return void
	 */
	public function testCheckBotstOpBranchVanAnder(): void
	{
		$alice = $this->persoon('Alice')['token'];
		$bob = $this->persoon('Bob')['token'];
		$this->meldAan($bob, 'bob-1');

		$botsing = $this->verzoek('POST', '/check', $alice, array('repo_basis' => 'APP', 'branch' => 'test', 'paden' => array()))->data['botsing'];
		self::assertSame(array(array('sessie' => 'bob-1', 'persoon' => 'Bob', 'reden' => 'is also on branch test')), $botsing);
	}

	/**
	 * @return void
	 */
	public function testCheckBotstOpClaimOverWorktreesHeen(): void
	{
		$alice = $this->persoon('Alice')['token'];
		$bob = $this->persoon('Bob')['token'];
		$this->meldAan($bob, 'bob-1', array('repo' => 'wt-x-app', 'branch' => 'feature/x', 'claim' => array('src/stores/')));

		$raak = $this->verzoek('POST', '/check', $alice, array('repo_basis' => 'app', 'branch' => 'test', 'paden' => array('src\\stores\\userStore.ts')))->data['botsing'];
		self::assertSame('claims src/stores/', $raak[0]['reden']);

		$vrij = $this->verzoek('POST', '/check', $alice, array('repo_basis' => 'app', 'branch' => 'test', 'paden' => array('src/pages/A.vue')))->data['botsing'];
		self::assertSame(array(), $vrij);
	}

	/**
	 * @return void
	 */
	public function testCheckNegeertEigenSessiesAndereReposEnVerlopen(): void
	{
		$alice = $this->persoon('Alice')['token'];
		$bob = $this->persoon('Bob')['token'];
		$this->meldAan($alice, 'alice-2');
		$this->meldAan($bob, 'bob-1', array('repo' => 'foundation', 'repo_basis' => 'foundation'));
		$this->meldAan($bob, 'bob-2');
		$this->pdo->exec("UPDATE sessie SET laatst_gezien = now() - interval '11 minutes' WHERE naam = 'bob-2'");

		$botsing = $this->verzoek('POST', '/check', $alice, array('repo_basis' => 'app', 'branch' => 'test', 'paden' => array()))->data['botsing'];
		self::assertSame(array(), $botsing);
	}

	/**
	 * @return void
	 */
	public function testNaamMoetMetEigenPersoonBeginnen(): void
	{
		$bob = $this->persoon('Bob')['token'];

		$vreemd = $this->verzoek('POST', '/sessie', $bob, array('naam' => 'alice-app-a3f1', 'machine' => 'x', 'repo' => 'app', 'repo_basis' => 'app'));
		self::assertSame(422, $vreemd->status);
		self::assertSame('bob-app-9b2c', $this->meldAan($bob, 'Bob-App-9B2C')['naam']);
	}
}
