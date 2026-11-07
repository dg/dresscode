<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Config\ProjectPackages;
use DressCode\ConfigurationException;
use DressCode\Engine\Helpers;
use DressCode\Measuring\NamespaceDeclarations;
use Nette\Utils\FileSystem;
use function count, strlen;


/**
 * Runs the steps of a plan over the working tree of the project and stops where a human is needed. A fix runs topic by
 * topic over the project as it is, in the style the code keeps, and the files of a step go back where its tests then
 * fail (`Snapshots`). Whether a step is done is read from the code, `composer.json` and the lock, never from a record
 * of the run, so a run goes on from a working tree a team committed, squashed or rebased.
 * @internal
 */
final readonly class PlanRunner
{
	public RunState $state;
	private string $configFile;
	private VersionControl $git;
	private PackageManager $composer;
	private TestRunner $tests;


	/** @throws ConfigurationException  for a project without a configuration file */
	public function __construct(
		public Project $project,
		/** the fixes take the types of the code from PHPStan, which the caller knows is installed */
		private bool $types = false,
		/** each topic is committed with the files it changed, once its step passed the tests */
		private bool $commit = false,
		/** @var ?\Closure(string): void  told what is being done */
		private ?\Closure $onProgress = null,
		?VersionControl $git = null,
		?PackageManager $composer = null,
		?TestRunner $tests = null,
	) {
		$this->configFile = $project->configFile ?? throw new ConfigurationException(
			'The upgrade rewrites the code in the style the configuration of the project keeps, and the project has none; write it with `dresscode init` first.',
		);
		$this->state = new RunState($project);
		$this->git = $git ?? new Git($project->root);
		$this->composer = $composer ?? Composer::forProject($project->root);
		$this->tests = $tests ?? new ProcessTestRunner;
	}


	/** Whether the tracked files of the working tree have changes of their own, which the changes of a run mix with. */
	public function hasOwnChanges(): bool
	{
		return $this->git->readStatus([PlanFile::Name]) === WorkingTree::Dirty;
	}


	/**
	 * Runs the steps the plan does not leave out, or with `$singleStep` that one alone; a manual step passes once
	 * Composer says nothing of the lock refuses its target, and a step of Composer that is not done yet is made once
	 * `$approve` agrees to what Composer says it changes; a raise stops a run of the whole plan, which `$resumed` goes on
	 * after in a process that loads what the raise installed. Where a run was interrupted in a step, it runs nothing
	 * unless `$discard` says to go on from the files as they are.
	 * @param  ?\Closure(DependencyStep, list<string>): bool  $approve  told the step and the operations of Composer it makes
	 * @throws ConfigurationException
	 */
	public function run(
		Plan $plan,
		?int $singleStep = null,
		?\Closure $approve = null,
		bool $discard = false,
		?RunOutcome $resumed = null,
	): RunOutcome
	{
		$warnings = [];
		$outcome = $this->state->runExclusively(function () use ($plan, $singleStep, $approve, $discard, $resumed, &$warnings) {
			return $this->runSteps($plan, $singleStep, $approve, $discard, $resumed, $warnings);
		});
		return $warnings === [] ? $outcome : $outcome->withWarnings($warnings);
	}


	/**
	 * @param  ?\Closure(DependencyStep, list<string>): bool  $approve
	 * @param  list<string>  $warnings  what the run tells the user beside its outcome, filled as it goes
	 * @throws ConfigurationException
	 */
	private function runSteps(
		Plan $plan,
		?int $singleStep,
		?\Closure $approve,
		bool $discard,
		?RunOutcome $resumed,
		array &$warnings,
	): RunOutcome
	{
		$steps = $plan->findSteps();
		if ($singleStep !== null && !isset($steps[$singleStep])) {
			throw new ConfigurationException(isset($plan->steps[$singleStep - 1])
				? "Step $singleStep of the plan is left out with `skip: true`, so it is not run."
				: "The plan has no step $singleStep; it has " . count($plan->steps) . '.');
		}

		// a step that left files it could not process is committed once a later run makes it whole
		$uncommitted = $this->commit ? $this->findUncommitted($steps, $discard) : null;
		$status = $this->commit
			? $this->git->readStatus([PlanFile::Name, ...$uncommitted?->listOwnFiles($this->project->root) ?? []])
			: null;
		if ($status === WorkingTree::Unavailable) {
			throw new ConfigurationException('`--commit` commits into the git repository of the project, and git finds none there that tracks it, or refuses to tell its status.');
		} elseif ($status === WorkingTree::Dirty) {
			throw new ConfigurationException('A commit of a topic takes the files it changed whole, and the working tree has changes of its own; commit or stash them first.');
		}

		$snapshots = $this->state->snapshots;
		foreach ($snapshots->findInterrupted() as $number => $files) {
			if (!$discard) {
				return new RunOutcome(RunStop::Interrupted, $number, interrupted: $files);
			}

			$snapshots->drop($number);
		}

		$baselines = [];
		foreach ($plan->listTestCommands() as $command) {
			$baselines[$command] = $this->findBaseline($command);
			if ($baselines[$command]->crash !== null) {
				$crash = "the tests of `$command` do not run before the first step, as {$baselines[$command]->crash}";
				return new RunOutcome(RunStop::Tests, null, crash: $crash);
			}
		}

		$satisfiedRequirements = $this->project->measureSatisfiedRequirements();
		$names = $this->project->resolveNames();
		$declarations = $names->declarations;
		if ($names->warning !== null) {
			$warnings[] = $names->warning;
		}

		$lastFixStep = array_key_last(array_filter($steps, fn(Step $step) => $step instanceof FixStep));
		$applied = $resumed->applied ?? [];
		$tasks = $resumed->tasks ?? [];
		$followsRaise = $resumed !== null;
		// the step of Composer whose raise no tests have run after yet, which the journal keeps across the runs
		$untestedRaise = $resumed->step ?? $this->state->journal->findStep();
		$testBaseline = null; // what the last tests were compared with
		$lastTaskStep = max([0, ...array_keys(array_filter($applied, fn(array $topics) => array_intersect_key($tasks, $topics) !== []))]);
		foreach ($steps as $number => $step) {
			if (($singleStep !== null && $number !== $singleStep) || $number <= ($resumed->step ?? 0)) {
				continue;
			} elseif ($step instanceof ManualStep) {
				$blockers = $this->findBlockers($number, $step);
				if ($blockers !== null) {
					return new RunOutcome(RunStop::Manual, $number, $applied, $tasks, blockers: $blockers);
				}

				continue;

			} elseif ($step instanceof DependencyStep) {
				try {
					// whether every package is the newest the constraints allow only Composer tells
					$changes = $step instanceof UpdateStep ? $this->composer->previewUpdate() : null;
					$due = $changes === null
						? $step instanceof ComposerStep && !$step->isDoneIn(ProjectPackages::read($this->project->root))
						: $changes !== [];
					$done = $due ? $this->runComposerStep($number, $step, $approve, $changes) : null;
				} catch (ComposerException $e) {
					$done = $e->getMessage();
				} catch (CommitException $e) {
					return new RunOutcome(
						RunStop::Commit,
						$number,
						$applied,
						$tasks,
						raisedFiles: $this->state->journal->findChanged(),
						commitFailure: $e->getMessage(),
					);
				}

				if ($done !== null) {
					if ($done !== true) {
						return new RunOutcome(
							RunStop::Composer,
							$number,
							$applied,
							$tasks,
							raisedFiles: $this->state->journal->findChanged(),
							composerFailure: $done ?: null,
						);
					}

					$untestedRaise = $number;
					// what the raise installed is loaded anew for what runs after it, the check at the end of the plan among it
					if ($singleStep === null) {
						return new RunOutcome(RunStop::Raised, $number, $applied, $tasks);
					}
				}

				$followsRaise = true;
				continue;

			} elseif (!$step instanceof FixStep) {
				continue;
			}

			$result = $this->fixStep($number, $step, $plan->paths, $satisfiedRequirements, $declarations);
			$warnings = array_values(array_unique([...$warnings, ...$result->warnings]));
			$unprocessed = $result->unprocessed;

			// the whole suite after the jump and after the last step, the quick one after the others
			$command = $plan->selectTestCommand(whole: $followsRaise || $number === $lastFixStep);
			if ($command !== null) {
				$this->onProgress?->__invoke("step $number, running the tests");
				$testBaseline = $baselines[$command];
				$untestedRaise = null;
				$failure = $this->runTests($command, $testBaseline);
				if ($failure !== null) {
					$unprocessed += $snapshots->restore($number, $this->project->root);
					return new RunOutcome(
						RunStop::Tests,
						$number,
						$applied,
						$tasks,
						$failure->newFailures,
						$unprocessed,
						crash: $failure->crash,
						baseline: $testBaseline,
						raisedFiles: $this->state->journal->findChanged(),
					);
				}
			}

			// a step its tests sent back applied nothing
			$applied[$number] = array_map(array_keys(...), $result->written);
			$snapshots->drop($number);
			if ($followsRaise) { // the raise holds once a step after it is done
				$this->state->journal->forget();
			}

			try {
				if ($this->commit) {
					$this->commitStep($number, $step, $result->written, array_keys($unprocessed), $uncommitted);
				}
			} catch (CommitException $e) {
				return new RunOutcome(
					RunStop::Commit,
					$number,
					$applied,
					array_filter([...$tasks, ...$result->tasks]),
					commitFailure: $e->getMessage(),
				);
			}

			$tasks = array_filter([...$tasks, ...$result->tasks]); // the last fix of a topic tells what it leaves
			$lastTaskStep = array_filter($result->tasks) === [] ? $lastTaskStep : $number;
			if ($unprocessed !== []) {
				return new RunOutcome(RunStop::Unprocessed, $number, $applied, $tasks, unprocessed: $unprocessed, baseline: $testBaseline);
			} elseif ($followsRaise && $lastTaskStep === $number) {
				return new RunOutcome(RunStop::Tasks, $number, $applied, $tasks, baseline: $testBaseline);
			}

			$followsRaise = false;
		}

		// a raise no fix followed is tested after it all the same, the whole suite
		$command = $plan->selectTestCommand(whole: true);
		if ($untestedRaise !== null && $command !== null) {
			$this->onProgress?->__invoke("step $untestedRaise, running the tests");
			$testBaseline = $baselines[$command];
			$failure = $this->runTests($command, $testBaseline);
			if ($failure !== null) {
				return new RunOutcome(
					RunStop::Tests,
					$untestedRaise,
					$applied,
					$tasks,
					$failure->newFailures,
					crash: $failure->crash,
					baseline: $testBaseline,
					raisedFiles: $this->state->journal->findChanged(),
				);
			}

			$this->state->journal->forget();
		}

		if ($tasks !== []) { // a plan is done once nothing of it is left for a human
			return new RunOutcome(RunStop::Tasks, $lastTaskStep, $applied, $tasks, baseline: $testBaseline);
		} elseif ($singleStep === null) {
			// and once the targets find nothing in the whole scope, which the estimate the plan was made from may have missed
			$this->onProgress?->__invoke('checking the code against the targets');
			$check = $this->runTopic('deprecations', $satisfiedRequirements, $declarations, $plan->paths);
			if ($check->unprocessed !== []) {
				return new RunOutcome(RunStop::Unprocessed, null, $applied, unprocessed: $check->unprocessed, baseline: $testBaseline);
			} elseif ($check->changed !== [] || $check->tasks !== []) {
				return new RunOutcome(
					RunStop::Remaining,
					null,
					$applied,
					array_filter(['deprecations' => $check->tasks]),
					baseline: $testBaseline,
					remaining: array_keys($check->changed),
				);
			}

			// a place put off without a reason is no more done than one left
			$postponed = Postponement::findIn($this->project, $plan->paths);
			if ($postponed !== []) {
				return new RunOutcome(RunStop::Tasks, null, $applied, baseline: $testBaseline, postponed: $postponed);
			}

			$this->state->remove();
		}

		return new RunOutcome(RunStop::Done, null, $applied, $tasks, baseline: $testBaseline);
	}


	/**
	 * The step a run left uncommitted, which the plan must still have under its number, unless told to forget it.
	 * @param  array<int, Step>  $steps
	 * @throws ConfigurationException
	 */
	private function findUncommitted(array $steps, bool $discard): ?UncommittedStep
	{
		$uncommitted = $this->state->findUncommitted();
		$step = $uncommitted === null ? null : $steps[$uncommitted->step] ?? null;
		if ($uncommitted === null || ($step !== null && UncommittedStep::identify($step) === $uncommitted->identity)) {
			return $uncommitted;
		} elseif (!$discard) {
			throw new ConfigurationException("The plan changed since a run left step $uncommitted->step uncommitted, waiting for files it could not process; commit what it changed yourself, and `upgrade run --discard` forgets it.");
		}

		$this->state->forgetUncommitted();
		return null;
	}


	/** What refuses the target of the manual step, or why Composer could not tell; null where nothing does. */
	private function findBlockers(int $number, ManualStep $step): ?Blockers
	{
		$this->onProgress?->__invoke("step $number, asking Composer what refuses {$step->target->format()}");
		$blockers = $this->composer->findBlockers($step->target);
		return $blockers->isClear() ? null : $blockers;
	}


	/**
	 * Whether the step was made, once `$approve` agreed to what Composer says it changes, the files of Composer kept as
	 * they were before, and with commits the raise committed.
	 * @param  ?\Closure(DependencyStep, list<string>): bool  $approve
	 * @param  ?list<string>  $changes  what Composer said the step changes, where it was asked already
	 * @throws ComposerException
	 */
	private function runComposerStep(int $number, DependencyStep $step, ?\Closure $approve, ?array $changes): bool
	{
		if ($approve === null) {
			return false;
		}

		$changes ??= $step instanceof ComposerStep ? $this->composer->previewRequire($step->require, $step->update, $step->platform) : [];
		if (!$approve($step, $changes)) {
			return false;
		}

		$root = $this->project->root;
		$composerRoot = $this->project->findComposerRoot();
		$journal = $this->state->journal;
		$journal->recordBefore($number, $composerRoot);
		try {
			if ($step instanceof ComposerStep) {
				$this->composer->require($step->require, $step->update, $step->platform);
			} else {
				$this->composer->update();
			}
		} finally {
			$journal->recordAfter($composerRoot);
		}

		$raised = !$step instanceof ComposerStep || $step->isDoneIn(ProjectPackages::read($root));

		if ($journal->findChanged() === []) {
			$journal->forget();
		}

		if (!$raised) {
			return false;
		} elseif ($this->commit) {
			try {
				// the files of Composer as git names them from the root, which may lie below them
				$up = str_repeat('../', substr_count(substr($root, strlen($composerRoot)), '/'));
				$paths = $this->git->listUnignored(["{$up}composer.json", "{$up}composer.lock"]);
				if ($paths !== []) {
					$files = array_combine($paths, array_map(fn(string $path) => FileSystem::read("$root/$path"), $paths));
					$this->git->commit($files, "upgrade: $step->title", "DressCode-Upgrade-Step: $number");
				}
			} catch (GitException $e) {
				throw new CommitException("{$e->getMessage()}; the raise of step $number is left uncommitted.", previous: $e);
			}
		}

		return true;
	}


	/**
	 * Fixes the topics of the step one by one and writes what each changes.
	 * @param  list<string>  $paths
	 * @param  list<string>  $satisfied
	 */
	private function fixStep(int $number, FixStep $step, array $paths, array $satisfied, ?NamespaceDeclarations $declarations): StepResult
	{
		$written = $unprocessed = $tasks = $warnings = [];
		foreach ($step->topics as $topic) {
			$this->onProgress?->__invoke("step $number, fixing $topic");
			$reporter = $this->runTopic($topic, $satisfied, $declarations, $paths);
			[$written[$topic], $unwritten] = $this->writeTopic($number, $reporter);
			$unprocessed += $reporter->unprocessed + $unwritten;
			$tasks[$topic] = $reporter->tasks;
			$warnings = [...$warnings, ...$reporter->warnings];
		}

		return new StepResult($written, $unprocessed, $tasks, $warnings);
	}


	/** Runs the tests and compares them with the baseline; null where they pass. */
	private function runTests(string $command, TestResults $baseline): ?TestFailure
	{
		$results = $this->tests->run($command, $this->project->root);
		$failures = $results->findNewFailures($baseline);
		return $failures === [] && $results->crash === null ? null : new TestFailure($failures, $results->crash);
	}


	/**
	 * The results of the command of the tests before the first step, run once and kept for the runs that follow, each
	 * command its own; results whose command crashed are not kept.
	 */
	private function findBaseline(string $command): TestResults
	{
		$baseline = $this->state->findBaseline($command);
		if ($baseline === null) {
			$this->onProgress?->__invoke('running the tests before the first step');
			$baseline = $this->tests->run($command, $this->project->root);
			if ($baseline->crash !== null) {
				return $baseline;
			}

			$this->state->saveBaseline($command, $baseline);
		}

		return $baseline;
	}


	/**
	 * Runs the topic over the project as it is, writing nothing, the names resolved for certain with what the namespaces
	 * declare where they were measured, as the estimate resolves them.
	 * @param  list<string>  $satisfied  the requirements of the style the code satisfies
	 * @param  list<string>  $paths  within the configured ones, all of them where empty
	 */
	private function runTopic(string $topic, array $satisfied, ?NamespaceDeclarations $declarations, array $paths): TopicReporter
	{
		$project = $this->project;
		$layer = $project->createLayer($project->discoverPackages()->findDataPlugins(), $this->types, declarations: $declarations);
		[$factory, $resolution] = new UpgradeCheck($layer, [$topic, ...$satisfied], Estimator::Sets)->resolve(
			$project->config, $project->root,
		);
		$runner = $factory->createRunner($resolution, cache: false, configFile: $this->configFile);
		$reporter = new TopicReporter(array_keys($resolution->warnings));
		$runner->run($project->findUpgradeFiles($paths)->files, false, $reporter);
		return $reporter;
	}


	/**
	 * Writes what the fix of the topic changes, each file kept as it was before the step first, and leaves a file changed
	 * since the fix read it as it is.
	 * @return array{array<string, string>, array<string, string>}  the files written => their text, those not => why
	 */
	private function writeTopic(int $step, TopicReporter $reporter): array
	{
		$written = $unwritten = [];
		foreach ($reporter->changed as $path => [$before, $after]) {
			$file = Helpers::toAbsolutePath($path, $this->project->root);
			if (@file_get_contents($file) !== $before) { // @ a file deleted since is changed too
				$unwritten[$path] = 'The file changed while it was being fixed, so it was not written.';
				continue;
			}

			$this->state->snapshots->record($step, $path, $before, $after);
			if (!Helpers::writeFile($file, $after)) {
				$unwritten[$path] = 'The file cannot be written.';
				continue;
			}

			$written[$path] = $after;
		}

		return [$written, $unwritten];
	}


	/**
	 * Commits each topic of a step that processed every file, together with what the run before wrote in it and left
	 * uncommitted, and otherwise keeps the step as uncommitted for the run that processes the rest.
	 * @param  array<string, array<string, string>>  $written  topic => the files its fix wrote => their text
	 * @param  list<string>  $unprocessed
	 * @throws ConfigurationException  where git refuses a commit
	 */
	private function commitStep(int $number, FixStep $step, array $written, array $unprocessed, ?UncommittedStep $uncommitted): void
	{
		foreach ($uncommitted?->step === $number ? $uncommitted->topics : [] as $topic => $paths) {
			foreach ($paths as $path) {
				$file = Helpers::toAbsolutePath($path, $this->project->root);
				if (!isset($written[$topic][$path]) && is_file($file)) {
					$written[$topic][$path] = FileSystem::read($file);
				}
			}
		}

		$texts = array_merge(...array_values($written)); // the text a later topic wrote last
		if ($unprocessed !== []) {
			$this->state->saveUncommitted(new UncommittedStep(
				$number,
				UncommittedStep::identify($step),
				array_map(array_keys(...), $written),
				$unprocessed,
				array_map(fn(string $text) => hash('xxh128', $text), $texts),
			));
			return;
		}

		// the commits are of the texts the tests passed over, which a file changed since is not
		$changed = array_keys(array_filter(
			$texts,
			fn(string $text, string $path) => @file_get_contents(Helpers::toAbsolutePath($path, $this->project->root)) !== $text, // @ a file deleted since is changed too
			ARRAY_FILTER_USE_BOTH,
		));
		if ($changed !== []) {
			throw new CommitException('`' . implode('`, `', $changed) . "` changed after step $number wrote it, so the step is left uncommitted in the working tree; commit it yourself.");
		}

		$this->commitTopics($number, $written);
		$this->state->forgetUncommitted();
	}


	/**
	 * Commits each topic of the step with the files it changed as the topic left them, which a later topic of the step
	 * may have changed again, and leaves the working tree as the last topic left it.
	 * @param  array<string, array<string, string>>  $written  topic => the files its fix wrote => their text
	 * @throws ConfigurationException  where git refuses a commit
	 */
	private function commitTopics(int $step, array $written): void
	{
		$committed = [];
		foreach (array_filter($written) as $topic => $files) {
			try {
				$this->git->commit($files, "upgrade: $topic", "DressCode-Upgrade-Step: $step");
			} catch (GitException $e) {
				throw new CommitException(
					"{$e->getMessage()}; what step $step changed is left uncommitted in the working tree"
					. ($committed === [] ? '.' : ', but for the topics committed before: ' . implode(', ', $committed) . '.'),
					previous: $e,
				);
			}

			$committed[] = $topic;
		}
	}
}
