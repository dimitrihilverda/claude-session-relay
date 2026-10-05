<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Tests;

/** Uses */

use Relay\App;
use Relay\Http\Request;

/**
 * Toetst de algemene HTTP-afhandeling.
 * @author Alice Hilverda
 * @date 05-10-2026
 */
final class AppTest extends DbTestCase
{
	/**
	 * @return void
	 */
	public function testGezondZonderToken(): void
	{
		$antwoord = $this->verzoek('GET', '/gezond', null);
		self::assertSame(200, $antwoord->status);
		self::assertSame(array('status' => 'ok'), $antwoord->data);
	}

	/**
	 * @return void
	 */
	public function testZonderGeldigTokenIs401(): void
	{
		self::assertSame(401, $this->verzoek('GET', '/bord', null)->status);
		self::assertSame(401, $this->verzoek('GET', '/bord', 'onzin')->status);
	}

	/**
	 * @return void
	 */
	public function testOnbekendEndpointIs404(): void
	{
		$token = $this->persoon('Alice')['token'];
		self::assertSame(404, $this->verzoek('GET', '/bestaat-niet', $token)->status);
	}

	/**
	 * @return void
	 */
	public function testOngeldigeJsonIs422(): void
	{
		$token = $this->persoon('Alice')['token'];
		$antwoord = (new App($this->pdo))->handle(new Request('POST', '/sessie', array(), null, $token));
		self::assertSame(422, $antwoord->status);
	}

	/**
	 * @return void
	 */
	public function testBordpaginaZonderToken(): void
	{
		$antwoord = $this->verzoek('GET', '/', null);
		self::assertSame(200, $antwoord->status);
		self::assertNotNull($antwoord->html);
		self::assertStringContainsString('<title>', $antwoord->html);
		self::assertStringContainsString("fetch('/bord'", $antwoord->html);
		self::assertSame(401, $this->verzoek('GET', '/bord', null)->status);
	}
}
