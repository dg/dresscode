<?php declare(strict_types=1);

namespace DressCode\Engine;

use DressCode\Violation;


/**
 * Outcome of the passes over one file.
 * @internal
 */
final readonly class PassResult
{
	/**
	 * @param list<Violation> $violations
	 * @param list<string> $warnings  contract violations of rules the runner tolerated (silent mutation, mutation after a suppressed report)
	 */
	public function __construct(
		public array $violations,
		public array $warnings,
		public int $passes,
		public bool $mutated,
	) {
	}
}
