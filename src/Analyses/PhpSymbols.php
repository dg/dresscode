<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;


/**
 * What PHP itself declares: the functions, classes, interfaces, enums and constants of PHP 8.0 and later and of the
 * extensions shipped with it, gathered over every version. The running interpreter is never asked, so the answer
 * does not depend on the PHP or the extensions of the machine that runs the check; a PECL extension is not PHP and is
 * not here.
 */
final class PhpSymbols
{
	use PhpSymbolsData;

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
