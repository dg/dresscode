<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\Rule;
use function count;


/**
 * One rule of a resolved configuration: its canonical name, the options it ends up with, the layers that
 * set them and, when it does not run, why. The layers are what they were given, in the order they were
 * given; the options are the result of processing them through the schema of the rule, defaults and all.
 */
final readonly class ResolvedRule
{
	public function __construct(
		public string $name,
		/** @var class-string<Rule> */
		public string $class,
		/** @var array<string, mixed>  validated, with the defaults of the schema filled in */
		public array $options,
		/** @var list<array{string, mixed}>  where a value came from and what it was, in the order given */
		public array $layers,
		/** why the rule does not run; null when it does */
		public ?string $inactive = null,
		/** @var ?\Closure(): Rule  a rule the configuration builds itself */
		public ?\Closure $factory = null,
	) {
	}


	public function isActive(): bool
	{
		return $this->inactive === null;
	}


	/** The name of the last layer that had a say, or null for a rule nobody mentioned. */
	public function getSource(): ?string
	{
		$last = $this->layers[count($this->layers) - 1] ?? null;
		return $last === null ? null : $last[0];
	}
}
