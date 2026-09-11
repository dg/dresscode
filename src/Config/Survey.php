<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\Config;
use DressCode\ConfigurationException;
use DressCode\Reporters\NullReporter;
use DressCode\RuleInfo;
use function count;


/**
 * How a project writes a decision, told by the rule that would enforce it: the rule runs over a sample of
 * the files once per value, and what it reports is what disagrees with that value. Nothing here reads the
 * code on its own, so the measure and the fix cannot part ways.
 * @internal
 */
final class Survey
{
	/** the largest sample; every k-th of the sorted files, so that the same tree always gives the same one */
	public const MaxFiles = 300;


	public function __construct(
		private readonly string $root,
		/** @var list<string>  the sample, relative to the root */
		public readonly array $files,
		/** what the configuration leaves the run to, the scope of the files above all */
		private readonly Config $base,
	) {
	}


	/**
	 * Every k-th of the files, at most MaxFiles of them.
	 * @param  list<string>  $files  sorted
	 * @return list<string>
	 */
	public static function pick(array $files): array
	{
		$step = max(1, (int) ceil(count($files) / self::MaxFiles));
		return array_values(array_filter($files, fn(int $index) => $index % $step === 0, ARRAY_FILTER_USE_KEY));
	}


	/**
	 * A decision that is a property of the file: a file agrees with a value when the rule of that value reports
	 * nothing in it, and a file no value reports has none of the decision in it and does not count.
	 * @param  class-string<\DressCode\Rule>  $rule
	 * @param  array<int|string, Config>  $values  value → the layer that runs the rule with it; PHP keeps a numeric value as an int key
	 * @throws ConfigurationException
	 */
	public function measureFiles(string $rule, array $values, string $unit = 'files'): Measurement
	{
		$reported = array_map(fn(Config $layer) => $this->probe($rule, $layer)[0], $values);
		$opportunities = count(array_replace(...array_values($reported)));
		return new Measurement(
			array_map(fn(array $files) => $opportunities - count($files), $reported),
			$opportunities,
			$unit,
		);
	}


	/**
	 * A decision every place makes anew, where the rule of each value reports every place written another way,
	 * once: the places are what the values report together, and what one reports disagrees with it. A place in
	 * none of the values is reported by every one of them; the layer that lets all of them pass counts those,
	 * so that each is one place and disagrees with every value.
	 * @param  class-string<\DressCode\Rule>  $rule
	 * @param  array<int|string, Config>  $values  value → the layer that runs the rule with it; PHP keeps a numeric value as an int key
	 * @param  ?Config  $any  the layer that runs the rule with every value allowed
	 * @throws ConfigurationException
	 */
	public function measurePlaces(string $rule, array $values, string $unit, ?Config $any = null): Measurement
	{
		$neither = $any === null ? 0 : $this->probe($rule, $any)[1];
		$reported = array_map(fn(Config $layer) => $this->probe($rule, $layer)[1], $values);
		$opportunities = array_sum($reported) - (count($values) - 1) * $neither;
		return new Measurement(
			array_map(fn(int $count) => $opportunities - $count, $reported),
			$opportunities,
			$unit,
			$neither,
		);
	}


	/**
	 * How many files of the sample the configuration would change, which is the number a user weighs.
	 * @throws ConfigurationException
	 */
	public function countChanged(Config $config): int
	{
		$runner = (new RunnerFactory)->createRunner($config, $this->root, cache: false);
		return $runner->run($this->files, fix: false, reporter: new NullReporter)->countChangedFiles();
	}


	/**
	 * The files the rule reports something in under the layer, and the number of its reports; a violation
	 * that follows from another one is not a place of its own.
	 * @param  class-string<\DressCode\Rule>  $rule
	 * @return array{array<string, true>, int}
	 * @throws ConfigurationException
	 */
	private function probe(string $rule, Config $layer): array
	{
		$config = Config::create()->merge($this->base)->merge($layer);
		$runner = (new RunnerFactory)->createRunner($config, $this->root, cache: false);
		$name = RuleInfo::of($rule)->name;
		$files = [];
		$count = 0;
		foreach ($runner->run($this->files, fix: false, reporter: new NullReporter)->files as $result) {
			foreach ($result->violations as $violation) {
				if ($violation->ruleName === $name && $violation->derivedFrom === null) {
					$files[$result->path] = true;
					$count++;
				}
			}
		}

		return [$files, $count];
	}
}
