<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/** Uses */

use PDO;

/**
 * Verwijdert sessies zonder hartslag sinds 24 uur en berichten ouder dan 30 dagen.
 * @author Dimitri Hilverda
 * @date 05-10-2026
 */
final class Opruimer
{
	/**
	 * @param PDO $pdo
	 */
	public function __construct(private PDO $pdo)
	{
	}

	/**
	 * @return array{sessies:int,berichten:int}
	 */
	public function ruimOp(): array
	{
		$sessies = (int) $this->pdo->exec("DELETE FROM sessie WHERE laatst_gezien < now() - interval '24 hours'");
		$berichten = (int) $this->pdo->exec("DELETE FROM bericht WHERE aangemaakt < now() - interval '30 days'");

		return array('sessies' => $sessies, 'berichten' => $berichten);
	}
}
