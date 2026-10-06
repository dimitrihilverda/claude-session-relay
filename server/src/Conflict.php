<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/**
 * Pure logic: do paths overlap, and why does another session conflict with mine?
 * @author Dimitri Hilverda
 * @date 06-10-2026
 */
final class Conflict
{
	const array WHOLE_REPO = array('.', '*');

	/**
	 * @param string $path
	 * @return string Path with /, without leading ./ and trailing /, in lower case.
	 */
	public static function normalize(string $path): string
	{
		$path = str_replace('\\', '/', trim($path));
		while(str_starts_with($path, './') === true) {
			$path = substr($path, 2);
		}
		if(in_array($path, self::WHOLE_REPO, true) === false) {
			$path = rtrim($path, '/');
		}

		return mb_strtolower($path);
	}

	/**
	 * @param string $a
	 * @param string $b
	 * @return bool
	 */
	public static function pathsOverlap(string $a, string $b): bool
	{
		$a = self::normalize($a);
		$b = self::normalize($b);

		//Empty never overlaps; "." or "*" is the whole repo:
		if($a === '' || $b === '') {
			return false;
		}
		if(in_array($a, self::WHOLE_REPO, true) === true || in_array($b, self::WHOLE_REPO, true) === true) {
			return true;
		}

		return $a === $b || str_starts_with($b, $a . '/') === true || str_starts_with($a, $b . '/') === true;
	}

	/**
	 * @param list<string> $claim
	 * @param list<string> $paths
	 * @return list<string> The claimed paths that overlap with at least one path.
	 */
	public static function overlap(array $claim, array $paths): array
	{
		//Index the paths once (the paths and every folder above them), so this is not claims x paths:
		$exact = array();
		$folders = array();
		$wholeRepo = false;
		foreach($paths as $path) {
			$path = self::normalize($path);
			if($path === '') {
				continue;
			}
			if(in_array($path, self::WHOLE_REPO, true) === true) {
				$wholeRepo = true;
				continue;
			}
			$exact[$path] = true;
			for($slash = strrpos($path, '/'); $slash !== false && $slash > 0; $slash = strrpos($path, '/', $slash - strlen($path) - 1)) {
				$folders[substr($path, 0, $slash)] = true;
			}
		}
		if($exact === array() && $wholeRepo === false) {
			return array();
		}

		//A claim hits when it is the whole repo, a path, a folder above a path, or lies inside a path:
		$hits = array();
		foreach($claim as $claimed) {
			$normal = self::normalize($claimed);
			if($normal === '') {
				continue;
			}
			if($wholeRepo === true || in_array($normal, self::WHOLE_REPO, true) === true
				|| isset($exact[$normal]) === true || isset($folders[$normal]) === true || self::insideAny($normal, $exact) === true) {
				$hits[] = $claimed;
			}
		}

		return $hits;
	}

	/**
	 * @param string $path Normalised path.
	 * @param array<string, true> $set Normalised paths.
	 * @return bool Whether a folder above $path is in $set.
	 */
	private static function insideAny(string $path, array $set): bool
	{
		for($slash = strrpos($path, '/'); $slash !== false && $slash > 0; $slash = strrpos($path, '/', $slash - strlen($path) - 1)) {
			if(isset($set[substr($path, 0, $slash)]) === true) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array{branch:string,paths:list<string>} $mine
	 * @param array{branch:string,claim:list<string>} $other
	 * @return string|null Readable reason, or null when there is no conflict.
	 */
	public static function reason(array $mine, array $other): ?string
	{
		$reasons = array();

		//Same branch (a detached HEAD has no branch and never conflicts):
		if($mine['branch'] !== '' && $mine['branch'] === $other['branch']) {
			$reasons[] = 'is also on branch ' . $other['branch'];
		}

		//Overlapping claim:
		$hits = self::overlap($other['claim'], $mine['paths']);
		if($hits !== array()) {
			$reasons[] = 'claims ' . implode(', ', $hits);
		}

		return $reasons === array() ? null : implode(' and ', $reasons);
	}
}
