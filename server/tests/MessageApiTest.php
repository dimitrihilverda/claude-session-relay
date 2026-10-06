<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/**
 * Tests sending messages, the inbox, answers and the long-poll.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class MessageApiTest extends DbTestCase
{
	private string $alice;

	private string $bob;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		$this->alice = $this->person('Alice')['token'];
		$this->bob = $this->person('Bob')['token'];
		$this->team('app', 'Alice', 'Bob');
		$this->register($this->alice, 'alice-1', array('team' => 'app'));
		$this->register($this->bob, 'bob-1', array('team' => 'app'));
	}

	/**
	 * @param string $token
	 * @param array<string, mixed> $body
	 * @return int
	 */
	private function send(string $token, array $body): int
	{
		$response = $this->request('POST', '/message', $token, $body);
		self::assertSame(201, $response->status, (string) json_encode($response->data));
		self::assertSame(array('id'), array_keys($response->data));

		return $response->data['id'];
	}

	/**
	 * @param string $token
	 * @param string $session
	 * @param int $wait
	 * @return list<array<string, mixed>>
	 */
	private function inbox(string $token, string $session, int $wait = 0): array
	{
		$response = $this->request('GET', '/inbox', $token, array(), array('session' => $session, 'wait' => (string) $wait));
		self::assertSame(200, $response->status, (string) json_encode($response->data));

		return $response->data['messages'];
	}

	/**
	 * @return void
	 */
	public function testNoteToSessionArrivesOnce(): void
	{
		$id = $this->send($this->alice, array('from' => 'alice-1', 'to' => 'Bob-1', 'kind' => 'note', 'text' => 'I push to test once'));

		$messages = $this->inbox($this->bob, 'bob-1');
		self::assertCount(1, $messages);
		self::assertSame(array('id', 'kind', 'from', 'from_person', 'text', 'reply_to', 'created_at'), array_keys($messages[0]));
		self::assertSame($id, $messages[0]['id']);
		self::assertSame('note', $messages[0]['kind']);
		self::assertSame('alice-1', $messages[0]['from']);
		self::assertSame('Alice', $messages[0]['from_person']);
		self::assertSame('I push to test once', $messages[0]['text']);
		self::assertNull($messages[0]['reply_to']);
		self::assertSame(array(), $this->inbox($this->bob, 'bob-1'));
	}

	/**
	 * @return void
	 */
	public function testQuestionAndAnswer(): void
	{
		$question = $this->send($this->alice, array('from' => 'alice-1', 'to' => 'bob-1', 'kind' => 'question', 'text' => 'Done with UserEditDialog?'));
		$this->inbox($this->bob, 'bob-1');
		$this->send($this->bob, array('from' => 'bob-1', 'kind' => 'answer', 'reply_to' => $question, 'text' => 'Yes'));

		$messages = $this->inbox($this->alice, 'alice-1');
		self::assertSame('answer', $messages[0]['kind']);
		self::assertSame($question, $messages[0]['reply_to']);
	}

	/**
	 * @return void
	 */
	public function testAnswerOnlyToMessageAddressedToMe(): void
	{
		$this->register($this->alice, 'alice-2', array('team' => 'app'));
		$question = $this->send($this->alice, array('from' => 'alice-1', 'to' => 'alice-2', 'kind' => 'question', 'text' => 'x'));

		$response = $this->request('POST', '/message', $this->bob, array('from' => 'bob-1', 'kind' => 'answer', 'reply_to' => $question, 'text' => 'y'));
		self::assertSame(404, $response->status);
		self::assertSame(array('error' => 'No such message.'), $response->data);
		$missing = $this->request('POST', '/message', $this->bob, array('from' => 'bob-1', 'kind' => 'answer', 'reply_to' => 999999, 'text' => 'y'));
		self::assertSame($response->data, $missing->data);
		self::assertSame(422, $this->request('POST', '/message', $this->bob, array('from' => 'bob-1', 'kind' => 'answer', 'text' => 'y'))->status);
	}

	/**
	 * @return void
	 */
	public function testToPersonReachesAllSessionsExceptSender(): void
	{
		$this->register($this->bob, 'bob-2', array('team' => 'app'));
		$this->send($this->bob, array('from' => 'bob-1', 'to' => 'bob', 'kind' => 'note', 'text' => 'own'));
		$this->send($this->alice, array('from' => 'alice-1', 'to' => 'Bob', 'kind' => 'note', 'text' => 'to all'));

		self::assertSame(array('to all'), array_column($this->inbox($this->bob, 'bob-1'), 'text'));
		self::assertSame(array('own', 'to all'), array_column($this->inbox($this->bob, 'bob-2'), 'text'));
	}

	/**
	 * @return void
	 */
	public function testToPersonExpiresAfterAnHour(): void
	{
		$this->send($this->alice, array('from' => 'alice-1', 'to' => 'Bob', 'kind' => 'note', 'text' => 'old'));
		$this->pdo->exec("UPDATE message SET created_at = now() - interval '61 minutes'");

		self::assertSame(array(), $this->inbox($this->bob, 'bob-1'));
	}

	/**
	 * @return void
	 */
	public function testUnknownRecipientIs404(): void
	{
		$this->pdo->exec("UPDATE session SET last_seen = now() - interval '11 minutes' WHERE name = 'bob-1'");
		$expired = $this->request('POST', '/message', $this->alice, array('from' => 'alice-1', 'to' => 'bob-1', 'kind' => 'note', 'text' => 'x'));
		self::assertSame(404, $expired->status);
		self::assertSame(array('error' => 'No such session or person.'), $expired->data);
		self::assertSame($expired->data, $this->request('POST', '/message', $this->alice, array('from' => 'alice-1', 'to' => 'nobody', 'kind' => 'note', 'text' => 'x'))->data);
	}

	/**
	 * @return void
	 */
	public function testRevokedPersonIsNotARecipient(): void
	{
		$this->request('DELETE', '/session/bob-1', $this->bob);
		$this->pdo->exec("UPDATE person SET active = false WHERE name = 'Bob'");

		self::assertSame(404, $this->request('POST', '/message', $this->alice, array('from' => 'alice-1', 'to' => 'bob', 'kind' => 'note', 'text' => 'x'))->status);
	}

	/**
	 * @return void
	 */
	public function testFromMustBeOwnSession(): void
	{
		$response = $this->request('POST', '/message', $this->alice, array('from' => 'bob-1', 'to' => 'alice-1', 'kind' => 'note', 'text' => 'x'));
		self::assertSame(404, $response->status);
		self::assertSame(array('error' => 'Unknown session.'), $response->data);
	}

	/**
	 * @return void
	 */
	public function testInvalidMessageIs422(): void
	{
		self::assertSame(422, $this->request('POST', '/message', $this->alice, array('from' => 'alice-1', 'to' => 'bob-1', 'kind' => 'gossip', 'text' => 'x'))->status);
		self::assertSame(422, $this->request('POST', '/message', $this->alice, array('from' => 'alice-1', 'to' => 'bob-1', 'kind' => 'melding', 'text' => 'x'))->status);
		self::assertSame(422, $this->request('POST', '/message', $this->alice, array('from' => 'alice-1', 'to' => 'bob-1', 'kind' => 'note', 'text' => str_repeat('a', 4001)))->status);
		self::assertSame(422, $this->request('POST', '/message', $this->alice, array('from' => 'alice-1', 'kind' => 'note', 'text' => 'x'))->status);
	}

	/**
	 * @return void
	 */
	public function testInboxOfSomeoneElsesOrUnknownSessionIs404(): void
	{
		$other = $this->request('GET', '/inbox', $this->alice, array(), array('session' => 'bob-1'));
		$unknown = $this->request('GET', '/inbox', $this->alice, array(), array('session' => 'alice-9'));
		self::assertSame(404, $other->status);
		self::assertSame(array('error' => 'Unknown session.'), $other->data);
		self::assertSame($other->data, $unknown->data);
		self::assertSame(404, $unknown->status);
	}

	/**
	 * @return void
	 */
	public function testInboxIsHeartbeat(): void
	{
		$this->pdo->exec("UPDATE session SET last_seen = now() - interval '9 minutes' WHERE name = 'bob-1'");
		$this->inbox($this->bob, 'bob-1');

		$age = (int) $this->pdo->query("SELECT extract(epoch FROM now() - last_seen) FROM session WHERE name = 'bob-1'")->fetchColumn();
		self::assertLessThan(5, $age);
	}

	/**
	 * @return void
	 */
	public function testLongPollWaitsOnEmptyInbox(): void
	{
		$start = microtime(true);
		self::assertSame(array(), $this->inbox($this->bob, 'bob-1', 2));
		self::assertGreaterThanOrEqual(1.9, microtime(true) - $start);

		$this->send($this->alice, array('from' => 'alice-1', 'to' => 'bob-1', 'kind' => 'note', 'text' => 'x'));
		$start = microtime(true);
		self::assertCount(1, $this->inbox($this->bob, 'bob-1', 2));
		self::assertLessThan(0.5, microtime(true) - $start);
	}

	/**
	 * @return void
	 */
	public function testNewSessionWithSameNameDoesNotSeeOldMessages(): void
	{
		$this->send($this->alice, array('from' => 'alice-1', 'to' => 'bob-1', 'kind' => 'question', 'text' => 'for the old session'));
		$this->pdo->exec("UPDATE message SET created_at = now() - interval '1 minute'");
		$this->request('DELETE', '/session/bob-1', $this->bob);
		$this->register($this->bob, 'bob-1', array('team' => 'app'));

		self::assertSame(array(), $this->inbox($this->bob, 'bob-1'));
	}

	/**
	 * @return void
	 */
	public function testSessionThatMovedTeamDoesNotSeeMessagesOfOldTeam(): void
	{
		$this->send($this->alice, array('from' => 'alice-1', 'to' => 'bob-1', 'kind' => 'note', 'text' => 'app only'));
		$this->register($this->bob, 'bob-1', array('team' => 'private'));

		self::assertSame(array(), $this->inbox($this->bob, 'bob-1'));
	}
}
