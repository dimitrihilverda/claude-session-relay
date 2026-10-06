<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/** Uses */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Relay\Conflict;

/**
 * Tests path overlap and conflict reasons (no database).
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class ConflictTest extends TestCase
{
	/**
	 * @return array<string, array{string, string, bool}>
	 */
	public static function pairs(): array
	{
		return array(
			'equal' => array('src/a.ts', 'src/a.ts', true),
			'folder contains file' => array('src/stores/', 'src/stores/userStore.ts', true),
			'folder without slash' => array('src/stores', 'src/stores/userStore.ts', true),
			'reversed' => array('src/stores/userStore.ts', 'src/stores', true),
			'not a name prefix' => array('src/stores', 'src/storesX.ts', false),
			'backslashes' => array('src\\stores\\userStore.ts', 'src/stores/userStore.ts', true),
			'dot slash' => array('./src/a.ts', 'src/a.ts', true),
			'upper case' => array('SRC/Stores/x.ts', 'src/stores/x.ts', true),
			'whole repo dot' => array('.', 'src/a.ts', true),
			'whole repo star' => array('*', 'README.md', true),
			'empty' => array('', 'src/a.ts', false),
			'different' => array('src/a.ts', 'src/b.ts', false),
		);
	}

	/**
	 * @param string $a
	 * @param string $b
	 * @param bool $expected
	 * @return void
	 */
	#[DataProvider('pairs')]
	public function testPathsOverlap(string $a, string $b, bool $expected): void
	{
		self::assertSame($expected, Conflict::pathsOverlap($a, $b));
	}

	/**
	 * @return void
	 */
	public function testOverlapKeepsClaimOrderAndDuplicates(): void
	{
		self::assertSame(
			array('src/stores/', 'docs', 'SRC/a.ts', '.'),
			Conflict::overlap(array('src/stores/', 'lib/', 'docs', 'SRC/a.ts', '.', ''), array('src/stores/x.ts', 'docs/readme.md', 'src/a.ts'))
		);
		self::assertSame(array('src/'), Conflict::overlap(array('src/'), array('*')));
		self::assertSame(array(), Conflict::overlap(array('src/'), array()));
	}

	/**
	 * A full claim against a full check must stay cheap (no claims x paths loop).
	 * @return void
	 */
	public function testOverlapIsFastOnLargeInput(): void
	{
		$claim = array_map(static fn(int $i): string => "claimed/dir$i/deep/er/file$i.ts", range(1, 1000));
		$paths = array_map(static fn(int $i): string => "other/dir$i/deep/er/file$i.ts", range(1, 5000));
		$paths[] = 'claimed/dir7';

		$start = microtime(true);
		self::assertSame(array('claimed/dir7/deep/er/file7.ts'), Conflict::overlap($claim, $paths));
		self::assertLessThan(0.2, microtime(true) - $start);
	}

	/**
	 * @return void
	 */
	public function testReasonOnSameBranch(): void
	{
		$reason = Conflict::reason(array('branch' => 'test', 'paths' => array()), array('branch' => 'test', 'claim' => array()));
		self::assertSame('is also on branch test', $reason);
	}

	/**
	 * @return void
	 */
	public function testReasonOnClaimAndBranch(): void
	{
		$reason = Conflict::reason(
			array('branch' => 'test', 'paths' => array('src/stores/userStore.ts')),
			array('branch' => 'test', 'claim' => array('src/stores/', 'docs/'))
		);
		self::assertSame('is also on branch test and claims src/stores/', $reason);
	}

	/**
	 * @return void
	 */
	public function testEmptyBranchNeverConflicts(): void
	{
		self::assertNull(Conflict::reason(array('branch' => '', 'paths' => array()), array('branch' => '', 'claim' => array())));
	}

	/**
	 * @return void
	 */
	public function testNoConflict(): void
	{
		self::assertNull(Conflict::reason(
			array('branch' => 'feature/a', 'paths' => array('src/a.ts')),
			array('branch' => 'feature/b', 'claim' => array('src/b.ts'))
		));
	}
}
