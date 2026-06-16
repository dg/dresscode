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
final class ConfigurationException extends \Exception
{
	public function __construct(
		string $message,
		/** the page of the manual that says more than the message, as `page#anchor` */
		public readonly ?string $docs = null,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, previous: $previous);
	}
}


/**
 * A rule failed while processing a file.
 */
final class RuleException extends \Exception
{
	public function __construct(
		/** null when the whitespace engine failed deciding the claims of the rules */
		public readonly ?string $ruleName,
		public readonly string $path,
		\Throwable $previous,
	) {
		parent::__construct(($ruleName === null ? 'The whitespace engine' : "Rule `$ruleName`") . " failed in `$path`: {$previous->getMessage()}", previous: $previous);
	}
}


/**
 * Rules keep mutating the file without converging.
 */
final class ConvergenceException extends \Exception
{
	/** the page of the manual on rules that do not converge */
	public const Docs = 'troubleshooting#no-convergence';


	public function __construct(
		public readonly string $path,
		/** @var list<string> */
		public readonly array $ruleNames,
		public readonly string $diff,
	) {
		parent::__construct(count($ruleNames) === 1
			? "Rule `$ruleNames[0]` does not converge in `$path`."
			: sprintf('Rules `%s` and `%s` do not converge in `%s`.', implode('`, `', array_slice($ruleNames, 0, -1)), end($ruleNames), $path));
	}
}
