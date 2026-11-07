<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use DressCode\Config\{Draft, ResolvedConfig};
use DressCode\ConfigurationException;
use DressCode\Engine\Helpers;
use function count, sprintf;


/**
 * The configuration `dresscode init` proposes for a project: the standard, and over it what the code departs from it
 * in, the residue of the standard. Every decision of the residue is measured by its plan, in two layers, the first laid
 * under the probes of the second; what the code holds to is written, what it does not decide is left unchecked, and the
 * configuration is then checked over the whole scope, a decision whose fix would change more files than the budget
 * allows left unchecked too. It never writes `fixRisky`.
 * @internal
 */
final class Proposal
{
	/** a directory writing a decision of the file otherwise is told where this many of its files do, and this share */
	private const RegionFiles = 5;
	private const RegionShare = 0.9;

	/** @var array<string, ProposedValue>  path => what the file writes for it */
	public private(set) array $values = [];

	/** what a check of the configuration over the whole scope says, null before it ran */
	public private(set) ?Verification $verification = null;

	/** what the same check says with the types of the code, which the measuring goes without; null before it ran */
	public private(set) ?Verification $typesVerification = null;

	/** @var array<string, MeasuredDecision>  path => how it was measured and what its probes said */
	private array $measured = [];


	private function __construct(
		public readonly ProjectFacts $project,
		public readonly Scope $scope,
		/** the files the decisions are measured on */
		public readonly FileSample $sample,
		public readonly NamespaceDeclarations $declarations,
		public readonly StandardChoice $standard,
		private readonly ProbeRunner $runner,
		private readonly Budget $budget,
		/** @var ?\Closure(string, string): void */
		private readonly ?\Closure $onPhase,
	) {
	}


	/**
	 * Measures the residue of the standard over a sample of the scope; a file that does not parse leaves the scope.
	 * @param  ?list<string>  $presets  the standard, named as the configuration names it; null to choose
	 * @param  ?int  $budget  the files the first fix may change; by default a share of the scope
	 * @param  ?\Closure(string, string): void  $onPhase  the phase, `Measuring` or `Checking`, and what it does now, said
	 *     before it starts
	 * @throws ConfigurationException
	 */
	public static function measure(
		ProjectFacts $project,
		Scope $scope,
		ProbeRunner $runner,
		?array $presets = null,
		?int $budget = null,
		?\Closure $onPhase = null,
	): self
	{
		$onPhase?->__invoke('Measuring', 'reading the declarations of the namespaces');
		$declarations = NamespaceDeclarations::collect($project->root, $scope->files);
		$scope->leaveOut($declarations->unparsed, LeftOutReason::Unparsed);
		$sample = FileSample::pick($project->root, $scope->files);

		$candidates = StandardChoice::listCandidates($project, $presets);
		$onPhase?->__invoke('Measuring', sprintf(
			'checking %d files against %s',
			count($sample->files),
			count($candidates) > 1 ? 'every standard' : implode(', ', $candidates[0]),
		));
		$base = new Draft()->setScope($sample->files, fileExtensions: $scope->listExtensions());
		if ($declarations->unparsed === []) { // as the configuration written resolves the names
			$base->setNamespaces($declarations->functions, $declarations->constants);
		}

		$base = $base->toArray();
		$standard = StandardChoice::measure($project, $presets, $runner, $base);
		$scope->leaveOut($standard->unparsed, LeftOutReason::Unparsed);

		$proposal = new self(
			$project,
			$scope,
			$sample,
			$declarations,
			$standard,
			$runner,
			new Budget(count($scope->files), $budget),
			$onPhase,
		);
		$onPhase?->__invoke('Measuring', 'measuring ' . count($standard->residue) . ' decisions over ' . count($sample->files) . ' files');
		$proposal->measureResidue();
		return $proposal;
	}


	/**
	 * Measures the decisions of the residue in two layers, what the file writes for those of the first laid under the
	 * probes of the second, each probe under the standard and narrowed to its decision, so that its rule reads the other
	 * decisions as the standard gives them.
	 * @throws ConfigurationException
	 */
	private function measureResidue(): void
	{
		$base = [...$this->buildProbeConfig(), 'paths' => $this->sample->files];
		$residue = $this->standard->residue;
		foreach ([array_intersect(MeasurementPlan::FirstLayer, $residue), array_diff($residue, MeasurementPlan::FirstLayer)] as $layer) {
			$plans = $probes = [];
			foreach ($layer as $path) {
				$plans[$path] = MeasurementPlan::fromDecision(
					$this->standard->decisions[$path]->decision,
					StandardChoice::listStandardValues($path),
				);
				$probes += $plans[$path]->buildProbes($base);
			}

			$probeResults = $probes ? $this->runner->run($probes) : [];
			foreach ($plans as $path => $plan) {
				$own = array_filter($probeResults, fn(string $key) => str_starts_with($key, "$path="), ARRAY_FILTER_USE_KEY);
				$this->measured[$path] = new MeasuredDecision($plan, $own);
				$proposed = $plan->decide($own, count($this->sample->files), $this->standard->isStandard(...));
				if ($proposed === null) {
					continue;
				}

				$this->values[$proposed->path] = $proposed;
				if (in_array($path, MeasurementPlan::FirstLayer, true) && !$proposed->isKept()) {
					$base = Helpers::placeValue($base, $proposed->path, $proposed->value);
				}
			}
		}
	}


	/**
	 * Writes the value the user gives a decision, whatever was measured, which the budget then never leaves unchecked.
	 * @throws ConfigurationException
	 */
	public function answer(string $path, mixed $value): void
	{
		$standard = $this->standard->decisions[$path] ?? throw new ConfigurationException("Decision `$path` is not one the standard knows.");
		$standard->decision->accept($value);
		$this->values[$path] = new ProposedValue($path, $value, 'your answer', ValueOrigin::Answered);
	}


	/**
	 * Checks the configuration over the whole scope and leaves unchecked the decisions whose fix would change more files
	 * than the budget allows, the costliest first, then checks it again; a file such a check finds unparsable leaves the
	 * scope, and the last check is what a fix would change and what it leaves.
	 * @throws ConfigurationException
	 */
	public function verify(): Verification
	{
		$this->onPhase?->__invoke('Checking', 'the configuration over all ' . count($this->scope->files) . ' files');
		$check = fn() => $this->runner->run(['scope' => new Probe($this->buildProbeConfig())])['scope'];
		$result = $check();
		$answered = array_keys(array_filter($this->values, fn(ProposedValue $proposed) => $proposed->origin === ValueOrigin::Answered));
		$dropped = $this->budget->findDropped($result->fixable, $answered);
		foreach ($dropped as $path) {
			$this->values[$path] = ProposedValue::overBudget($path, count($result->fixable[$path]), $result->checkedFiles);
		}

		$this->scope->leaveOut($result->syntaxErrors, LeftOutReason::Unparsed);
		if ($dropped || $result->syntaxErrors) {
			$result = $check();
		}

		return $this->verification = new Verification($result, $this->budget->files);
	}


	/**
	 * Whether the configuration asks for the types of the code, the project having PHPStan; the measuring goes without
	 * them, so a check with them may report what it did not.
	 */
	public function usesTypeAnalysis(): bool
	{
		return $this->project->hasPackage('phpstan/phpstan');
	}


	/**
	 * Checks the configuration as written, the types of the code among it, once over the whole scope, as the measuring
	 * and the verification go without them, which would start PHPStan in every probe; null where it asks for no types.
	 */
	public function verifyWithTypes(): ?Verification
	{
		if (!$this->usesTypeAnalysis()) {
			return null;
		}

		$this->onPhase?->__invoke('Checking', 'the configuration with the types of the code');
		$result = $this->runner->run(['types' => new Probe($this->toDraft()->toArray())])['types'];
		return $this->typesVerification = new Verification($result, $this->budget->files);
	}


	/**
	 * The files a rule failed in under some probe of the measuring or of the checks of the configuration, whose places
	 * nothing counted, so that the decisions rest on the other files.
	 * @return list<string>
	 */
	public function findFailedFiles(): array
	{
		$failed = [
			...$this->standard->failed,
			...$this->verification->result->failed ?? [],
			...$this->typesVerification->result->failed ?? [],
			...array_merge(...array_map(
				fn(MeasuredDecision $measured) => $measured->listFailedFiles(),
				array_values($this->measured),
			)),
		];
		$failed = array_values(array_unique($failed));
		sort($failed);
		return $failed;
	}


	/** Whether the decisions rest on part of the code only, a rule having failed in some files. */
	public function isPartial(): bool
	{
		return $this->findFailedFiles() !== [];
	}


	/**
	 * The directories of the sample whose files write a decision of the file otherwise than the configuration does, in
	 * `RegionFiles` files at least and in `RegionShare` of those writing one value; an override would keep the value
	 * there, and the file writes none, as one style per project is what init proposes.
	 * @return list<Region>
	 */
	public function findRegions(): array
	{
		$regions = [];
		foreach ($this->measured as $path => $measured) {
			$plan = $measured->plan;
			if ($plan->method !== MeasuringMethod::Values || !$plan->isFileProperty()) {
				continue;
			}

			$decision = $this->standard->decisions[$path];
			$written = isset($this->values[$path])
				? $decision->decision->accept($this->values[$path]->value)->toData()
				: $decision->value->toData();
			$own = array_find_key(
				$plan->candidates,
				fn(mixed $candidate) => $decision->decision->accept($candidate)->toData() === $written,
			);
			if ($own === null) { // a tolerance, or `keep`
				continue;
			}

			$counts = [];
			foreach ($plan->groupFilesByValue($measured->results) as $name => $files) {
				foreach ($files as $file) {
					$directory = $this->sample->findDirectory($file);
					$counts[$directory][$name] = ($counts[$directory][$name] ?? 0) + 1;
				}
			}

			foreach ($counts as $directory => $byValue) {
				arsort($byValue);
				$name = array_key_first($byValue);
				if ($directory !== '' && $name !== $own && $byValue[$name] >= self::RegionFiles
					&& $byValue[$name] >= self::RegionShare * array_sum($byValue)
				) {
					$regions[] = new Region($directory, $path, $plan->candidates[$name], $byValue[$name], array_sum($byValue));
				}
			}
		}

		return $regions;
	}


	/** Whether the numbers of the measuring are estimates, the sample being smaller than the scope. */
	public function isSampled(): bool
	{
		return count($this->sample->files) < count($this->scope->files);
	}


	/**
	 * What has moved since the configuration that exists was written: every decision init would write otherwise, and
	 * those it sets apart from the standard while the code holds to the standard, which no residue shows.
	 * @return list<Shift>
	 */
	public function findShifts(ResolvedConfig $current): array
	{
		$shifts = [];
		foreach ($this->values as $path => $proposed) {
			$written = ($current->decisions[$path] ?? null)?->value->toWrittenText();
			$measured = $this->standard->formatValue($path, $proposed->value);
			if ($written !== $measured) {
				$shifts[] = new Shift(ShiftKind::Measured, $path, $written, $measured, $proposed->comment);
			}
		}

		foreach ($current->decisions as $path => $decision) {
			$standard = $this->standard->decisions[$path] ?? null;
			if (
				$standard !== null
				&& $standard->inactive === null
				&& $decision->inactive === null
				&& !isset($this->values[$path])
				&& !in_array($path, $this->standard->residue, true)
				&& $decision->value->toData() !== $standard->value->toData()
			) {
				$shifts[] = new Shift(
					ShiftKind::HeldToStandard,
					$path,
					$decision->value->toWrittenText(),
					$standard->value->toWrittenText(),
				);
			}
		}

		return $shifts;
	}


	/**
	 * The configuration as `dresscode.neon` writes it, every value the standard does not give with what it rests on, over
	 * the scope as it stands: after `verify()` without the files its checks found unparsable.
	 */
	public function toDraft(): Draft
	{
		$failed = $this->findFailedFiles();
		$draft = new Draft('made to measure by `dresscode init`; run it again to see what has moved since, it writes nothing over this file'
			. ($failed === [] ? '' : "\nnot measured: a rule failed in " . implode(', ', $failed) . ', so the decisions rest on the other files'));
		$draft->setUse($this->standard->presets, $this->standard->describeReason());
		// a file that does not parse may declare what the lists lack, so the names are not resolved for certain then
		if ($this->declarations->unparsed === []) {
			$draft->setNamespaces(
				$this->declarations->functions,
				$this->declarations->constants,
				$this->declarations->isEmpty()
					? "the namespaces of the scope declare no function and no constant, so an unqualified name in a namespace\n"
						. 'is resolved for certain; one declared later is reported until it is listed'
					: "the namespaces of the scope declare these functions and constants and no others, so an unqualified name\n"
						. 'in a namespace is resolved for certain; one declared later is reported until it is listed',
			);
		}
		if ($this->usesTypeAnalysis()) {
			$draft->setTypeAnalysis('phpstan/phpstan is installed, so the rules get the types of the code');
		}

		foreach ($this->findWrittenValues() as $path => $proposed) {
			$draft->setDecision($path, $proposed->value, $proposed->comment);
		}

		$decisions = $this->standard->decisions;
		$this->scope->writeInto(
			$draft,
			$this->project->formatterConfig,
			fn(string $path) => isset($decisions[$path]) && $decisions[$path]->decision->takesKeep() && !$decisions[$path]->value->isKept(),
		);
		return $draft;
	}


	/**
	 * The values the configuration writes: those the standard does not give, as an answer giving the value of the
	 * standard has nothing to write.
	 * @return array<string, ProposedValue>  path => the value
	 */
	public function findWrittenValues(): array
	{
		return array_filter(
			$this->values,
			fn(ProposedValue $proposed) => !$this->standard->isStandard($proposed->path, $proposed->value),
		);
	}


	/**
	 * The configuration a probe checks with, the types left out, which would start PHPStan in every probe.
	 * @return array<string, mixed>
	 */
	private function buildProbeConfig(): array
	{
		return array_diff_key($this->toDraft()->toArray(), ['typeAnalysis' => true]);
	}
}
