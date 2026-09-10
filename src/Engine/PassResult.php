<?php declare(strict_types=1);

namespace DressCode\Engine;

use DressCode\Violation;


/**
 * Outcome of the passes over one file.
 * @internal
 */
final readonly class PassResult
{
	public function __construct(
		/** @var list<Violation> */
		public array $violations,
		/** @var list<string> contract violations of rules the runner tolerated (silent mutation, mutation after a suppressed report) */
		public array $warnings,
		public int $passes,
		public bool $mutated,
		/** @var list<string> fingerprints of the violations the baseline silenced */
		public array $baselined = [],
	) {
	}
}
