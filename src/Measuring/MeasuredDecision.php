<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;


/**
 * A decision as it was measured: its plan and what its own probes said.
 * @internal
 */
final readonly class MeasuredDecision
{
	public function __construct(
		public MeasurementPlan $plan,
		/** @var array<string, ProbeResult>  by the keys of `MeasurementPlan::buildProbes()` */
		public array $results,
	) {
	}


	/**
	 * The files a rule failed in under some of the probes.
	 * @return list<string>
	 */
	public function listFailedFiles(): array
	{
		return array_values(array_unique(array_merge(
			...array_map(fn(ProbeResult $probe) => $probe->failed, array_values($this->results)),
		)));
	}
}
