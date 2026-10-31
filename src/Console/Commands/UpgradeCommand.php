<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\{Analyses, Config, ConfigurationException, Tristate};
use DressCode\Config\{DataPackageCatalogue, PackageDiscovery, ProjectPackages, RunnerFactory, Versions};
use DressCode\Console\{Application, CommandContext, EstimatePrinter, Markup, PlanPrinter, RunExecutor, RunOutcomePrinter, TasksPrinter};
use DressCode\Engine\{Worker, WorkerPool};
use DressCode\Measuring\Scope;
use DressCode\Upgrade\{
	Composer, ComposerException, EstimateRun, Estimator, Plan, PlanBuilder, PlanFile, PlanRunner, Project, RunStop, Step, Target,
};
use Nette\CommandLine\{Attributes as Cli, ColorDepth, Console, ParseResult, UsageException};
use Nette\Utils\FileSystem;
use const JSON_PRETTY_PRINT, JSON_THROW_ON_ERROR, JSON_UNESCAPED_SLASHES;


/**
 * The command `upgrade`: what moving the code to newer versions of PHP and of the libraries costs, and with `--today`
 * the code moved to the lowest versions the project allows.
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


	#[Cli\Command('Show what upgrading to newer versions of PHP or of a library costs, changing nothing; with `--today`, upgrade the code to the lowest versions `composer.json` allows')]
	public function upgrade(
		ParseResult $args,
		#[Cli\Argument('`php` or a package; PHP and every library with upgrading data when omitted', optional: true)]
		?string $target,
		#[Cli\Argument('The version to upgrade to; for PHP the newest its upgrading data know when omitted', optional: true)]
		?string $version,
		UpgradeOptions $options,
		ConfigOptions $configuration,
	): int
	{
		if ($options->worker !== null && $options->layer !== null) {
			return $this->runEstimateWorker($args, $options->worker, $options->layer, $configuration);
		} elseif (($misuse = $options->findMisuse($target)) !== null) {
			throw new UsageException($misuse, $args->command);
		} elseif ($options->today) {
			return $this->upgradeToday($args, $options, $configuration);
		}

		$project = $this->loadProject($args, $configuration);
		$console = $this->findConsole($options->format);
		if ($options->tasks) {
			return $this->listTasks($project, $options, $console);
		} elseif (!$options->continue) {
			return $this->offer($args, $project, $target, $version, $options, $configuration, $console);
		}

		$runner = $this->createPlanRunner($project, $this->decideTypes($options, $project->config), $options, $console);
		$plan = PlanFile::read("$project->root/" . PlanFile::Name);
		if ($console !== null) {
			self::writeHeader($console, $project);
		}

		return $this->runPlan($plan, $runner, $options, $console);
	}


	/**
	 * Measures what upgrading to the targets costs and writes it, changing nothing, and with the options of the plan
	 * orders it into the steps of an upgrade, which it shows, saves or runs.
	 */
	private function offer(
		ParseResult $args,
		Project $project,
		?string $target,
		?string $version,
		UpgradeOptions $options,
		ConfigOptions $configuration,
		?Console $console,
	): int
	{
		if ($options->run && !$options->savePlan && is_file("$project->root/" . PlanFile::Name)) {
			throw new UsageException(
				'The project has a saved plan, `' . PlanFile::Name . '`, which `--continue` runs; `--run --save-plan` replaces it.',
				$args->command,
			);
		}

		$types = $this->decideTypes($options, $project->config);
		$runner = $options->run ? $this->createPlanRunner($project, $types, $options, $console) : null;
		$estimator = new Estimator(
			$project,
			$types,
			(bool) $options->offline,
			createWorkers: fn(EstimateRun $run, int $files) => $this->createEstimateWorkers(
				$args,
				$configuration,
				$run,
				$project->configFile === null ? $project->config : null,
				$options->jobs ?? WorkerPool::countDefaultJobs($files),
				$project->getCacheDir(),
			),
			onProgress: $console === null
				? null
				: fn(string $what) => $console->setStatus($console->color('gray', 'Measuring  ') . "$what\u{2026}"),
		);
		$targets = $this->resolveTargets($args, $target, $version, $estimator);
		if ($console !== null) {
			self::writeHeader($console, $project);
		}

		try {
			$estimate = $estimator->estimate($targets, array_map($this->context->resolvePath(...), $options->paths));
		} finally {
			$console?->clearStatus();
		}

		$plan = $options->makesPlan()
			? new PlanBuilder(ProjectPackages::read($project->root), $this->decideLibrary($options, $project))
				->build($estimate, $options->testCommand, $options->quickTestCommand)
			: null;
		$printer = $plan === null ? new EstimatePrinter($estimate) : new PlanPrinter($plan);
		if ($console !== null) {
			$console->write(Markup::highlightCode($console, $printer->print($console)));
		} elseif ($runner === null) { // the plan of a run goes into the document of the run
			$this->writeJson($printer->toArray());
		}

		if ($plan !== null && ($options->savePlan || $options->run)) {
			PlanFile::write($plan, "$project->root/" . PlanFile::Name);
			PlanRunner::forget($project);
			$console?->writeLine($console->color('gray', 'Saved      ') . PlanFile::Name);
		}

		return $plan !== null && $runner !== null ? $this->runPlan($plan, $runner, $options, $console) : 0;
	}


	/**
	 * The runner of the plan, telling its progress on the status line of the console, which data have none.
	 * @throws ConfigurationException  for a project without a configuration file
	 */
	private function createPlanRunner(Project $project, bool $types, UpgradeOptions $options, ?Console $console): PlanRunner
	{
		$console ??= $this->context->err;
		return new PlanRunner(
			$project,
			$types,
			(bool) $options->commit,
			fn(string $what) => $console->setStatus($console->color('gray', 'Upgrading  ') . "$what\u{2026}"),
		);
	}


	/**
	 * Runs the plan over the working tree and says how far it got, as text on the console or, without one, as a document
	 * of data whose warnings go to standard error: exit code 0 done, 1 where tests newly failed or a step leaves tasks for a
	 * human, 3 where a step waits for Composer.
	 */
	private function runPlan(Plan $plan, PlanRunner $runner, UpgradeOptions $options, ?Console $output): int
	{
		$console = $output ?? $this->context->err;
		$label = fn(string $label) => $console->color('gray', str_pad($label, 11));
		if (!$plan->isMadeWith(Application::Version, PlanBuilder::findDataVersions(ProjectPackages::read($runner->project->root)))) {
			$console->writeLine($label('Warning') . 'The plan was made with other versions of DressCode or of the upgrading data, so a step may fix more or less than it did then.');
		}

		if (!$options->commit && $runner->hasOwnChanges()) {
			$console->writeLine($label('Warning') . 'The working tree has changes of its own, which the changes of the upgrade mix with.');
		}

		try {
			$outcome = $runner->run(
				$plan,
				$options->step,
				fn(Step $step) => $this->raiseWithConsent($step, $runner->project, (bool) $options->yes, $console),
			);
		} finally {
			$console->clearStatus();
		}

		$printer = new RunOutcomePrinter($plan, $outcome, $options->step);
		if ($output !== null) {
			$output->write(Markup::highlightCode($output, $printer->print($output)));
		} else {
			$this->writeJson([
				'version' => RunOutcomePrinter::Version,
				'plan' => new PlanPrinter($plan)->toArray(),
				'outcome' => $printer->toArray(),
			]);
		}

		return match ($outcome->stop) {
			RunStop::Done => 0,
			RunStop::Tasks, RunStop::Tests => 1,
			RunStop::Composer => 3,
		};
	}


	/**
	 * Lists what the fixes of the saved plan leave for a human in the code as it stands, changing nothing, and the
	 * suppressions that only put a task off: exit code 1 where a task is left.
	 */
	private function listTasks(Project $project, UpgradeOptions $options, ?Console $console): int
	{
		$plan = PlanFile::read("$project->root/" . PlanFile::Name);
		$runner = $this->createPlanRunner($project, $this->decideTypes($options, $project->config), $options, $console);
		if ($console !== null) {
			self::writeHeader($console, $project);
		}

		try {
			$tasks = $runner->findTasks($plan);
			$printer = new TasksPrinter($tasks, $runner->findPostponed());
		} finally {
			($console ?? $this->context->err)->clearStatus();
		}

		if ($console === null) {
			$this->writeJson(['version' => TasksPrinter::Version, ...$printer->toArray()]);
		} else {
			$console->write(Markup::highlightCode($console, $printer->print($console)));
			$console->writeLine($tasks === []
				? $console->color('white/green', 'OK  nothing of the plan is left for you')
				: $console->color('white/red', 'FOUND  places of the plan are left for you'));
		}

		return $tasks === [] ? 0 : 1;
	}


	/**
	 * Raises the constraints of the step of Composer once the user agrees, having seen what the update changes: in a
	 * terminal after asking, outside one with `--yes` alone.
	 */
	private function raiseWithConsent(Step $step, Project $project, bool $yes, Console $console): bool
	{
		$prompt = $this->context->prompt;
		if (!$yes && !$prompt->isInteractive()) {
			return false;
		}

		$console->clearStatus();
		$composer = new Composer($project->root);
		$label = fn(string $label) => $console->color('gray', str_pad($label, 11));
		try {
			$changes = $composer->previewRequire($step->require, $step->update);
		} catch (ComposerException $e) {
			$console->writeLine($label('Composer') . "cannot $step->title: {$e->getMessage()}");
			return false;
		}

		$console->writeLine($label('Composer') . "$step->title, " . ($changes === [] ? 'which changes no installed package' : 'which changes:'));
		foreach ($changes as $change) {
			$console->writeLine("  $change");
		}

		if (!$yes && $prompt->choose('Change `composer.json` and update the packages?', ['y', 'n'], 'y') !== 'y') {
			return false;
		}

		$console->setStatus($console->color('gray', 'Upgrading  ') . "Composer, $step->title\u{2026}");
		try {
			$composer->require($step->require, $step->update);
		} catch (ComposerException $e) {
			$console->writeLine($label('Composer') . "failed to $step->title: {$e->getMessage()}");
			return false;
		} finally {
			$console->clearStatus();
		}

		return true;
	}


	/** @param  array<string, mixed>  $data */
	private function writeJson(array $data): void
	{
		fwrite($this->context->stdout, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
	}


	/** The name of the tool and the configuration the command measures or runs with. */
	private static function writeHeader(Console $console, Project $project): void
	{
		$console->writeLine(CommandContext::formatName($console));
		$console->writeLine($console->color('gray', 'Config     ') . ($project->configFile === null
			? 'none, the scope `init` would write: ' . implode(', ', $project->config->paths)
			: FileSystem::platformSlashes($project->configFile)));
	}


	/** The console the format writes text to, one without colors for an agent; null for data. */
	private function findConsole(?string $format): ?Console
	{
		return match ($format ?? 'console') {
			'console' => $this->context->out,
			'agent' => new Console($this->context->stdout, ColorDepth::None, terminal: false),
			default => null,
		};
	}


	/**
	 * The project of the command: its configuration, or the scope `init` would write where it has no file of its own.
	 * @throws ConfigurationException  for a directory that is no project at all
	 */
	private function loadProject(ParseResult $args, ConfigOptions $configuration): Project
	{
		['config' => $config, 'root' => $root, 'file' => $file, 'commandLine' => $commandLine]
			= $this->context->loadConfig($args, $configuration, new Config(paths: ['.']));
		if ($file === null) {
			if (ProjectPackages::read($root)->rootPath === null) {
				throw new ConfigurationException('There is nothing to measure: the directory has no configuration of DressCode and no `composer.json`.');
			}

			$scope = Scope::fromProject($root, null);
			$config = new Config(paths: $scope->listPaths(), excludePaths: array_keys($scope->listExcludePaths()));
		}

		return new Project($config, $root, $file, $commandLine);
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
			!preg_match('~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D', $target)
				=> throw new UsageException("The target is `php` or a package as `vendor/name`, `$target` given.", $args->command),
			$version === null
				=> throw new UsageException("Name the version of `$target` to upgrade to, as `upgrade $target 4.0`.", $args->command),
			default => [new Target($target, $version)],
		};
	}


	/**
	 * Whether the plan is the one of a library, which widens its constraints: as `--library` or `--no-library` says, else
	 * by the `type` of the project and the lock it has none of, and for a library with a lock of its own in a terminal as
	 * the user answers, outside one as its `type` says.
	 */
	private function decideLibrary(UpgradeOptions $options, Project $project): bool
	{
		$prompt = $this->context->prompt;
		return $options->library ?? match (PlanBuilder::isLibrary($project->root)) {
			Tristate::Yes => true,
			Tristate::No => false,
			Tristate::Maybe => !$prompt->isInteractive() || $prompt->choose(
				'The project says it is a library, yet has a `composer.lock` of its own. Plan it as a library, which widens its constraints to the targets instead of raising them?',
				['y', 'n'],
				'y',
			) === 'y',
		};
	}


	/**
	 * Whether the offer is measured with the types of the code: as `--types` says, as the configuration says, else in a
	 * terminal as the user answers, and outside one without them.
	 * @throws ConfigurationException  for the types asked for without PHPStan beside DressCode
	 */
	private function decideTypes(UpgradeOptions $options, Config $config): bool
	{
		$available = Analyses\PhpStan::isAvailable();
		if ($options->types === true && !$available) {
			throw new ConfigurationException(
				'`--types` asks for the types of the code, but `phpstan/phpstan` is not installed beside DressCode.',
				docs: 'types#enable',
			);
		} elseif ($options->types !== null) {
			return $options->types;
		} elseif ($config->typeAnalysis !== null || !$available) {
			return $available;
		}

		$prompt = $this->context->prompt;
		return $prompt->isInteractive()
			&& $prompt->choose(
				'Measure with the types of the code, from PHPStan with its default configuration? They decide most of what a fix of a call needs to know.',
				['y', 'n'],
				'y',
			) === 'y';
	}


	/**
	 * The workers repeating the check of the estimate, which read it from a file in the cache directory together with the
	 * scope of a project without a configuration file; null for one job, or where there is no PHP to start one with.
	 */
	private function createEstimateWorkers(
		ParseResult $args,
		ConfigOptions $configuration,
		EstimateRun $run,
		?Config $scope,
		int $jobs,
		string $cacheDir,
	): ?WorkerPool
	{
		$php = WorkerPool::buildPhpCommand();
		if ($jobs < 2 || $php === null) {
			return null;
		}

		$file = "$cacheDir/upgrade/estimate-" . bin2hex(random_bytes(8)) . '.json';
		$job = [
			'run' => $run->toArray(),
			'scope' => $scope === null ? null : ['paths' => $scope->paths, 'excludePaths' => $scope->excludePaths],
		];
		FileSystem::write($file, json_encode($job, JSON_THROW_ON_ERROR));
		register_shutdown_function(fn() => @unlink($file)); // @ a worker may hold it still

		$command = [...$php, $this->context->scriptFile, (string) $args->command->name, '--no-color', "--layer=$file"];
		foreach (['--config' => (array) $configuration->config, '--root' => (array) $configuration->root] as $option => $values) {
			foreach ($values as $value) {
				$command[] = "$option=$value";
			}
		}

		return new WorkerPool($command, $jobs, $this->context->workingDirectory, warmFirst: $run->layer->typeAnalysis !== null);
	}


	/** The run of a worker of the estimate: the check repeated as the parent wrote it, the files coming over the connection. */
	private function runEstimateWorker(ParseResult $args, string $address, string $file, ConfigOptions $configuration): int
	{
		$job = json_decode(FileSystem::read($file), associative: true, flags: JSON_THROW_ON_ERROR);
		['config' => $config, 'root' => $root] = $this->context->loadConfig($args, $configuration, new Config(paths: ['.']));
		[$factory, $resolution] = EstimateRun::fromArray($job['run'])->resolve(
			$job['scope'] === null ? $config : new Config(...$job['scope']),
			$root,
		);
		$runner = $factory->createRunner($resolution, cache: false);
		($runner->warmUp)?->__invoke(); // before connecting, which is what starts the other workers
		return Worker::run($address, $runner, fix: false);
	}


	private function upgradeToday(ParseResult $args, UpgradeOptions $options, ConfigOptions $configuration): int
	{
		$run = $options->toRunOptions();
		// a worker runs with what its parent installed, and what is installed, plugins among it, loads in a new process
		if ($run->worker === null && $this->installDataPackages($args, $configuration, (bool) $options->yes)) {
			passthru(
				implode(' ', array_map(escapeshellarg(...), [...WorkerPool::buildPhpCommand() ?? [PHP_BINARY], ...$_SERVER['argv']])),
				$exit,
			);
			return $exit;
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


	/**
	 * Installs the packages of upgrading data the libraries of the project need and DressCode lacks: beside DressCode,
	 * or into the project with `require --dev` where its lock holds DressCode; in a terminal after asking, elsewhere
	 * only with `--yes`.
	 * @return bool  whether it installed them
	 * @throws ConfigurationException  where it may not install them, or Composer failed
	 */
	private function installDataPackages(ParseResult $args, ConfigOptions $configuration, bool $yes): bool
	{
		['config' => $config, 'root' => $root] = $this->context->loadConfig($args, $configuration, new Config(paths: ['.']));
		$project = ProjectPackages::read($root);
		$missing = PackageDiscovery::findMissingDataPackages(
			$project,
			DataPackageCatalogue::load(RunnerFactory::resolveCacheDir($config, $root)),
		);
		if (!$missing) {
			return false;
		}

		$intoProject = $project->has('dresscode/dresscode');
		$directory = $intoProject ? $root : PackageDiscovery::findInstallationRoot();
		$names = implode(', ', array_map(fn(string $package) => "`$package`", $missing));
		$what = 'The libraries of the project have upgrading data in ' . $names . ', which DressCode does not have';
		if ($directory === null) {
			throw new ConfigurationException("$what, and its installation is not one Composer can add to; install the package beside DressCode.");
		}

		$command = ['composer', 'require', ...$intoProject ? ['--dev'] : [], '--working-dir=' . $directory, ...$missing];
		$shown = implode(' ', array_map(fn(string $arg) => str_contains($arg, ' ') ? "\"$arg\"" : $arg, $command));
		if (!$yes) {
			$prompt = $this->context->prompt;
			if (!$prompt->isInteractive()) {
				throw new ConfigurationException("$what; install it with `$shown`, or let `dresscode " . TodayUpgrade::Command . ' --yes` do it.');
			}

			$this->context->out->writeLine("$what.");
			$where = $intoProject ? 'into the project, which changes its `composer.json`' : "beside DressCode in `$directory`";
			if ($prompt->choose("Install it $where?", ['y', 'n'], 'y') !== 'y') {
				return false;
			}
		}

		passthru(implode(' ', array_map(escapeshellarg(...), $command)), $exit);
		if ($exit !== 0) {
			throw new ConfigurationException("Composer did not install the upgrading data: `$shown` exited with code $exit.");
		}

		return true;
	}
}
