<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\{Risk, Severity, Violation};


/**
 * How many violations of each kind a file has, which is what a run keeps of them once they were reported: a large tree
 * has millions.
 * @internal
 */
final readonly class ViolationTally
{
	private function __construct(
		/** @var array<string, int> the severity => how many */
		private array $all,
		/** @var array<string, int> the severity => how many follow from another violation of the file */
		private array $derived,
		/** @var array<string, int> the risk => how many fixes the run did not allow */
		private array $refused,
		private int $unfixable,
	) {
	}


	/** @param  list<Violation>  $violations */
	public static function of(array $violations): self
	{
		$all = $derived = $refused = [];
		$unfixable = 0;
		foreach ($violations as $violation) {
			$severity = $violation->severity->value;
			$all[$severity] = ($all[$severity] ?? 0) + 1;
			if ($violation->derivedFrom !== null) {
				$derived[$severity] = ($derived[$severity] ?? 0) + 1;
			}

			if ($violation->refused) {
				$risk = $violation->risk->value ?? '';
				$refused[$risk] = ($refused[$risk] ?? 0) + 1;
			}

			$unfixable += $violation->fixable ? 0 : 1;
		}

		return new self($all, $derived, $refused, $unfixable);
	}


	public function count(?Severity $severity = null): int
	{
		return $severity === null ? array_sum($this->all) : $this->all[$severity->value] ?? 0;
	}


	public function countDerived(?Severity $severity = null): int
	{
		return $severity === null ? array_sum($this->derived) : $this->derived[$severity->value] ?? 0;
	}


	public function countRefused(?Risk $risk = null): int
	{
		return $risk === null ? array_sum($this->refused) : $this->refused[$risk->value] ?? 0;
	}


	public function countUnfixable(): int
	{
		return $this->unfixable;
	}
}
