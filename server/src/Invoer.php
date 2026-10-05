<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/** Uses */

use Relay\Http\HttpError;

/**
 * Validatie van request-velden; elke fout wordt een 422.
 * @author Dimitri Hilverda
 * @date 05-10-2026
 */
final class Invoer
{
	const int MAX_PADEN = 5000;

	/**
	 * @param array<string, mixed> $in
	 * @param string $veld
	 * @param int $max
	 * @param bool $verplicht
	 * @return string Getrimde waarde ('' als niet verplicht en afwezig).
	 * @throws HttpError
	 */
	public static function tekst(array $in, string $veld, int $max, bool $verplicht = true): string
	{
		$waarde = $in[$veld] ?? '';
		if(is_string($waarde) === false) {
			throw new HttpError(422, "Field $veld must be a string.");
		}
		$waarde = trim($waarde);
		if($verplicht === true && $waarde === '') {
			throw new HttpError(422, "Field $veld is required.");
		}
		if(mb_strlen($waarde) > $max) {
			throw new HttpError(422, "Field $veld must be at most $max characters.");
		}

		return $waarde;
	}

	/**
	 * @param array<string, mixed> $in
	 * @param string $veld
	 * @return string Sessie- of persoonsnaam in kleine letters.
	 * @throws HttpError
	 */
	public static function naam(array $in, string $veld): string
	{
		$naam = self::tekst($in, $veld, 80);
		if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $naam) !== 1) {
			throw new HttpError(422, "Field $veld may only contain letters, digits, dots, dashes and underscores.");
		}

		return strtolower($naam);
	}

	/**
	 * @param array<string, mixed> $in
	 * @param string $veld
	 * @return list<string> Niet-lege paden.
	 * @throws HttpError
	 */
	public static function padLijst(array $in, string $veld): array
	{
		$lijst = $in[$veld] ?? array();
		if(is_array($lijst) === false || array_is_list($lijst) === false || count($lijst) > self::MAX_PADEN) {
			throw new HttpError(422, "Field $veld must be a list of at most " . self::MAX_PADEN . ' paths.');
		}
		$uit = array();
		foreach($lijst as $pad) {
			if(is_string($pad) === false || mb_strlen($pad) > 500) {
				throw new HttpError(422, "Field $veld contains an invalid path.");
			}
			$pad = trim($pad);
			if($pad !== '') {
				$uit[] = $pad;
			}
		}

		return $uit;
	}
}
