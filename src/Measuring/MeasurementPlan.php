<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use DressCode\{Decision, Domains};
use DressCode\Engine\Helpers;
use function count, is_int, sprintf;


/**
 * How `dresscode init` measures one decision, all told by its domain: the method, the values it probes the decision
 * under as a configuration writes them, and from what the probes report, what the file writes for it. The domains know
 * nothing of measuring.
 * @internal
 */
final readonly class MeasurementPlan
{
	/** the decisions measured first and laid under the probes of the others, which stand on them */
	public const FirstLayer = ['indentation.unit', 'file.longLines', 'file.lineEnding'];

	/** a requirement measured on a parameter: the ladder of the parameter, the requirement laid with it */
	public const MeasuredOnParameter = ['file.longLines' => 'file.lineLength'];

	/** the steps of the width of a line */
	public const LineLengths = [80, 100, 120, 140, 160, 200, 'none'];

	/** the counts a count is measured under at most, beside the values of the standards */
	private const MaxCount = 2;

	/** a requirement of one value whose fix touches at most this share of the files is written, its fix being cheap */
	private const StragglerShare = 0.1;

	/** a width of a line passes where at most this share of the files has a longer line */
	private const LineLengthShare = 0.01;


	private function __construct(
		public Decision $decision,
		public MeasuringMethod $method,
		/** @var array<int|string, mixed>  name => the value as the configuration writes it */
		public array $candidates,
		/** the value letting every candidate pass, where the domain takes one */
		public mixed $any,
	) {
	}


	/** @param  list<mixed>  $standardValues  what the standards give the decision, `keep` among them */
	public static function for(Decision $decision, array $standardValues = []): self
	{
		$candidates = self::findCandidates($decision, $standardValues);
		$domain = $decision->domain;
		$method = match (true) {
			isset(self::MeasuredOnParameter[$decision->path]) => MeasuringMethod::Ladder,
			$domain instanceof Domains\Words, $domain instanceof Domains\Shapes => count($candidates) === 1
				? MeasuringMethod::OneSided
				: MeasuringMethod::Values,
			$domain instanceof Domains\Count => MeasuringMethod::Values,
			default => MeasuringMethod::Policy,
		};
		return new self(
			$decision,
			$method,
			$candidates,
			$method === MeasuringMethod::Values ? self::findAnyValue($decision, $candidates) : null,
		);
	}


	/**
	 * The values to measure the decision under: a word, the example of a shape, a count from 0 to 2 within the bounds of
	 * the domain and the counts the standards give, the steps of the ladder; none for a policy.
	 * @param  list<mixed>  $standardValues
	 * @return array<int|string, mixed>
	 */
	private static function findCandidates(Decision $decision, array $standardValues): array
	{
		$domain = $decision->domain;
		if (isset(self::MeasuredOnParameter[$decision->path])) {
			return array_combine(array_map(strval(...), self::LineLengths), self::LineLengths);
		}

		return match (true) {
			$domain instanceof Domains\Words => array_combine(array_keys($domain->words), array_keys($domain->words)),
			$domain instanceof Domains\Shapes => array_map(fn(array $shape) => $shape[0], $domain->shapes),
			$domain instanceof Domains\Count => self::findCounts($domain, $standardValues),
			default => [],
		};
	}


	/**
	 * @param  list<mixed>  $standardValues
	 * @return array<int, int>
	 */
	private static function findCounts(Domains\Count $domain, array $standardValues): array
	{
		$counts = [];
		$max = min($domain->max ?? self::MaxCount, self::MaxCount);
		foreach ([...range($domain->min, max($domain->min, $max)), ...$standardValues] as $value) {
			if (is_int($value) && $value >= $domain->min && ($domain->max === null || $value <= $domain->max)) {
				$counts[$value] = $value;
			}
		}

		ksort($counts);
		return $counts;
	}


	/**
	 * The list of the words or the shapes, the range of the counts; null where the domain takes no tolerance.
	 * @param  array<int|string, mixed>  $candidates
	 */
	private static function findAnyValue(Decision $decision, array $candidates): mixed
	{
		$domain = $decision->domain;
		return match (true) {
			count($candidates) < 2 => null,
			($domain instanceof Domains\Words || $domain instanceof Domains\Shapes) && $domain->tolerance => array_values($candidates),
			$domain instanceof Domains\Count && $domain->range => min($candidates) . '-' . max($candidates),
			default => null,
		};
	}


	/**
	 * The configurations to probe, each with the decision under one of its values over the base, the value letting every
	 * candidate pass under `path=*`.
	 * @param  array<string, mixed>  $base
	 * @return array<string, array<string, mixed>>  `path=name` => the configuration
	 */
	public function buildProbes(array $base): array
	{
		$probes = [];
		foreach ($this->candidates as $name => $value) {
			$probes["{$this->decision->path}=$name"] = $this->lay($base, $value);
		}

		if ($this->any !== null) {
			$probes["{$this->decision->path}=*"] = $this->lay($base, $this->any);
		}

		return $probes;
	}


	/**
	 * The value of the decision, or for a requirement measured on a parameter the step of the parameter with the
	 * requirement.
	 * @param  array<string, mixed>  $base
	 * @return array<string, mixed>
	 */
	private function lay(array $base, mixed $value): array
	{
		$path = $this->decision->path;
		$parameter = self::MeasuredOnParameter[$path] ?? null;
		return $parameter === null
			? Helpers::placeValue($base, $path, $value)
			: Helpers::placeValue(Helpers::placeValue($base, $path, 'forbidden'), $parameter, $value);
	}


	/**
	 * What the file writes for the decision from what its probes report; null where the code says what the standard
	 * does, or nothing at all.
	 * @param  array<string, ProbeResult>  $results  by the keys of `buildProbes()`
	 * @param  \Closure(string, mixed): bool  $isStandard  whether the value of the path is the one of the standard
	 */
	public function decide(array $results, int $sampled, \Closure $isStandard): ?ProposedValue
	{
		$path = $this->decision->path;
		$probes = $this->selectProbes($results);
		$proposed = match ($this->method) {
			MeasuringMethod::Values => $this->decideValues($results),
			MeasuringMethod::OneSided => $this->decideOneSided(array_values($probes)[0], $sampled),
			MeasuringMethod::Ladder => $this->decideLadder($probes, $sampled),
			MeasuringMethod::Policy => $this->decision->takesKeep()
				? new ProposedValue($path, 'keep', 'not checked: your code does not follow the list of the standard')
				: null,
		};
		return $proposed === null || $isStandard($proposed->path, $proposed->value) ? null : $proposed;
	}


	/**
	 * How the code writes a decision of several values, from what its probes report, counted by files where it is a
	 * property of the file and by places otherwise.
	 * @param  array<string, ProbeResult>  $results  by the keys of `buildProbes()`
	 */
	public function measure(array $results): Measurement
	{
		$path = $this->decision->path;
		return $path === 'indentation.unit' || str_starts_with($path, 'file.')
			? Measurement::fromFileProbes($this->selectProbes($results), $path)
			: Measurement::fromPlaceProbes($this->selectProbes($results), $path, 'places', $results["$path=*"] ?? null);
	}


	/**
	 * The files the probe of a candidate reports in, which a fix under that value would rewrite; null for a value no
	 * probe ran under.
	 * @param  array<string, ProbeResult>  $results  by the keys of `buildProbes()`
	 */
	public function countFiles(array $results, mixed $value): ?int
	{
		$path = $this->decision->path;
		if (isset(self::MeasuredOnParameter[$path])) {
			return null;
		}

		$wanted = $this->decision->accept($value)->toData();
		$name = array_find_key($this->candidates, fn(mixed $candidate) => $this->decision->accept($candidate)->toData() === $wanted);
		return $name === null ? null : ($results["$path=$name"] ?? null)?->countFiles($path);
	}


	/**
	 * The results of the probes of the candidates, by the name of the candidate.
	 * @param  array<string, ProbeResult>  $results
	 * @return array<int|string, ProbeResult>
	 */
	private function selectProbes(array $results): array
	{
		$probes = [];
		foreach (array_keys($this->candidates) as $name) {
			$probes[$name] = $results["{$this->decision->path}=$name"];
		}

		return $probes;
	}


	/**
	 * A decision of several values: the one the code holds to, the ones it has together where the decision takes them,
	 * else unchecked.
	 * @param  array<string, ProbeResult>  $results
	 */
	private function decideValues(array $results): ?ProposedValue
	{
		$path = $this->decision->path;
		$measurement = $this->measure($results);
		$tolerated = array_map(fn(string $name) => $this->candidates[$name], $measurement->findTolerated() ?? []);
		return match ($measurement->classify($this->any !== null)) {
			Classification::Settled, Classification::SettledWeakly => new ProposedValue(
				$path,
				$this->candidates[(string) $measurement->findCommonest()],
				self::describeShare($measurement),
			),
			Classification::Tolerated => new ProposedValue(
				$path,
				is_int($tolerated[0] ?? null) ? min($tolerated) . '-' . max($tolerated) : $tolerated,
				'your code, ' . $measurement->describe(),
			),
			Classification::Mixed => new ProposedValue($path, 'keep', 'not checked: your code has ' . $measurement->describe()),
			Classification::Absent => null,
		};
	}


	/** A decision of one value, whose rule reports only what disagrees: the standard where few files would change, else unchecked. */
	private function decideOneSided(ProbeResult $probe, int $sampled): ?ProposedValue
	{
		$files = $probe->countFiles($this->decision->path);
		return $files <= self::StragglerShare * $sampled
			? null
			: new ProposedValue($this->decision->path, 'keep', sprintf('not checked: a fix would change %d of %d files', $files, $sampled));
	}


	/**
	 * The width of a line: the smallest step of the ladder few files exceed.
	 * @param  array<int|string, ProbeResult>  $probes
	 */
	private function decideLadder(array $probes, int $sampled): ?ProposedValue
	{
		$path = $this->decision->path;
		foreach ($this->candidates as $name => $step) {
			$files = $probes[$name]->countFiles($path);
			if ($files <= self::LineLengthShare * $sampled || $step === 'none') {
				$widest = self::LineLengths[count(self::LineLengths) - 2];
				return new ProposedValue(self::MeasuredOnParameter[$path], $step, match (true) {
					$step === 'none' => sprintf(
						'your code: %d of %d files have a line over %d',
						$probes[$widest]->countFiles($path),
						$sampled,
						$widest,
					),
					$files > 0 => "your code, all but $files of $sampled files",
					default => "your code, all $sampled files",
				});
			}
		}

		return null;
	}


	private static function describeShare(Measurement $measurement): string
	{
		$share = $measurement->getShare((string) $measurement->findCommonest());
		return $share === 1.0
			? 'your code, ' . ($measurement->opportunities === 1 ? '' : 'all ') . $measurement->formatPlaces()
			: sprintf('your code, %d%% of %s', round(100 * $share), $measurement->formatPlaces());
	}
}
