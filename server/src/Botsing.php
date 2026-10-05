<?php
declare(strict_types=1);

/** Namespace */
namespace Relay;

/**
 * Pure logica: overlappen paden, en waarom botst een andere sessie met mij?
 * @author Dimitri Hilverda
 * @date 05-10-2026
 */
final class Botsing
{
	const array HELE_REPO = array('.', '*');

	/**
	 * @param string $pad
	 * @return string Pad met /, zonder ./ vooraan en / achteraan, in kleine letters.
	 */
	public static function normaliseer(string $pad): string
	{
		$pad = str_replace('\\', '/', trim($pad));
		while(str_starts_with($pad, './') === true) {
			$pad = substr($pad, 2);
		}
		if(in_array($pad, self::HELE_REPO, true) === false) {
			$pad = rtrim($pad, '/');
		}

		return mb_strtolower($pad);
	}

	/**
	 * @param string $a
	 * @param string $b
	 * @return bool
	 */
	public static function padenOverlappen(string $a, string $b): bool
	{
		$a = self::normaliseer($a);
		$b = self::normaliseer($b);

		//Empty never overlaps; "." or "*" is the whole repo:
		if($a === '' || $b === '') {
			return false;
		}
		if(in_array($a, self::HELE_REPO, true) === true || in_array($b, self::HELE_REPO, true) === true) {
			return true;
		}

		return $a === $b || str_starts_with($b, $a . '/') === true || str_starts_with($a, $b . '/') === true;
	}

	/**
	 * @param list<string> $claim
	 * @param list<string> $paden
	 * @return list<string> De claimpaden die met minstens één pad overlappen.
	 */
	public static function overlap(array $claim, array $paden): array
	{
		$raak = array();
		foreach($claim as $geclaimd) {
			foreach($paden as $pad) {
				if(self::padenOverlappen($geclaimd, $pad) === true) {
					$raak[] = $geclaimd;
					break;
				}
			}
		}

		return $raak;
	}

	/**
	 * @param array{branch:string,paden:list<string>} $ik
	 * @param array{branch:string,claim:list<string>} $ander
	 * @return string|null Leesbare reden, of null als er geen botsing is.
	 */
	public static function reden(array $ik, array $ander): ?string
	{
		$redenen = array();

		//Same branch (a detached HEAD has no branch and never collides):
		if($ik['branch'] !== '' && $ik['branch'] === $ander['branch']) {
			$redenen[] = 'is also on branch ' . $ander['branch'];
		}

		//Overlapping claim:
		$raak = self::overlap($ander['claim'], $ik['paden']);
		if($raak !== array()) {
			$redenen[] = 'claims ' . implode(', ', $raak);
		}

		return $redenen === array() ? null : implode(' and ', $redenen);
	}
}
