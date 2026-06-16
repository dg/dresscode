<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use function count, sprintf;


/**
 * The configuration is invalid: unknown rule, colliding names, bad options.
 */
class ConfigurationException extends \Exception
{
}


/**
 * A rule failed while processing a file.
 */
class RuleException extends \Exception
{
	public function __construct(
		/** null when the whitespace engine failed deciding the claims of the rules */
		public readonly ?string $ruleName,
		public readonly string $path,
		\Throwable $previous,
	) {
		parent::__construct(($ruleName === null ? 'The whitespace engine' : "Rule $ruleName") . " failed in $path: {$previous->getMessage()}", previous: $previous);
	}
}


/**
 * Rules keep mutating the file without converging.
 */
class ConvergenceException extends \Exception
{
	public function __construct(
		public readonly string $path,
		/** @var list<string> */
		public readonly array $ruleNames,
		public readonly string $diff,
	) {
		parent::__construct(count($ruleNames) === 1
			? "Rule $ruleNames[0] does not converge in $path."
			: sprintf('Rules %s and %s do not converge in %s.', implode(', ', array_slice($ruleNames, 0, -1)), end($ruleNames), $path));
	}
}
