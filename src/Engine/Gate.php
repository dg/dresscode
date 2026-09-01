<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\{Decision, Values};
use function array_key_exists, count;


/**
 * The gate every report of a rule passes: which of the decisions it declares the run reports, a requirement
 * being reported or not, `keep` or narrowed away, and a parameter reporting nothing of its own.
 * @internal
 */
final readonly class Gate
{
	/** the path of the one requirement of the rule; null for a rule with another count of them */
	private ?string $soleRequirement;


	public function __construct(
		/** @var array<string, ?bool>  path => whether the requirement is reported in this run, null for a parameter */
		private array $decisions = [],
	) {
		$requirements = array_filter($decisions, fn(?bool $selected) => $selected !== null);
		$this->soleRequirement = count($requirements) === 1 ? (string) array_key_first($requirements) : null;
	}


	/**
	 * The gate of the decisions as the values of the run select them.
	 * @param  list<Decision>  $decisions
	 */
	public static function fromValues(array $decisions, Values $values): self
	{
		$map = [];
		foreach ($decisions as $decision) {
			$map[$decision->path] = $decision->parameter ? null : $values->isSelected($decision->path);
		}

		return new self($map);
	}


	/**
	 * The gate of a rule run without a resolution, as a test runs one: every requirement of it is reported.
	 * @param  list<Decision>  $decisions
	 */
	public static function open(array $decisions): self
	{
		$map = [];
		foreach ($decisions as $decision) {
			$map[$decision->path] = $decision->parameter ? null : true;
		}

		return new self($map);
	}


	/**
	 * What a report of the decision is told by, the only requirement of the rule when null, and null where the run
	 * does not report it; a path the rule does not declare and a parameter, which reports nothing of its own, are
	 * a mistake of the rule.
	 */
	public function admit(?string $decision): ?string
	{
		$decision ??= $this->soleRequirement;
		if ($decision === null) {
			throw new \LogicException('It reported under no decision, which only a rule of one requirement may.');
		} elseif (!array_key_exists($decision, $this->decisions)) {
			throw new \LogicException("It reported under `$decision`, a decision it does not declare.");
		}

		$selected = $this->decisions[$decision] ?? throw new \LogicException("It reported under `$decision`, a parameter, which reports nothing of its own.");
		return $selected ? $decision : null;
	}


	/**
	 * The paths of the requirements the run reports.
	 * @return list<string>
	 */
	public function getAdmitted(): array
	{
		return array_keys(array_filter($this->decisions));
	}
}
