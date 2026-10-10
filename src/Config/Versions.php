<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\{Intervals, VersionParser};
use function count;


/**
 * Versions and constraints as Composer reads them: what the rules require, what the project is written for, and the
 * short form a version travels in to the rules and the messages.
 * @internal
 */
final class Versions
{
	/** @var array<string, ConstraintInterface> */
	private static array $constraints = [];


	/**
	 * @throws \UnexpectedValueException  when the string is no constraint
	 */
	public static function parse(string $constraint): ConstraintInterface
	{
		return self::$constraints[$constraint] ??= (new VersionParser)->parseConstraints($constraint);
	}


	/** Whether the string is one released version, `8.2` or `3.3.1`, and not a constraint or a branch. */
	public static function isVersion(string $version): bool
	{
		try {
			return VersionParser::parseStability((new VersionParser)->normalize($version)) !== 'dev';
		} catch (\UnexpectedValueException) {
			return false;
		}
	}


	/** Whether every version the first constraint allows is one the second allows too. */
	public static function isSubset(string $constraint, string $of): bool
	{
		return Intervals::isSubsetOf(self::parse($constraint), self::parse($of));
	}


	/**
	 * The lowest version a constraint allows in the short form, `3.1` for `^3.1 || ^4.0`; null where the constraint has
	 * no lower bound (`<8.4`, `*`, `dev-master`) or is no constraint.
	 */
	public static function findLowestVersion(string $constraint): ?string
	{
		try {
			$bound = self::parse($constraint)->getLowerBound();
		} catch (\UnexpectedValueException) {
			return null;
		}

		return $bound->isZero() ? null : self::shortenVersion($bound->getVersion());
	}


	/** A normalized version without its stability and the zeros it only pads with: `3.1.0.0-dev` is `3.1`. */
	public static function shortenVersion(string $version): string
	{
		$parts = explode('.', (string) preg_replace('~-.*$~D', '', $version));
		while (count($parts) > 2 && $parts[count($parts) - 1] === '0') {
			array_pop($parts);
		}

		return implode('.', $parts);
	}


	/** The short version as a reader knows it: the newest of a development line, `3.3.9999999.9999999`, is `3.3.x-dev`. */
	public static function formatVersion(string $version): string
	{
		return (string) preg_replace('~(\.9999999)+$~D', '.x-dev', $version);
	}
}
