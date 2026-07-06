<?php declare(strict_types=1);

namespace DressCode;


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
		public readonly string $ruleName,
		public readonly string $path,
		\Throwable $previous,
	) {
		parent::__construct("Rule $ruleName failed in $path: {$previous->getMessage()}", previous: $previous);
	}
}


/**
 * Rules keep mutating the file without converging.
 */
class ConvergenceException extends \Exception
{
	/** @param list<string> $ruleNames */
	public function __construct(
		public readonly string $path,
		public readonly array $ruleNames,
		public readonly string $diff,
	) {
		parent::__construct(sprintf('Rules %s do not converge in %s.', implode(', ', $ruleNames), $path));
	}
}
