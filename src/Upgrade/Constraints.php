<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Config\Versions;


/**
 * The constraints of `composer.json` an upgrade raises to the targets, each in the shape the project writes it with its
 * upper bounds kept.
 * @internal
 */
final readonly class Constraints
{
	/** a range of versions from one to another, `8.1 - 8.5` */
	private const HyphenRange = '~^(v?\d[\w.]*)\s+-\s+(v?\d[\w.]*)$~';


	/**
	 * The constraint raised to the version in the shape the project writes it, its upper bounds kept: a part of `||` the
	 * version is past dropped, and the lower bound of a part allowing it raised, `>=8.4` for `>=8.1`, `^8.4` for a caret
	 * or a bare version, `>=8.4 <8.5` for `>=8.1 <8.5`; where the version is past every part, the last raised the same
	 * way, `~8.4.0` for `~8.1.0`, and a caret for one with an upper bound of its own.
	 */
	public static function raiseConstraint(string $constraint, string $version): string
	{
		$parts = Versions::splitAlternatives($constraint);
		$raised = [];
		foreach ($parts as $part) {
			if (version_compare(Versions::findLowestVersion($part) ?? '0', $version, '>=')) {
				$raised[] = $part;
			} elseif (Versions::isSubset($version, $part)) {
				$raised[] = self::rewriteLowerBound($part, $version);
			}
		}

		$last = end($parts);
		return match (true) {
			$raised !== [] => implode(' || ', $raised),
			$last === false || self::findUpperBound($last) !== null => "^$version",
			default => self::rewriteLowerBound($last, $version),
		};
	}


	/**
	 * The part of a constraint with its lower bound replaced by the version, its upper bound kept: each operator with its
	 * version, `>= 8.1` as `>=8.1`, and a range of a hyphen with its end.
	 */
	private static function rewriteLowerBound(string $part, string $version): string
	{
		$part = (string) preg_replace('~(<=|>=|<|>|!=|==|=|\^|\~)\s+(?=v?\d)~', '$1', trim($part));
		if (preg_match(self::HyphenRange, $part, $m)) {
			return "$version - $m[2]";
		}

		$tokens = array_map(fn(string $token) => match (true) {
			str_starts_with($token, '<'), str_starts_with($token, '!=') => $token,
			str_starts_with($token, '>') => ">=$version",
			(bool) preg_match('~^\~\s*v?(\d+(?:\.\d+)*)$~', $token, $m)
				=> '~' . implode('.', array_pad(explode('.', $version), substr_count($m[1], '.') + 1, '0')),
			default => "^$version",
		}, preg_split('~\s*,\s*|\s+~', trim($part), flags: PREG_SPLIT_NO_EMPTY) ?: []);
		return implode(str_contains($part, ',') ? ', ' : ' ', $tokens);
	}


	private static function findUpperBound(string $part): ?string
	{
		return match (true) {
			(bool) preg_match(self::HyphenRange, trim($part), $m) => $m[2],
			(bool) preg_match('~<=?\s*v?\d[\w.-]*~', $part, $m) => $m[0],
			default => null,
		};
	}
}
