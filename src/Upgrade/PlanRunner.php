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
use function count;


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
		$steps = $plan->findSteps();
		if ($only !== null && !isset($steps[$only])) {
			throw new ConfigurationException(isset($plan->steps[$only - 1])
				? "Step $only of the plan is left out with `skip: true`, so it is not run."
				: "The plan has no step $only; it has " . count($plan->steps) . '.');
		} elseif ($this->commit && $this->git->hasOwnChanges()) {
			throw new ConfigurationException('A commit of a topic takes the files it changed whole, and the working tree has changes of its own; commit or stash them first.');
		}

		$snapshots = new Snapshots("$this->workDir/snapshots");
		$commands = array_values(array_filter([$plan->testCommand, $plan->quickTestCommand]));
		$baselines = array_combine($commands, array_map($this->findBaseline(...), $commands));
		$kept = $this->project->findKeptDecisions();
		$declarations = $this->project->collectDeclarations();
		$last = array_key_last(array_filter($steps, fn(Step $step) => $step->kind === StepKind::Fix));
		$fixed = $tasks = [];
		$raised = false; // the step follows a raise of Composer
		$leaving = 0; // the last step that left tasks
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

			$snapshots->drop($number); // what an interrupted run of the step kept is not what the files are now
			$written = $errors = $left = [];
			foreach ($step->only as $topic) {
				$this->onProgress?->__invoke("step $number, fixing $topic");
				$reporter = $this->runTopic($topic, $kept, $declarations, $plan->paths);
				[$written[$topic], $unwritten] = $this->writeTopic($number, $reporter, $snapshots);
				$errors += $reporter->failed + $unwritten;
				$left[$topic] = $reporter->tasks;
				$fixed[$number][$topic] = array_keys($written[$topic]);
			}

			// the whole suite after the jump and after the last step, the quick one after the others
			$command = $raised || $number === $last
				? $plan->testCommand ?? $plan->quickTestCommand
				: $plan->quickTestCommand ?? $plan->testCommand;
			if ($command !== null) {
				$this->onProgress?->__invoke("step $number, running the tests");
				$failures = TestResults::collect($command, $this->project->root)?->findNewFailures($baselines[$command]);
				if ($failures !== []) {
					$errors += $snapshots->restore($number, $this->project->root);
					return new RunOutcome(RunStop::Tests, $number, $fixed, $tasks, $failures ?? [], $errors);
				}
			}

			$snapshots->drop($number);
			if ($this->commit) {
				$this->commitStep($number, $written);
			}

			$tasks = array_filter([...$tasks, ...$left]); // the last fix of a topic tells what it leaves
			$leaving = array_filter($left) === [] ? $leaving : $number;
			if ($errors !== []) {
				return new RunOutcome(RunStop::Errors, $number, $fixed, $tasks, errors: $errors);
			} elseif ($raised && $leaving === $number) {
				return new RunOutcome(RunStop::Tasks, $number, $fixed, $tasks);
			}

			$raised = false;
		}

		if ($tasks !== []) { // a plan is done once nothing of it is left for a human
			return new RunOutcome(RunStop::Tasks, $leaving, $fixed, $tasks);
		} elseif ($only === null) {
			self::forget($this->project);
		}

		return new RunOutcome(RunStop::Done, null, $fixed, $tasks);
	}


	/**
	 * The results of the command of the tests before the first step, run once and kept for the runs that follow, each
	 * command its own.
	 * @throws ConfigurationException  where the command gave no results
	 */
	private function findBaseline(string $command): TestResults
	{
		$file = "$this->workDir/tests-baseline-" . substr(hash('xxh128', $command), 0, 16) . '.json';
		$baseline = TestResults::fromFile($file);
		if ($baseline === null) {
			$this->onProgress?->__invoke('running the tests before the first step');
			$baseline = TestResults::collect($command, $this->project->root)
				?? throw new ConfigurationException("The command testing the project, `$command`, wrote no JUnit report where `{junit}` stands, or did not end in time.");
			$baseline->save($file);
		}

		return $baseline;
	}


	/**
	 * What the fixes of the steps the plan does not leave out leave for a human in the code as it is now, changing
	 * nothing; a task confirmed by a suppression or the baseline is done, and so is not among them.
	 * @return array<string, list<Task>>  topic => its tasks
	 * @throws ConfigurationException
	 */
	public function findTasks(Plan $plan): array
	{
		$kept = $this->project->findKeptDecisions();
		$declarations = $this->project->collectDeclarations();
		$tasks = [];
		foreach ($plan->findSteps() as $step) {
			foreach ($step->kind === StepKind::Fix ? $step->only : [] as $topic) {
				$this->onProgress?->__invoke("looking at $topic");
				$tasks[$topic] = $this->runTopic($topic, $kept, $declarations, $plan->paths)->tasks;
			}
		}

		return array_filter($tasks);
	}


	/**
	 * The comments `dresscode:ignore` about an upgrade in the files of the plan that give no reason after ` -- `, which
	 * only put a task off.
	 * @return list<array{path: string, line: int, comment: string}>  the file relative to the root
	 * @throws ConfigurationException
	 */
	public function findPostponed(Plan $plan): array
	{
		$postponed = [];
		foreach ($this->project->findFiles($plan->paths) as $path) {
			$code = FileSystem::read(Helpers::toAbsolutePath($path, $this->project->root));
			foreach (\PhpToken::tokenize($code) as $token) {
				// a decision of upgrading, a package of upgrading data, a library or the data of PHP
				if (
					$token->is([T_COMMENT, T_DOC_COMMENT])
					&& preg_match_all(
						'~dresscode:ignore\s+(?:upgrading|upgrade-|php\b|[\w-]+/)[^\n]*~',
						$token->text,
						$matches,
						PREG_OFFSET_CAPTURE,
					)
				) {
					foreach ($matches[0] as [$comment, $offset]) {
						if (!str_contains($comment, ' -- ')) {
							$line = $token->line + substr_count($token->text, "\n", 0, $offset);
							$postponed[] = ['path' => $path, 'line' => $line, 'comment' => rtrim(explode('*/', $comment)[0])];
						}
					}
				}
			}
		}

		return $postponed;
	}


	/**
	 * Runs the topic over the project as it is, writing nothing, the names resolved for certain with what the namespaces
	 * declare where they were measured, as the estimate resolves them.
	 * @param  list<string>  $kept  the decisions of the style the code keeps
	 * @param  list<string>  $paths  within the configured ones, all of them where empty
	 */
	private function runTopic(string $topic, array $kept, ?NamespaceDeclarations $declarations, array $paths): TopicReporter
	{
		$project = $this->project;
		$factory = new RunnerFactory(withoutTypes: !$this->types);
		$plugins = PackageDiscovery::discover($factory->readProjectPackages($project->config, $project->root))->findDataPlugins();
		$resolution = $factory->resolve(
			$project->config,
			$project->root,
			$project->createLayer($plugins, $this->types, declarations: $declarations),
			[$topic, ...$kept],
			laid: Estimator::Sets,
		);
		$runner = $factory->createRunner($resolution, cache: false, configFile: $this->configFile);
		$reporter = new TopicReporter;
		$runner->run($runner->findFiles($paths ?: $project->config->paths, configured: $project->config->paths), false, $reporter);
		return $reporter;
	}


	/**
	 * Writes what the fix of the topic changes, each file kept as it was before the step first, and leaves a file changed
	 * since the fix read it as it is.
	 * @return array{array<string, string>, array<string, string>}  the files written => their text, the files left => why
	 */
	private function writeTopic(int $step, TopicReporter $reporter, Snapshots $snapshots): array
	{
		$written = $left = [];
		foreach ($reporter->changed as $path => [$before, $after]) {
			$file = Helpers::toAbsolutePath($path, $this->project->root);
			if (@file_get_contents($file) !== $before) { // @ a file deleted since is changed too
				$left[$path] = 'The file changed while it was being fixed, so it was not written.';
				continue;
			}

			$snapshots->keep($step, $path, $before, $after);
			FileSystem::write($file, $after);
			$written[$path] = $after;
		}

		return [$written, $left];
	}


	/**
	 * Whether `composer.json` requires every package of the step with a lower bound the step reaches, allowing the newest
	 * version the step names, `^8.4` and `~8.4.0` raising `^8.1` to `^8.4` and `^3.2 || ^4.0` widening `^3.2`, and for a
	 * step that updates, whether the version installed is one the step allows, PHP aside, which the project does not
	 * install.
	 * @phpstan-impure
	 */
	private function isRaised(Step $step): bool
	{
		$project = ProjectPackages::read($this->project->root);
		return array_all($step->require, function (string $constraint, string $package) use ($project, $step): bool {
			$required = $project->required[$package] ?? null;
			$lowests = array_map(
				fn(string $part) => Versions::findLowestVersion($part) ?? '0',
				preg_split('~\s*\|\|?\s*~', trim($constraint)) ?: [],
			);
			usort($lowests, fn(string $a, string $b) => version_compare($a, $b));
			$installed = $project->installed[$package] ?? null; // the version of a branch without an alias is not known
			return $required !== null
				&& version_compare(Versions::findLowestVersion($required) ?? '0', $lowests[0] ?? '0', '>=')
				&& Versions::isSubset((string) end($lowests), $required)
				&& (!$step->update
					|| $package === PackageDiscovery::Php
					|| ($installed !== null && ($installed['version'] === null || Versions::isSubset($installed['version'], $constraint))));
		});
	}


	/**
	 * Commits each topic of the step with the files it changed as the topic left them, which a later topic of the step
	 * may have changed again, so the working tree ends as the last topic left it, a commit git refuses too.
	 * @param  array<string, array<string, string>>  $written  topic => the files its fix wrote => their text
	 * @throws ConfigurationException  where git refuses a commit
	 */
	private function commitStep(int $step, array $written): void
	{
		try {
			foreach ($written as $topic => $files) {
				$this->writeFiles($files);
				if ($files !== []) {
					$this->git->commit(array_keys($files), "upgrade: $topic", "DressCode-Upgrade-Step: $step");
				}
			}
		} catch (ConfigurationException $e) {
			foreach ($written as $files) {
				$this->writeFiles($files);
			}

			throw new ConfigurationException(
				"{$e->getMessage()}; what step $step changed is left uncommitted in the working tree.",
				previous: $e,
			);
		}
	}


	/** @param  array<string, string>  $files  relative to the root => text */
	private function writeFiles(array $files): void
	{
		foreach ($files as $path => $text) {
			FileSystem::write(Helpers::toAbsolutePath($path, $this->project->root), $text);
		}
	}


	private static function getWorkDir(Project $project): string
	{
		return $project->getCacheDir() . '/upgrade/' . substr(hash('xxh128', $project->root), 0, 16);
	}
}
