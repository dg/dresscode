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


	/** Whether the string is a version, `8.2`, `3.3.1` or `8.2-beta1`, and not a constraint or a branch. */
	public static function isVersion(string $version): bool
	{
		try {
			return VersionParser::parseStability((new VersionParser)->normalize($version)) !== 'dev';
		} catch (\UnexpectedValueException) {
			return false;
		}
	}


	/** Whether the string is a constraint Composer reads. */
	public static function isConstraint(string $constraint): bool
	{
		try {
			self::parse($constraint);
			return true;
		} catch (\UnexpectedValueException) {
			return false;
		}
	}


	/** The versions the constraint allows from the version on, `^8.1 || ^9.0` from 8.3 being `^8.1, >=8.3 || ^9.0, >=8.3`. */
	public static function restrictFrom(string $constraint, string $version): string
	{
		return implode(' || ', array_map(fn(string $part) => "$part, >=$version", self::splitAlternatives($constraint)));
	}


	/**
	 * The alternatives of the constraint `||` separates, `^3.2` and `^4.0` of `^3.2 || ^4.0`.
	 * @return list<string>
	 */
	public static function splitAlternatives(string $constraint): array
	{
		return preg_split('~\s*\|\|?\s*~', trim($constraint), flags: PREG_SPLIT_NO_EMPTY) ?: [];
	}


	/** Whether the constraint names a development branch, `dev-master` or `dev-main as 3.3.x-dev`, and not versions. */
	public static function isBranch(string $constraint): bool
	{
		return (bool) preg_match('~(?:^|[\s|,])dev-~', $constraint);
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
