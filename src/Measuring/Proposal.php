<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use DressCode\{Analyses, ConfigurationException};
use DressCode\Config\{Draft, Loader, ResolvedConfig, RunnerFactory};
use DressCode\Engine\Helpers;
use Nette\Utils\FileSystem;
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
	/** a scope this small is measured whole */
	private const WholeFiles = 1000;
	private const WholeBytes = 8_000_000;

	/** @var array<string, ProposedValue>  path => what the file writes for it */
	public private(set) array $values = [];

	/** what a check of the configuration over the whole scope says, null before it ran */
	public private(set) ?Verification $verification = null;

	/** @var array<string, array{MeasurementPlan, array<string, ProbeResult>}>  path => how it was measured and what its probes said */
	private array $measured = [];


	private function __construct(
		public readonly Project $project,
		public readonly Scope $scope,
		/** the files the decisions are measured on */
		public readonly FileSample $sample,
		public readonly NamespaceDeclarations $declarations,
		public readonly StandardChoice $standard,
	) {
	}


	/**
	 * Measures the residue of the standard over a sample of the scope; a file that does not parse leaves the scope.
	 * @param  ?list<string>  $presets  the standard, named as the configuration names it; null to choose
	 * @param  ?\Closure(string): void  $onPhase  what is being done now, said before it starts
	 * @throws ConfigurationException
	 */
	public static function measure(
		Project $project,
		Scope $scope,
		ProbeRunner $runner,
		?array $presets = null,
		?\Closure $onPhase = null,
	): self
	{
		$onPhase?->__invoke('reading the declarations of the namespaces');
		$declarations = NamespaceDeclarations::collect($project->root, $scope->files);
		$scope->markUnparsed(...$declarations->unparsed);
		$sample = count($scope->files) <= self::WholeFiles
			? FileSample::pick($project->root, $scope->files, self::WholeFiles, self::WholeBytes)
			: FileSample::pick($project->root, $scope->files);

		$candidates = StandardChoice::listCandidates($project, $presets);
		$onPhase?->__invoke(sprintf(
			'checking %d files against %s',
			count($sample->files),
			count($candidates) > 1 ? 'every standard' : implode(', ', $candidates[0]),
		));
		$base = new Draft()
			->setScope($sample->files, fileExtensions: $scope->listExtensions())
			->setNamespaces($declarations->functions, $declarations->constants)
			->toArray();
		$standard = StandardChoice::make($project, $presets, $runner, $base);
		$scope->markUnparsed(...$standard->unparsed);

		$proposal = new self($project, $scope, $sample, $declarations, $standard);
		$onPhase?->__invoke('measuring ' . count($standard->residue) . ' decisions over ' . count($sample->files) . ' files');
		$proposal->measureResidue($runner);
		return $proposal;
	}


	/**
	 * Measures the decisions of the residue in two layers, what the file writes for those of the first laid under the
	 * probes of the second, each probe having its decision alone, no standard beside it.
	 * @throws ConfigurationException
	 */
	private function measureResidue(ProbeRunner $runner): void
	{
		$base = [...array_diff_key($this->buildProbeConfig(), ['use' => true]), 'paths' => $this->sample->files];
		$residue = $this->standard->residue;
		foreach ([array_intersect(MeasurementPlan::FirstLayer, $residue), array_diff($residue, MeasurementPlan::FirstLayer)] as $layer) {
			$plans = $probes = [];
			foreach ($layer as $path) {
				$plans[$path] = MeasurementPlan::for(
					$this->standard->decisions[$path]->decision,
					StandardChoice::listStandardValues($path),
				);
				$probes += $plans[$path]->buildProbes($base);
			}

			$results = $probes ? $runner->run($probes) : [];
			foreach ($plans as $path => $plan) {
				$this->measured[$path] = [$plan, $results];
				$proposed = $plan->decide($results, count($this->sample->files), $this->standard->isStandard(...));
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
	public function answer(string $path, mixed $value, string $comment = 'your answer'): void
	{
		$standard = $this->standard->decisions[$path] ?? throw new ConfigurationException("Decision `$path` is not one the standard knows.");
		$standard->decision->accept($value);
		$this->values[$path] = new ProposedValue($path, $value, $comment, ValueOrigin::Answered);
	}


	/**
	 * Checks the configuration over the whole scope and leaves unchecked the decisions whose fix would change more files
	 * than the budget allows, the costliest first, then checks it again until the rest fits; a file such a check finds
	 * unparsable leaves the scope, and the last check is what a fix would change and what it leaves.
	 * @throws ConfigurationException
	 */
	public function verify(ProbeRunner $runner, Budget $budget): Verification
	{
		$check = fn() => $runner->run(['scope' => $this->buildProbeConfig()])['scope'];
		$result = $check();
		$answered = array_keys(array_filter($this->values, fn(ProposedValue $proposed) => $proposed->origin === ValueOrigin::Answered));
		do {
			// a decision left unchecked changes what the others would fix, so the budget is applied until it holds
			$dropped = $budget->findDropped($result->fixable, $answered);
			foreach ($dropped as $path) {
				$this->values[$path] = ProposedValue::overBudget(
					$path,
					$this->values[$path]->value ?? $this->standard->decisions[$path]->value->toWrittenData(),
					count($result->fixable[$path]),
					count($this->scope->files),
					$result->findExample($path),
				);
			}

			$this->scope->markUnparsed(...$result->syntaxErrors);
			if ($dropped || $result->syntaxErrors) {
				$result = $check();
			}
		} while ($dropped);

		return $this->verification = new Verification($result, $budget->files);
	}


	/**
	 * Takes an answer to a question: writes the decisions it gives; a standard to measure against instead is the
	 * caller's to measure.
	 * @throws ConfigurationException
	 */
	public function apply(Answer $answer): void
	{
		foreach ($answer->values as $path => $value) {
			$this->answer($path, $value, $answer->comment);
		}
	}


	/**
	 * The files of the scope a fix under the value would rewrite, as the probes of the measuring tell, scaled from the
	 * sample to the scope; null for a decision or a value they did not measure.
	 */
	public function estimateFiles(string $path, mixed $value): ?int
	{
		[$plan, $results] = $this->measured[$path] ?? [null, []];
		$files = $plan?->countFiles($results, $value);
		return $files === null ? null : (int) round($files * count($this->scope->files) / max(1, count($this->sample->files)));
	}


	/** Whether the numbers of the measuring are estimates, the sample being smaller than the scope. */
	public function isSampled(): bool
	{
		return count($this->sample->files) < count($this->scope->files);
	}


	/**
	 * The configuration as `dresscode.neon` writes it, every value with what it rests on, over the scope as it stands:
	 * after `verify()` without the files its checks found unparsable.
	 */
	public function toDraft(): Draft
	{
		$draft = new Draft('made to measure by `dresscode init`; run it again to see what has moved since, it writes nothing over this file');
		$draft->setUse($this->standard->presets, $this->standard->describeReason());
		$draft->setScope($this->scope->listPaths(), $this->scope->listExcludePaths(), $this->scope->listExtensions());
		$draft->setNamespaces(
			$this->declarations->functions,
			$this->declarations->constants,
			$this->declarations->isEmpty()
				? "the namespaces of the scope declare no function and no constant, so an unqualified name in a namespace\n"
					. 'is resolved for certain; one declared later is reported until it is listed'
				: "the namespaces of the scope declare these functions and constants and no others, so an unqualified name\n"
					. 'in a namespace is resolved for certain; one declared later is reported until it is listed',
		);
		if ($this->project->hasPackage('phpstan/phpstan')) {
			$draft->setTypeAnalysis('phpstan/phpstan is installed, so the rules get the types of the code');
		}

		foreach ($this->values as $path => $proposed) {
			$draft->setDecision($path, $proposed->value, $proposed->comment);
		}

		$decisions = $this->standard->decisions;
		$this->project->formatterConfig?->addOverrides(
			$draft,
			$this->scope,
			fn(string $path) => isset($decisions[$path]) && $decisions[$path]->decision->takesKeep() && !$decisions[$path]->value->isKept(),
		);
		return $draft;
	}


	/**
	 * The configuration a probe checks with, the types left out, which would start PHPStan in every probe.
	 * @return array<string, mixed>
	 */
	private function buildProbeConfig(): array
	{
		return array_diff_key($this->toDraft()->toArray(), ['typeAnalysis' => true]);
	}


	/**
	 * Resolves the configuration the proposal writes the way a run reads it and checks it against what was measured,
	 * before it is anywhere a run could read it.
	 * @throws \LogicException
	 * @throws ConfigurationException
	 */
	public function checkWritten(): void
	{
		$temp = sys_get_temp_dir() . '/dresscode-init-' . uniqid() . '.neon';
		FileSystem::write($temp, $this->toDraft()->toNeon());
		try {
			$this->checkResolution((new RunnerFactory)->resolve(Loader::loadFile($temp), $this->project->root)->resolvedConfig);
		} finally {
			@unlink($temp); // @ file may have been deleted meanwhile
		}
	}


	/**
	 * What the run makes of the written configuration must be what was measured; anything else is a proposal that says
	 * one thing and does another.
	 * @throws \LogicException
	 */
	public function checkResolution(ResolvedConfig $resolved): void
	{
		$problem = null;
		foreach ($this->values as $path => $proposed) {
			$value = ($resolved->decisions[$path] ?? null)?->value;
			$expected = $this->standard->decisions[$path]->decision->accept($proposed->value);
			if ($value === null || $value->isKept() !== $expected->isKept() || $value->toData() !== $expected->toData()) {
				$problem = "`$path` is not what was measured";
				break;
			}
		}

		$problem ??= match (true) {
			$resolved->nameResolution !== 'certain' => 'the names are not resolved for certain',
			array_diff($this->declarations->functions, array_keys($resolved->namespacedFunctions)) !== []
				=> 'a function the namespaces declare is not listed',
			array_diff($this->declarations->constants, array_keys($resolved->namespacedConstants)) !== []
				=> 'a constant the namespaces declare is not listed',
			// a DressCode without PHPStan beside it goes without the types the project can give
			$this->project->hasPackage('phpstan/phpstan') !== ($resolved->typeAnalysis === 'phpstan')
				&& Analyses\PhpStan::isAvailable() => 'the types are not what the installed packages allow',
			default => null,
		};
		if ($problem !== null) {
			throw new \LogicException("The configuration `dresscode init` wrote does not say what it measured: $problem.");
		}
	}
}
