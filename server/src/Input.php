<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/** Uses */

use Relay\Http\HttpError;

/**
 * Validation of request fields; every error becomes a 422.
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class Input
{
	const int MAX_PATHS = 5000;

	/**
	 * @param array<string, mixed> $in
	 * @param string $field
	 * @param int $max
	 * @param bool $required
	 * @return string Trimmed value ('' when optional and absent).
	 * @throws HttpError
	 */
	public static function text(array $in, string $field, int $max, bool $required = true): string
	{
		$value = $in[$field] ?? '';
		if(is_string($value) === false) {
			throw new HttpError(422, "Field $field must be a string.");
		}
		$value = trim($value);
		if($required === true && $value === '') {
			throw new HttpError(422, "Field $field is required.");
		}
		if(mb_strlen($value) > $max) {
			throw new HttpError(422, "Field $field must be at most $max characters.");
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $in
	 * @param string $field
	 * @return string Session or person name in lower case.
	 * @throws HttpError
	 */
	public static function name(array $in, string $field): string
	{
		$name = self::text($in, $field, 80);
		if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) !== 1) {
			throw new HttpError(422, "Field $field may only contain letters, digits, dots, dashes and underscores.");
		}

		return strtolower($name);
	}

	/**
	 * @param array<string, mixed> $in
	 * @param string $field
	 * @return int A positive id.
	 * @throws HttpError
	 */
	public static function id(array $in, string $field): int
	{
		$value = $in[$field] ?? null;
		if(is_string($value) === true && preg_match('/^[0-9]{1,18}$/', $value) === 1) {
			$value = (int) $value;
		}
		if(is_int($value) === false || $value < 1) {
			throw new HttpError(422, "Field $field must be a message id.");
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $in
	 * @param string $field
	 * @param int $max Most paths allowed.
	 * @return list<string> Non-empty paths.
	 * @throws HttpError
	 */
	public static function pathList(array $in, string $field, int $max = self::MAX_PATHS): array
	{
		$list = $in[$field] ?? array();
		if(is_array($list) === false || array_is_list($list) === false || count($list) > $max) {
			throw new HttpError(422, "Field $field must be a list of at most $max paths.");
		}
		$out = array();
		foreach($list as $path) {
			if(is_string($path) === false || mb_strlen($path) > 500) {
				throw new HttpError(422, "Field $field contains an invalid path.");
			}
			$path = trim($path);
			if($path !== '') {
				$out[] = $path;
			}
		}

		return $out;
	}
}
