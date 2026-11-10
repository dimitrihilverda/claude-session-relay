<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/** Uses */

use InvalidArgumentException;
use Relay\ServiceTokenStore;

/**
 * Tests service tokens: a trusted service lists the teams (and members) in its scope.
 * @author Dimitri Hilverda
 * @date 10-11-2026
 */
final class ServiceTokenTest extends DbTestCase
{
	/**
	 * @return void
	 */
	public function testListsOnlyScopedTeamsWithMembers(): void
	{
		$this->person('alice');
		$this->person('bob');
		$this->person('carol');
		$this->team('a', 'alice', 'bob');
		$this->team('b', 'carol');
		$token = (new ServiceTokenStore($this->pdo))->create('brain', array('a'));

		$response = $this->request('GET', '/service/teams', $token);

		self::assertSame(200, $response->status);
		self::assertSame(array('teams' => array(array('name' => 'a', 'members' => array('alice', 'bob')))), $response->data);
	}

	/**
	 * @return void
	 */
	public function testUnknownOrRevokedTokenIs401(): void
	{
		$store = new ServiceTokenStore($this->pdo);
		$token = $store->create('brain', array('a'));

		self::assertSame(401, $this->request('GET', '/service/teams', 'nope')->status);
		self::assertSame(401, $this->request('GET', '/service/teams', null)->status);
		self::assertSame(200, $this->request('GET', '/service/teams', $token)->status);

		self::assertTrue($store->revoke('brain'));
		self::assertSame(401, $this->request('GET', '/service/teams', $token)->status);
		self::assertFalse($store->revoke('unknown'));
	}

	/**
	 * @return void
	 */
	public function testPersonTokenIsNotAServiceToken(): void
	{
		$alice = $this->person('alice');

		self::assertSame(401, $this->request('GET', '/service/teams', $alice['token'])->status);
	}

	/**
	 * @return void
	 */
	public function testServiceTokenIsNotAPersonToken(): void
	{
		$token = (new ServiceTokenStore($this->pdo))->create('brain', array('a'));

		self::assertSame(401, $this->request('GET', '/me', $token)->status);
	}

	/**
	 * @return void
	 */
	public function testDeletedTeamIsOmitted(): void
	{
		$this->person('alice');
		$this->team('a', 'alice');
		$token = (new ServiceTokenStore($this->pdo))->create('brain', array('a', 'gone'));

		$response = $this->request('GET', '/service/teams', $token);

		self::assertSame(array(array('name' => 'a', 'members' => array('alice'))), $response->data['teams']);
	}

	/**
	 * @return void
	 */
	public function testTeamWithoutMembersHasEmptyList(): void
	{
		$this->team('a');
		$token = (new ServiceTokenStore($this->pdo))->create('brain', array('a'));

		self::assertSame(array(array('name' => 'a', 'members' => array())), $this->request('GET', '/service/teams', $token)->data['teams']);
	}

	/**
	 * @return void
	 */
	public function testCreateAgainGivesNewTokenAndInvalidatesTheOldOne(): void
	{
		$store = new ServiceTokenStore($this->pdo);
		$old = $store->create('brain', array('a'));
		$new = $store->create('brain', array('b'));

		self::assertNotSame($old, $new);
		self::assertNull($store->teamsFor($old));
		self::assertSame(array('b'), $store->teamsFor($new));
	}

	/**
	 * @return void
	 */
	public function testCreateValidatesInput(): void
	{
		$store = new ServiceTokenStore($this->pdo);

		$this->expectException(InvalidArgumentException::class);
		$store->create('brain', array());
	}

	/**
	 * @return void
	 */
	public function testCreateRejectsInvalidTeamName(): void
	{
		$this->expectException(InvalidArgumentException::class);
		(new ServiceTokenStore($this->pdo))->create('brain', array('Bad Team'));
	}
}
