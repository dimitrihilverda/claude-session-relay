<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/** Uses */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Relay\Botsing;

/**
 * Toetst padoverlap en botsingsredenen (zonder database).
 * @author Alice Hilverda
 * @date 05-10-2026
 */
final class BotsingTest extends TestCase
{
	/**
	 * @return array<string, array{string, string, bool}>
	 */
	public static function paren(): array
	{
		return array(
			'gelijk' => array('src/a.ts', 'src/a.ts', true),
			'map bevat bestand' => array('src/stores/', 'src/stores/userStore.ts', true),
			'map zonder slash' => array('src/stores', 'src/stores/userStore.ts', true),
			'omgekeerd' => array('src/stores/userStore.ts', 'src/stores', true),
			'geen prefix van naam' => array('src/stores', 'src/storesX.ts', false),
			'backslashes' => array('src\\stores\\userStore.ts', 'src/stores/userStore.ts', true),
			'punt-slash' => array('./src/a.ts', 'src/a.ts', true),
			'hoofdletters' => array('SRC/Stores/x.ts', 'src/stores/x.ts', true),
			'hele repo punt' => array('.', 'src/a.ts', true),
			'hele repo ster' => array('*', 'README.md', true),
			'leeg' => array('', 'src/a.ts', false),
			'anders' => array('src/a.ts', 'src/b.ts', false),
		);
	}

	/**
	 * @param string $a
	 * @param string $b
	 * @param bool $verwacht
	 * @return void
	 */
	#[DataProvider('paren')]
	public function testPadenOverlappen(string $a, string $b, bool $verwacht): void
	{
		self::assertSame($verwacht, Botsing::padenOverlappen($a, $b));
	}

	/**
	 * @return void
	 */
	public function testRedenBijZelfdeBranch(): void
	{
		$reden = Botsing::reden(array('branch' => 'test', 'paden' => array()), array('branch' => 'test', 'claim' => array()));
		self::assertSame('is also on branch test', $reden);
	}

	/**
	 * @return void
	 */
	public function testRedenBijClaimEnBranch(): void
	{
		$reden = Botsing::reden(
			array('branch' => 'test', 'paden' => array('src/stores/userStore.ts')),
			array('branch' => 'test', 'claim' => array('src/stores/', 'docs/'))
		);
		self::assertSame('is also on branch test and claims src/stores/', $reden);
	}

	/**
	 * @return void
	 */
	public function testLegeBranchBotstNooit(): void
	{
		self::assertNull(Botsing::reden(array('branch' => '', 'paden' => array()), array('branch' => '', 'claim' => array())));
	}

	/**
	 * @return void
	 */
	public function testGeenBotsing(): void
	{
		self::assertNull(Botsing::reden(
			array('branch' => 'feature/a', 'paden' => array('src/a.ts')),
			array('branch' => 'feature/b', 'claim' => array('src/b.ts'))
		));
	}
}
