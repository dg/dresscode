<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Config\{PackageDiscovery, ProjectPackages, RunnerFactory, Versions};
use DressCode\ConfigurationException;
use DressCode\Engine\Helpers;
use DressCode\Measuring\NamespaceDeclarations;
use Nette\Utils\FileSystem;


/**
 * Runs the steps of a plan over the working tree of the project and stops where a human is needed. A fix is made topic
 * by topic over the project as it is, so that a topic of the bridge fixes what the versions allowed today reach and
 * one of the jump, after the constraints are raised, what the new ones do, each in the style the code keeps; the files
 * a step changes are kept as they were before it (`Snapshots`) and go back where the tests then fail. Whether a step is
 * done is read from the code alone: a fix leaving nothing changes nothing, a step of Composer whose constraints stand
 * is passed, so the run goes on from a working tree a team committed, squashed or rebased.
 * @internal
 */
final readonly class PlanRunner
{
	private string $configFile;
	private string $workDir;
	private Git $git;


	/** @throws ConfigurationException  for a project without a configuration file */
	public function __construct(
		public Project $project,
		/** the fixes take the types of the code from PHPStan, which the caller knows is installed */
		private bool $types = false,
		/** each topic is committed with the files it changed, once its step passed the tests */
		private bool $commit = false,
		/** @var ?\Closure(string): void  told what is being done */
		private ?\Closure $onProgress = null,
	) {
		$this->configFile = $project->configFile ?? throw new ConfigurationException(
			'The upgrade rewrites the code in the style the configuration of the project keeps, and the project has none; write it with `dresscode init` first.',
		);
		$this->workDir = self::getWorkDir($project);
		$this->git = new Git($project->root);
	}


	/**
	 * Forgets what the runs of a plan of the project kept, the results of the tests before the first step and the
	 * snapshots, once the plan is done or another one is made.
	 */
	public static function forget(Project $project): void
	{
		FileSystem::delete(self::getWorkDir($project));
	}


	/** Whether the tracked files of the working tree have changes of their own, which the changes of a run mix with. */
	public function hasOwnChanges(): bool
	{
		return $this->git->hasOwnChanges();
	}


	/**
	 * Runs the steps the plan does not leave out, or with `$only` that one alone; at a step of Composer whose constraints
	 * are not raised yet it asks `$raise`, which raises them or tells it did not.
	 * @param  ?\Closure(Step): bool  $raise
	 * @throws ConfigurationException
	 */
	public function run(Plan $plan, ?int $only = null, ?\Closure $raise = null): RunOutcome
	{
		if ($this->commit && $this->git->hasOwnChanges()) {
			throw new ConfigurationException('A commit of a topic takes the files it changed whole, and the working tree has changes of its own; commit or stash them first.');
		}

		$snapshots = new Snapshots("$this->workDir/snapshots");
		$baseline = $plan->testCommand === null ? null : $this->findBaseline($plan->testCommand);
		$kept = $this->project->findKeptDecisions();
		$declarations = $this->project->collectDeclarations();
		$steps = $plan->findSteps();
		$last = array_key_last(array_filter($steps, fn(Step $step) => $step->kind === StepKind::Fix));
		$fixed = $tasks = [];
		$raised = false; // the step follows a raise of Composer
		foreach ($steps as $number => $step) {
			if ($only !== null && $number !== $only) {
				continue;
			} elseif ($step->kind === StepKind::Composer) {
				if (!$this->isRaised($step) && (!$raise?->__invoke($step) || !$this->isRaised($step))) {
					return new RunOutcome(RunStop::Composer, $number, $fixed, $tasks);
				}

				$raised = true;
				continue;
			} elseif ($step->kind !== StepKind::Fix) {
				continue;
			}

			$reporters = [];
			foreach ($step->only as $topic) {
				$this->onProgress?->__invoke("step $number, fixing $topic");
				$reporters[$topic] = $this->fixTopic($topic, $kept, $declarations, $number, $snapshots);
				$fixed[$number][$topic] = array_keys($reporters[$topic]->changed);
			}

			// the whole suite after the jump and after the last step, the quick one after the others
			$command = $raised || $number === $last ? $plan->testCommand : $plan->quickTestCommand ?? $plan->testCommand;
			if ($baseline !== null && $command !== null) {
				$this->onProgress?->__invoke("step $number, running the tests");
				$failures = TestResults::collect($command, $this->project->root)?->findNewFailures($baseline);
				if ($failures !== []) {
					$snapshots->restore($number, $this->project->root);
					return new RunOutcome(RunStop::Tests, $number, $fixed, $tasks, $failures ?? []);
				}
			}

			$snapshots->drop($number);
			if ($this->commit) {
				$this->commitStep($number, $reporters);
			}

			$left = array_map(fn(TopicReporter $reporter) => $reporter->tasks, $reporters);
			$tasks = array_filter([...$tasks, ...$left]); // the last fix of a topic tells what it leaves
			if ($raised && array_filter($left) !== []) {
				return new RunOutcome(RunStop::Tasks, $number, $fixed, $tasks);
			}

			$raised = false;
		}

		if ($only === null) {
			self::forget($this->project);
		}

		return new RunOutcome(RunStop::Done, null, $fixed, $tasks);
	}


	/**
	 * The results of the tests before the first step, run once and kept for the runs that follow.
	 * @throws ConfigurationException  where the command wrote no results
	 */
	private function findBaseline(string $command): TestResults
	{
		$file = "$this->workDir/tests-baseline.json";
		$baseline = TestResults::fromFile($file);
		if ($baseline === null) {
			$this->onProgress?->__invoke('running the tests before the first step');
			$baseline = TestResults::collect($command, $this->project->root)
				?? throw new ConfigurationException("The command testing the project, `$command`, wrote no JUnit file where `{junit}` stands.");
			$baseline->save($file);
		}

		return $baseline;
	}


	/**
	 * Fixes the topic over the project as it is, keeping each file it changes as it was before the step, the names
	 * resolved for certain with what the namespaces declare where they were measured, as the estimate resolves them.
	 * @param  list<string>  $kept  the decisions of the style the code keeps
	 */
	private function fixTopic(
		string $topic,
		array $kept,
		?NamespaceDeclarations $declarations,
		int $step,
		Snapshots $snapshots,
	): TopicReporter
	{
		$project = $this->project;
		$factory = new RunnerFactory;
		$plugins = PackageDiscovery::discover($factory->readProjectPackages($project->config, $project->root))->findDataPlugins();
		$resolution = $factory->resolve(
			$project->config,
			$project->root,
			$project->createLayer($plugins, $this->types, declarations: $declarations),
			[$topic, ...$kept],
			laid: Estimator::Sets,
		);
		$runner = $factory->createRunner($resolution, cache: false, configFile: $this->configFile);
		$reporter = new TopicReporter($snapshots, $step);
		$runner->run($runner->findFiles($project->config->paths, configured: $project->config->paths), true, $reporter);
		return $reporter;
	}


	/**
	 * Whether `composer.json` requires every package of the step with a lower bound the step reaches, allowing the newest
	 * version the step names: `^8.4` and `~8.4.0` raise `^8.1` to `^8.4`, `^3.2 || ^4.0` widens `^3.2`.
	 * @phpstan-impure
	 */
	private function isRaised(Step $step): bool
	{
		$required = ProjectPackages::read($this->project->root)->required;
		return array_all($step->require, function (string $constraint, string $package) use ($required): bool {
			$parts = preg_split('~\s*\|\|?\s*~', trim($constraint)) ?: [];
			$lowest = Versions::findLowestVersion($constraint) ?? '0';
			$newest = Versions::findLowestVersion((string) end($parts)) ?? $lowest;
			return isset($required[$package])
				&& version_compare(Versions::findLowestVersion($required[$package]) ?? '0', $lowest, '>=')
				&& Versions::isSubset($newest, $required[$package]);
		});
	}


	/**
	 * Commits each topic of the step with the files it changed as the topic left them, which a later topic of the step
	 * may have changed again, so the working tree ends as the last topic left it.
	 * @param  array<string, TopicReporter>  $reporters  topic => the reporter of its fix
	 */
	private function commitStep(int $step, array $reporters): void
	{
		foreach ($reporters as $topic => $reporter) {
			foreach ($reporter->changed as $path => $text) {
				FileSystem::write(Helpers::toAbsolutePath($path, $this->project->root), $text);
			}

			if ($reporter->changed !== []) {
				$this->git->commit(array_keys($reporter->changed), "upgrade: $topic", "DressCode-Upgrade-Step: $step");
			}
		}
	}


	private static function getWorkDir(Project $project): string
	{
		return $project->getCacheDir() . '/upgrade/' . substr(hash('xxh128', $project->root), 0, 16);
	}
}
