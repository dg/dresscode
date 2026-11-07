<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\{Analyses, Config, ConfigurationException, Tristate};
use DressCode\Config\{PackageDiscovery, ProjectPackages, Versions};
use DressCode\Console\{CommandContext, EstimatePrinter, Markup, PlanPrinter, RunExecutor, RunOutcomePrinter};
use DressCode\Engine\{Worker, WorkerPool};
use DressCode\Upgrade\{
	Composer, Estimate, Estimator, Plan, PlanBuilder, PlanFile, PlanRunner, Project, Provenance, RunState, RunStop, Target, UpgradeCheck,
};
use Nette\CommandLine\{Attributes as Cli, Console, ParseResult, UsageException};
use Nette\Utils\FileSystem;


/**
 * The commands of `upgrade`: what moving the code to newer versions of PHP and of the libraries changes, the plan that
 * orders it into steps and the run of the plan, and the code moved to the lowest versions the project allows.
 * @internal
 */
final readonly class UpgradeCommand
{
	private RunExecutor $executor;


	public function __construct(
		private CommandContext $context,
	) {
		$this->executor = new RunExecutor($context);
	}


	#[Cli\Command('Estimate the changes of the code an upgrade to a newer PHP or library needs, changing nothing')]
	public function estimate(
		ParseResult $args,
		#[Cli\Argument('`php` or a package; PHP and every library with upgrading data when omitted', optional: true)]
		?string $target,
		#[Cli\Argument('The version to upgrade to; for PHP the newest its upgrading data know when omitted', optional: true)]
		?string $version,
		EstimateOptions $options,
		ConfigOptions $configuration,
	): int
	{
		if ($options->worker !== null && $options->checkFile !== null) {
			return $this->runEstimateWorker($args, $options->worker, $options->checkFile, $configuration);
		}

		$project = $this->loadProject($args, $configuration);
		$console = $this->findConsole($options->format);
		$types = $this->decideTypes($options->types, $this->mayAsk($options->format), $project->config);
		$estimate = $this->measure($args, $project, $target, $version, $types, $options, $configuration, $console);
		$printer = new EstimatePrinter($estimate);
		if ($console === null) {
			$this->writeJson($project, $printer->toArray());
		} else {
			$command = implode(' ', array_filter(['dresscode upgrade plan', $target, $version], fn($word) => $word !== null));
			$console->write($printer->print($console) . ($estimate->topics === []
				? ''
				: "\n" . Markup::formatLine($console, 'Next', "`$command` orders this into steps you can run") . "\n"));
		}

		return $estimate->isComplete() ? 0 : 2;
	}


	#[Cli\Command('Order the estimate into the steps of an upgrade, changing nothing; `--save` keeps the plan')]
	public function plan(
		ParseResult $args,
		#[Cli\Argument('`php` or a package; PHP and every library with upgrading data when omitted', optional: true)]
		?string $target,
		#[Cli\Argument('The version to upgrade to; for PHP the newest its upgrading data know when omitted', optional: true)]
		?string $version,
		#[Cli\Flag('Save the plan into `dresscode-upgrade.neon`, which the team may edit and `upgrade run` carries out')]
		?bool $save,
		#[Cli\Flag('Drop what a run interrupted in a step kept of its files, which saving the plan forgets')]
		?bool $discard,
		EstimateOptions $options,
		PlanOptions $planning,
		ConfigOptions $configuration,
	): int
	{
		if (($misuse = $planning->findMisuse()) !== null) {
			throw new UsageException($misuse, $args->command);
		}

		$project = $this->loadProject($args, $configuration);
		$console = $this->findConsole($options->format);
		$types = $this->decideTypes($options->types, $this->mayAsk($options->format), $project->config);
		[$estimate, $plan, $mode] = $this->makePlan(
			$args, $project, $target, $version, $types, $options, $planning, $configuration, $console,
		);
		// the plan comes after the estimate it orders, which says what it cannot see and what it is not sure of
		$estimatePrinter = new EstimatePrinter($estimate);
		$planPrinter = new PlanPrinter($plan, $mode);
		$console?->write($estimatePrinter->print($console) . "\n" . $planPrinter->print($console));
		if ($save) {
			$this->savePlan($args, $plan, $project, (bool) $discard, $console);
		}

		if ($console === null) {
			$this->writeJson($project, [...$planPrinter->toArray(), 'estimate' => $estimatePrinter->toArray()], saved: (bool) $save);
		}

		if ($plan->steps !== []) {
			$console?->writeLine(Markup::formatLine($console, 'Next', $save
				? '`dresscode upgrade run` carries out the plan saved, step by step'
				: '`--save` keeps the plan in `'
					. PlanFile::Name
					. '` for the team to review, which `dresscode upgrade run` then carries out'));
		}

		return $estimate->isComplete() ? 0 : 2;
	}


	#[Cli\Command('Carry out the saved plan, or make one for the target first; stops where Composer or you are needed')]
	public function run(
		ParseResult $args,
		#[Cli\Argument('`php` or a package, to make a plan for; the saved plan when omitted', optional: true)]
		?string $target,
		#[Cli\Argument('The version to upgrade to; for PHP the newest its upgrading data know when omitted', optional: true)]
		?string $version,
		EstimateOptions $options,
		PlanOptions $planning,
		PlanRunOptions $running,
		ConfigOptions $configuration,
	): int
	{
		if ($target === null && ($planning->isGiven() || $options->paths !== [] || $options->offline || $options->jobs !== null)) {
			throw new UsageException(
				'`--paths`, `--offline`, `--jobs`, the commands of the tests and `--library` make a plan, which a target does; without one `upgrade run` carries out the saved plan as it is.',
				$args->command,
			);
		} elseif (($misuse = $planning->findMisuse()) !== null) {
			throw new UsageException($misuse, $args->command);
		}

		$project = $this->loadProject($args, $configuration);
		if ($target !== null && PlanFile::exists($project->root)) {
			throw new UsageException(
				'The project has a saved plan, `'
				. PlanFile::Name
				. '`, which `upgrade run` carries out; `upgrade plan --save` replaces it.',
				$args->command,
			);
		}

		$console = $this->findConsole($options->format);
		$mayAsk = $this->mayAsk($options->format);
		$types = $this->decideTypes($options->types, $mayAsk, $project->config);
		$runner = $this->createPlanRunner($project, $types, (bool) $running->commit, $console);
		$handoff = RunHandoff::take();
		if ($target === null) {
			$plan = PlanFile::read($project->root);
			if ($console !== null && $handoff->resumed === null) {
				self::writeHeader($console, $project);
			}
		} else {
			[$estimate, $plan, $mode] = $this->makePlan(
				$args, $project, $target, $version, $types, $options, $planning, $configuration, $console,
			);
			$console?->write(
				new EstimatePrinter($estimate)->print($console, passive: false) . "\n" . new PlanPrinter($plan, $mode)->print($console),
			);
			$this->savePlan($args, $plan, $project, (bool) $running->discard, $console);
		}

		// the run after a raise goes on in a process of its own with what this one decided
		$rerun = [
			'upgrade',
			'run',
			$types ? '--types' : '--no-types',
			...$options->format === null ? [] : ["--format=$options->format"],
			...$running->yes ? ['--yes'] : [],
			...$running->commit ? ['--commit'] : [],
			...$args->get('--no-color') ? ['--no-color'] : [],
			...$configuration->toArguments(),
		];
		return $this->runPlan($plan, $runner, $running, $mayAsk, $console, $rerun, $handoff);
	}


	#[Cli\Command('Undo an unfinished upgrade: restore the files of an interrupted step and the `composer.json` and lock a raise changed')]
	public function abort(
		ParseResult $args,
		ConfigOptions $configuration,
		#[Cli\Flag('Say what it would put back and leave, changing nothing')]
		bool $dryRun = false,
	): int
	{
		$project = $this->loadProject($args, $configuration);
		$console = $this->context->out;
		self::writeHeader($console, $project);
		$restoration = new RunState($project)->restore(
			$project,
			Composer::forProject($project->root),
			$dryRun,
			fn(string $what) => $console->setStatus(Markup::formatLabel($console, 'Upgrading') . "$what\u{2026}"),
		);
		$console->clearStatus();
		[$restored, $left, $installFailure] = [$restoration->restored, $restoration->left, $restoration->installFailure];
		if ($restored !== [] || !$restoration->installs) {
			$console->writeLine(Markup::formatLine(
				$console,
				$dryRun ? 'Restorable' : 'Restored',
				$restored === [] ? 'nothing, as no run keeps anything to go back' : implode(', ', $restored),
			));
		}

		if ($restoration->installs && $installFailure === null) {
			$console->writeLine(
				Markup::formatLine($console, $dryRun ? 'Installs' : 'Installed', 'the packages of the lock', ', by `composer install`'),
			);
		}

		foreach ($left as $path => $why) {
			$console->writeLine(Markup::formatLine($console, 'Left', "$path: $why"));
		}

		if ($installFailure !== null) {
			$console->writeLine(Markup::formatLine($console, 'Failed', "`composer install`: $installFailure"));
		}

		return $dryRun || ($left === [] && $installFailure === null) ? 0 : 1;
	}


	#[Cli\Command('Rewrite the old API for the versions `composer.json` requires now, keeping the style of the project')]
	public function today(ParseResult $args, TodayOptions $options, ConfigOptions $configuration): int
	{
		$run = $options->toRunOptions();
		// a worker runs with what its parent installed, and what is installed, plugins among it, loads in a new process
		if (
			$run->worker === null
			&& new DataPackageOffer($this->context)->install($args, $configuration, (bool) $options->yes, $this->mayAsk($options->format))
		) {
			$exit = $this->context->rerun(array_values(array_filter(array_slice($_SERVER['argv'], 1), is_string(...))));
			if ($exit === null) {
				$this->context->err->writeLine('The upgrading data are installed; run the command again, which loads them.');
			}

			return $exit ?? 4;
		}

		return $this->executor->run(
			$args,
			$configuration,
			$run,
			new CheckOptions(maxWarnings: $options->maxWarnings, fixRisky: $options->fixRisky, only: $options->only),
			fix: true,
			askRisky: (bool) $options->askRisky,
			upgrade: new TodayUpgrade($this->context, (bool) $options->modernize, $options->only),
		);
	}


	/** Measures what upgrading to the targets changes, changing nothing. */
	private function measure(
		ParseResult $args,
		Project $project,
		?string $target,
		?string $version,
		bool $types,
		EstimateOptions $options,
		ConfigOptions $configuration,
		?Console $console,
		bool $library = false,
	): Estimate
	{
		$estimator = new Estimator(
			$project,
			$types,
			(bool) $options->offline,
			createWorkers: fn(UpgradeCheck $check, int $files) => $this->createEstimateWorkers(
				$configuration,
				$check,
				$options->jobs ?? WorkerPool::countDefaultJobs($files),
				$project->getCacheDir(),
			),
			onProgress: $console === null
				? null
				: fn(string $what) => $console->setStatus(Markup::formatLabel($console, 'Measuring') . "$what\u{2026}"),
			library: $library,
		);
		$targets = $this->resolveTargets($args, $target, $version, $estimator);
		if ($console !== null) {
			self::writeHeader($console, $project);
		}

		try {
			return $estimator->estimate($targets, array_map($this->context->resolvePath(...), $options->paths));
		} finally {
			$console?->clearStatus();
		}
	}


	/**
	 * Measures what upgrading to the targets changes and orders it into the steps of an upgrade, of a library or of an
	 * application, which it says with why.
	 * @return array{Estimate, Plan, string}
	 */
	private function makePlan(
		ParseResult $args,
		Project $project,
		?string $target,
		?string $version,
		bool $types,
		EstimateOptions $options,
		PlanOptions $planning,
		ConfigOptions $configuration,
		?Console $console,
	): array
	{
		// a library moves no installed package, which the estimate would otherwise ask Composer about
		[$library, $why] = $planning->library === null
			? $this->decideLibrary($this->mayAsk($options->format), $project)
			: [$planning->library, 'as `--' . ($planning->library ? '' : 'no-') . 'library` says'];
		$estimate = $this->measure($args, $project, $target, $version, $types, $options, $configuration, $console, $library);
		if ($estimate->targets === []) { // a project on PHP 7 is offered none
			throw new UsageException(
				'There is no version to plan an upgrade to; name one, as `php 8.4` or `vendor/name 4.0`.',
				$args->command,
			);
		}

		$plan = new PlanBuilder(ProjectPackages::read($project->root))
			->build($estimate, $planning->testCommand, $planning->quickTestCommand);
		return [$estimate, $plan, ($library ? 'library, ' : 'application, ') . $why];
	}


	/**
	 * Saves the plan, which forgets what the runs of the one before kept, refusing to forget the files of a step a run left
	 * unfinished unless told to discard them; a plan of no step is no file, which nothing would run.
	 * @throws UsageException
	 */
	private function savePlan(ParseResult $args, Plan $plan, Project $project, bool $discard, ?Console $console): void
	{
		if ($plan->steps === []) {
			$console?->writeLine(Markup::formatLine($console, 'Saved', 'nothing, as the upgrade has no step to make'));
			return;
		}

		$state = new RunState($project);
		$state->runExclusively(function () use ($state, $discard, $args, $plan, $project) {
			$interrupted = $state->snapshots->findInterrupted();
			if ($interrupted !== [] && !$discard) {
				$files = array_merge(...array_values($interrupted));
				throw new UsageException(
					'Step ' . array_key_first($interrupted) . ' of a run did not finish'
					. ($files === [] ? '' : ' and may have rewritten ' . implode(', ', $files))
					. '; saving a plan forgets what it kept, so put the files back with `dresscode upgrade abort` first, or pass `--discard`.',
					$args->command,
				);
			}

			PlanFile::write($plan, $project->root);
			$state->remove();
		});
		$console?->writeLine(Markup::formatLine($console, 'Saved', PlanFile::Name));
	}


	/**
	 * The runner of the plan, telling its progress on the status line of the console, which data have none.
	 * @throws ConfigurationException  for a project without a configuration file
	 */
	private function createPlanRunner(Project $project, bool $types, bool $commit, ?Console $console): PlanRunner
	{
		$console ??= $this->context->err;
		return new PlanRunner(
			$project,
			$types,
			$commit,
			fn(string $what) => $console->setStatus(Markup::formatLabel($console, 'Upgrading') . "$what\u{2026}"),
		);
	}


	/**
	 * Runs the plan over the working tree and says how far it got, as text on the console or, without one, as a document
	 * of data whose warnings go to standard error, with the exit code of the outcome. After a raise it goes on in a new
	 * process, `$rerun` its command line, which tells how far the whole run got; a run going on after a raise
	 * says nothing of what the run before it said, and hands back where it stopped instead.
	 * @param  list<string>  $rerun
	 */
	private function runPlan(
		Plan $plan,
		PlanRunner $runner,
		PlanRunOptions $running,
		bool $mayAsk,
		?Console $output,
		array $rerun,
		RunHandoff $handoff,
	): int
	{
		$console = $output ?? $this->context->err;
		if ($handoff->resumed === null) { // what the run before the raise said already
			$this->warnBeforeRun($plan, $runner, (bool) $running->commit, $console);
		}

		// without a terminal only `--yes` agrees, so without it Composer is not even asked what a raise changes
		$approve = $running->yes || $mayAsk ? new RaiseApproval($this->context, $console, (bool) $running->yes)->approve(...) : null;
		try {
			$outcome = $runner->run($plan, $running->step, $approve, (bool) $running->discard, $handoff->resumed);
		} finally {
			$console->clearStatus();
		}

		if ($outcome->stop === RunStop::Raised && $handoff->resumed !== null) {
			$handoff->handBack($outcome);
			return 0;
		} elseif ($outcome->stop === RunStop::Raised && ($exit = RunHandoff::goOn($this->context, $outcome, $plan, $rerun)) !== null) {
			return $exit;
		}

		$printer = new RunOutcomePrinter($plan, $outcome, $running->step, $runner->project->root);
		if ($output !== null) {
			$output->write($printer->print($output));
		} else {
			$this->writeJson($runner->project, [
				'version' => RunOutcomePrinter::Version,
				'plan' => new PlanPrinter($plan)->toArray(),
				'outcome' => $printer->toArray(),
			], saved: true);
		}

		return $outcome->getExitCode();
	}


	private function warnBeforeRun(Plan $plan, PlanRunner $runner, bool $commit, Console $console): void
	{
		if (!$plan->isMadeWith(Provenance::current(ProjectPackages::read($runner->project->root)))) {
			$console->writeLine(Markup::formatLine(
				$console,
				'Warning',
				'The plan was made with other versions of DressCode or of the upgrading data',
				', so a step may fix more or less than it did then.',
			));
		}

		if ($plan->unestimated !== [] || $plan->missingData !== []) {
			$console->writeLine(Markup::formatLine(
				$console,
				'Warning',
				'The estimate of the plan ' . implode(' and ', array_filter([
					$plan->unestimated === [] ? '' : 'could not process ' . implode(', ', $plan->unestimated),
					$plan->missingData === [] ? '' : 'lacked the upgrading data of ' . implode(', ', array_keys($plan->missingData)),
				])),
				'; the run checks the whole code against the targets at its end.',
			));
		}

		if (!$runner->state->durable) {
			$console->writeLine(Markup::formatLine(
				$console,
				'Warning',
				'Git finds no repository of the project',
				', so what a run keeps to go back lies in the cache directory, which clearing the cache loses.',
			));
		}

		if (!$commit && $runner->hasOwnChanges()) {
			$console->writeLine(Markup::formatLine(
				$console,
				'Warning',
				'The working tree has changes of its own',
				', which the changes of the upgrade mix with.',
			));
		}
	}


	/** @param  array<string, mixed>  $data */
	private function writeJson(Project $project, array $data, bool $saved = false): void
	{
		$data = [
			'version' => $data['version'],
			'root' => $project->root,
			...$saved ? ['planIdentity' => PlanFile::identify($project->root)] : [],
			...$data,
		];
		$this->context->writeJson($data);
	}


	/** The name of the tool and the configuration the command measures or runs with. */
	private static function writeHeader(Console $console, Project $project): void
	{
		$console->writeLine(CommandContext::formatName($console));
		$console->writeLine($project->configFile === null
			? Markup::formatLine($console, 'Config', 'none', ', the scope `init` would write: ' . implode(', ', $project->config->paths))
			: Markup::formatLine($console, 'Config', FileSystem::platformSlashes($project->configFile)));
	}


	/** The console the format writes text to; null for data. */
	private function findConsole(?string $format): ?Console
	{
		return ($format ?? 'console') === 'console' ? $this->context->out : null;
	}


	/** Whether the command may ask the user: in a terminal, writing text for a human rather than data for a tool. */
	private function mayAsk(?string $format): bool
	{
		return ($format ?? 'console') === 'console' && $this->context->prompt->isInteractive();
	}


	/**
	 * The project of the command: its configuration, or the scope `init` would write where it has no file of its own.
	 * @throws ConfigurationException  for a directory that is no project at all
	 */
	private function loadProject(ParseResult $args, ConfigOptions $configuration): Project
	{
		['config' => $config, 'root' => $root, 'file' => $file, 'commandLine' => $commandLine]
			= $this->context->loadConfig($args, $configuration, new Config(paths: ['.']));
		return Project::fromConfig($config, $root, $file, $commandLine);
	}


	/**
	 * The versions to measure the upgrade to: the package and the version named, PHP to the version named or to the
	 * newest its data know, and without a target what the estimator proposes.
	 * @return list<Target>
	 * @throws UsageException
	 */
	private function resolveTargets(ParseResult $args, ?string $target, ?string $version, Estimator $estimator): array
	{
		return match (true) {
			$version !== null && !Versions::isVersion($version)
				=> throw new UsageException("The version to upgrade to is written as `8.4` or `4.0`, `$version` given.", $args->command),
			$target === null => $estimator->proposeTargets(),
			$target === PackageDiscovery::Php => $version === null ? $estimator->proposeTargets(phpOnly: true) : [Target::php($version)],
			!Target::isPackageName($target)
				=> throw new UsageException("The target is `php` or a package as `vendor/name`, `$target` given.", $args->command),
			$version === null => throw new UsageException("Name the version of `$target` to upgrade to, as `$target 4.0`.", $args->command),
			default => [new Target($target, $version)],
		};
	}


	/**
	 * Whether the plan is the one of a library, which widens its constraints, where no option says, and why: by the `type`
	 * of the project and the lock it has none of, and for a library with a lock of its own in a terminal as the user
	 * answers, otherwise as Enter does: a library where the `type` is written, the application the lock makes it where not.
	 * @return array{bool, string}
	 */
	private function decideLibrary(bool $mayAsk, Project $project): array
	{
		$packages = ProjectPackages::read($project->root);
		$type = $packages->findType();
		$typeGiven = $packages->rootPath !== null && isset(Config\ComposerJson::read("$packages->rootPath/composer.json")['type']);
		$typed = $typeGiven
			? "`composer.json` gives the type `$type`"
			: '`composer.json` gives no type, which Composer reads as `library`';
		return match ($packages->isLibrary()) {
			Tristate::Yes => [true, is_file("$packages->rootPath/composer.lock")
				? "$typed, and its git ignores `composer.lock`"
				: "$typed, and there is no `composer.lock`"],
			Tristate::No => [false, $typed],
			Tristate::Maybe => match (true) {
				$mayAsk => [$this->context->prompt->choose(
					Markup::formatQuestion(
						$this->context->out,
						'Plan it as a library, which widens its constraints to the targets instead of raising them?',
						"$typed, yet the project has a `composer.lock` of its own.",
					),
					['y', 'n'],
					$typeGiven ? 'y' : 'n',
				) === 'y', 'as you answered'],
				$typeGiven => [
					true,
					"$typed, though the project has a `composer.lock` of its own; `--no-library` plans it as an application",
				],
				default => [
					false,
					"$typed, yet the project has a `composer.lock` of its own, as an application has; `--library` plans it as a library",
				],
			},
		};
	}


	/**
	 * Whether the upgrade goes with the types of the code: as `--types` says, as the configuration says, else in a
	 * terminal as the user answers, and otherwise without them.
	 * @throws ConfigurationException  for the types asked for without PHPStan beside DressCode
	 */
	private function decideTypes(?bool $types, bool $mayAsk, Config $config): bool
	{
		$available = Analyses\PhpStan::isAvailable();
		if ($types === true && !$available) {
			throw new ConfigurationException(
				'`--types` asks for the types of the code, but `phpstan/phpstan` is not installed beside DressCode.',
				docs: 'types#enable',
			);
		} elseif ($types !== null) {
			return $types;
		} elseif ($config->typeAnalysis !== null || !$available) {
			return $available;
		}

		return $mayAsk
			&& $this->context->prompt->choose(
				Markup::formatQuestion(
					$this->context->out,
					'Measure with the types of the code?',
					'From PHPStan with the configuration of the project where it has one, they decide most of what a fix of a call needs to know.',
				),
				['y', 'n'],
				'y',
			) === 'y';
	}


	/**
	 * The workers repeating the check of the estimate, `upgrade estimate` reading it from a file in the cache directory;
	 * null for one job, or where there is no PHP to start one with.
	 */
	private function createEstimateWorkers(ConfigOptions $configuration, UpgradeCheck $check, int $jobs, string $cacheDir): ?WorkerPool
	{
		$php = WorkerPool::buildPhpCommand();
		if ($jobs < 2 || $php === null) {
			return null;
		}

		$file = "$cacheDir/upgrade/estimate-" . bin2hex(random_bytes(8)) . '.json';
		$check->write($file);
		register_shutdown_function(fn() => @unlink($file)); // @ a worker may hold it still

		$command = [...$php, $this->context->scriptFile, 'upgrade', 'estimate', '--no-color', "--check-file=$file"];
		foreach (['--config' => (array) $configuration->config, '--root' => (array) $configuration->root] as $option => $values) {
			foreach ($values as $value) {
				$command[] = "$option=$value";
			}
		}

		return new WorkerPool($command, $jobs, $this->context->workingDirectory, warmFirst: $check->layer->typeAnalysis !== null);
	}


	/** The run of a worker of the estimate: the check repeated as the parent wrote it, the files coming over the connection. */
	private function runEstimateWorker(ParseResult $args, string $address, string $file, ConfigOptions $configuration): int
	{
		['config' => $config, 'root' => $root] = $this->context->loadConfig($args, $configuration, new Config(paths: ['.']));
		[$factory, $resolution] = UpgradeCheck::read($file)->resolve($config, $root);
		$runner = $factory->createRunner($resolution, cache: false);
		($runner->warmUp)?->__invoke(); // before connecting, which is what starts the other workers
		return Worker::run($address, $runner, fix: false);
	}
}
