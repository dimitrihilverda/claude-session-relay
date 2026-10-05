<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Http;

/** Uses */

use RuntimeException;

/**
 * Fout die als HTTP-status met JSON-body naar de client gaat.
 * @author Dimitri Hilverda
 * @date 05-10-2026
 */
final class HttpError extends RuntimeException
{
	/**
	 * @param int $status
	 * @param string $bericht Volledige Engelse zin eindigend op een punt.
	 */
	public function __construct(public readonly int $status, string $bericht)
	{
		parent::__construct($bericht);
	}
}
