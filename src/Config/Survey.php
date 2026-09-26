<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Config, ConfigurationException, Profile, RuleInfo};
use DressCode\Reporters\NullReporter;
use function count;


/**
 * How a project writes a decision, told by the rule that would enforce it: the rule runs over a sample of
 * the files once per value, and what it reports is what disagrees with that value. Nothing here reads the
 * code on its own, so the measure and the fix cannot part ways.
 * @internal
 */
final class Survey
{
	public function __construct(
		private readonly string $root,
		/** @var list<string>  the sample, relative to the root */
		private readonly array $files,
		/** what the configuration leaves the run to, the scope of the files above all */
		private readonly Config $base,
	) {
	}


	/**
	 * A decision that is a property of the file: a file agrees with a value when the rule of that value reports
	 * nothing in it, and a file no value reports has none of the decision in it and does not count. Neither does a
	 * file the rule fails in under any of the values, which would otherwise agree with that one.
	 * @param  class-string<\DressCode\Rule>  $rule
	 * @param  array<int|string, Profile>  $values  value => the profile that runs the rule with it; PHP keeps a numeric value as an int key
	 * @throws ConfigurationException
	 */
	public function measureFiles(string $rule, array $values): Measurement
	{
		$probes = array_map(fn(Profile $layer) => $this->probe($rule, $layer), $values);
		$failed = array_merge(...array_column($probes, 1));
		$reported = array_map(fn(array $probe) => array_diff_key($probe[0], $failed), $probes);
		$opportunities = count(array_replace([], ...array_values($reported)));
		return new Measurement(
			array_map(fn(array $files) => $opportunities - count($files), $reported),
			$opportunities,
			'files',
			failed: count($failed),
		);
	}


	/**
	 * A decision every place makes anew, where the rule of each value reports every place written another way,
	 * once: the places are what the values report together, and what one reports disagrees with it. A place in
	 * none of the values is reported by every one of them; the profile that lets all of them pass counts those,
	 * so that each is one place and disagrees with every value. The places of a file the rule fails in under any
	 * of the profiles are left out of all of them.
	 * @param  class-string<\DressCode\Rule>  $rule
	 * @param  array<int|string, Profile>  $values  value => the profile that runs the rule with it; PHP keeps a numeric value as an int key
	 * @param  ?Profile  $any  the profile that runs the rule with every value allowed
	 * @throws ConfigurationException
	 */
	public function measurePlaces(string $rule, array $values, string $unit, ?Profile $any = null): Measurement
	{
		$probes = array_map(fn(Profile $layer) => $this->probe($rule, $layer), $values);
		$neither = $any === null ? [[], []] : $this->probe($rule, $any);
		$failed = array_merge($neither[1], ...array_column($probes, 1));
		$count = fn(array $probe) => array_sum(array_diff_key($probe[0], $failed));
		$reported = array_map($count, $probes);
		$opportunities = array_sum($reported) - (count($values) - 1) * $count($neither);
		return new Measurement(
			array_map(fn(int $count) => $opportunities - $count, $reported),
			$opportunities,
			$unit,
			$count($neither),
			count($failed),
		);
	}


	/**
	 * How many files of the sample the configuration would change, which is the number a user weighs, and how many
	 * a rule fails in, whose change is not known.
	 * @return array{int, int}
	 * @throws ConfigurationException
	 */
	public function countChanged(Config $config): array
	{
		$runner = (new RunnerFactory)->createRunner($config, $this->root, cache: false);
		$run = $runner->run($this->files, fix: false, reporter: new NullReporter);
		return [$run->countChangedFiles(), $run->countFailures()];
	}


	/**
	 * What the rule reports with the profile laid over the base, counted by file, and the files it fails in; a violation
	 * that follows from another one is not a place of its own.
	 * @param  class-string<\DressCode\Rule>  $rule
	 * @return array{array<string, int>, array<string, true>}
	 * @throws ConfigurationException
	 */
	private function probe(string $rule, Profile $layer): array
	{
		$runner = (new RunnerFactory)->createRunner($this->base, $this->root, commandLine: $layer, cache: false);
		$name = RuleInfo::of($rule)->name;
		$reported = $failed = [];
		foreach ($runner->run($this->files, fix: false, reporter: new NullReporter)->files as $result) {
			if ($result->failure !== null) {
				$failed[$result->path] = true;
				continue;
			}

			foreach ($result->violations as $violation) {
				if ($violation->ruleName === $name && $violation->derivedFrom === null) {
					$reported[$result->path] = ($reported[$result->path] ?? 0) + 1;
				}
			}
		}

		return [$reported, $failed];
	}
}
