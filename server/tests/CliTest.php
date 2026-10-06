<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/**
 * Runs bin/relay against the test database.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class CliTest extends DbTestCase
{
	/**
	 * @param string ...$args
	 * @return array{code:int,out:string,err:string}
	 */
	private function relay(string ...$args): array
	{
		$command = array(PHP_BINARY, __DIR__ . '/../bin/relay', ...$args);
		$process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		self::assertIsResource($process);
		$out = (string) stream_get_contents($pipes[1]);
		$err = (string) stream_get_contents($pipes[2]);

		return array('code' => proc_close($process), 'out' => $out, 'err' => $err);
	}

	/**
	 * @return void
	 */
	public function testTeamCommands(): void
	{
		$created = $this->relay('person:create', 'carol');
		self::assertSame(0, $created['code'], $created['err']);
		self::assertMatchesRegularExpression('/^[0-9a-f]{64}\n$/', $created['out']);
		self::assertSame(0, $this->relay('person:create', 'alice')['code']);

		self::assertSame("created team beta\n", $this->relay('team:create', 'beta')['out']);
		self::assertSame("added carol to beta\n", $this->relay('team:add', 'beta', 'carol')['out']);
		self::assertSame("added alice to beta\n", $this->relay('team:add', 'beta', 'alice')['out']);
		self::assertSame("renamed beta to widget\n", $this->relay('team:rename', 'beta', 'widget')['out']);
		self::assertSame("widget: alice, carol\n", $this->relay('team:list')['out']);
		self::assertSame("removed carol from widget\n", $this->relay('team:remove', 'widget', 'carol')['out']);
		self::assertSame("widget: alice\n", $this->relay('team:list')['out']);

		$private = $this->relay('team:create', 'private');
		self::assertSame(1, $private['code']);
		self::assertStringContainsString('private', $private['err']);
		self::assertSame(1, $this->relay('team:add', 'nope', 'carol')['code']);
	}

	/**
	 * @return void
	 */
	public function testOtherCommands(): void
	{
		self::assertSame(0, $this->relay('migrate')['code']);
		$this->relay('person:create', 'carol');
		self::assertSame("revoked carol\n", $this->relay('person:revoke', 'carol')['out']);
		self::assertSame(1, $this->relay('person:revoke', 'nobody')['code']);
		self::assertSame("removed 0 sessions, 0 messages, 0 oauth rows\n", $this->relay('cleanup')['out']);

		$usage = $this->relay('persoon:maak', 'x');
		self::assertSame(1, $usage['code']);
		self::assertStringContainsString('team:create', $usage['err']);
	}
}
