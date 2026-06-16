<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
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
}
