<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;

use function array_key_exists;


/**
 * What PHP itself declares: the functions, classes, interfaces, enums and constants of PHP 8.0 and later and of the
 * extensions shipped with it, gathered over every version; what PHP retired is in its upgrading data instead,
 * `src/Rules/Upgrading/php.neon`. The running interpreter is never asked, so the answer does not depend on the PHP or the
 * extensions of the machine that runs the check; a PECL extension is not PHP and is not here.
 */
final class PhpSymbols
{
	use PhpSymbolsData;

	/**
	 * Whether PHP declares the global function, whatever the letter case; given a version, whether that version
	 * declares it, which is how a function dropped later (`imap_open` from PHP 8.4) and one added later
	 * (`mb_trim` in 8.4) are told apart. An extension the source of the catalog could not see reads as added
	 * later than it was, so the answer errs towards no.
	 */
	public function isBuiltinFunction(string $name, ?string $version = null): bool
	{
		$name = strtolower($name);
		if (!array_key_exists($name, self::Functions)) {
			return false;
		} elseif ($version === null) {
			return true;
		}

		[$since, $until] = explode('-', self::FunctionVersions[$name] ?? '-');
		return ($since === '' || version_compare($version, $since, '>='))
			&& ($until === '' || version_compare($version, $until, '<='));
	}


	/**
	 * The declared spelling of a builtin class, interface or enum given its fully qualified name in any letter case,
	 * without a leading backslash; null for any other name.
	 */
	public function findClassName(string $name): ?string
	{
		return self::Classes[strtolower($name)] ?? null;
	}


	/** Whether PHP declares the constant, given its fully qualified name without a leading backslash; the name itself is case-sensitive. */
	public function isBuiltinConstant(string $name): bool
	{
		$pos = strrpos($name, '\\');
		$key = $pos === false ? $name : strtolower(substr($name, 0, $pos)) . substr($name, $pos);
		return isset(self::Constants[$key]);
	}


	/**
	 * The parameters of the builtin function in their order when PHP 8.4 or later calls it with that many arguments
	 * without a frame, which it does even unqualified in a namespace, but not with a named or an unpacked argument;
	 * null when it has no such call.
	 * @return ?list<string>
	 */
	public function findFramelessParameterNames(string $function, int $arguments): ?array
	{
		[$counts, $parameters] = self::FramelessFunctions[strtolower($function)] ?? [[], []];
		return in_array($arguments, $counts, true) ? $parameters : null;
	}
}
