<?php
declare(strict_types=1);

/** Namespace */
namespace Relay\Http;

/** Uses */

use RuntimeException;

/**
 * Error that goes to the client as an HTTP status with a JSON body.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class HttpError extends RuntimeException
{
	/**
	 * @param int $status
	 * @param string $message Full English sentence ending with a period.
	 */
	public function __construct(public readonly int $status, string $message)
	{
		parent::__construct($message);
	}
}
