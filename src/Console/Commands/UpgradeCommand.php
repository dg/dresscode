<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\{Analyses, Config, ConfigurationException};
use DressCode\Config\{DataPackageCatalogue, PackageDiscovery, ProjectPackages, RunnerFactory, Versions};
use DressCode\Console\{CommandContext, EstimatePrinter, Markup, PlanPrinter, RunExecutor};
use DressCode\Engine\{Worker, WorkerPool};
use DressCode\Measuring\Scope;
use DressCode\Upgrade\{EstimateRun, Estimator, PlanBuilder, PlanFile, Project, Target};
use Nette\CommandLine\{Attributes as Cli, ParseResult, UsageException};
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

		return $this->offer($args, $target, $version, $options, $configuration);
	}


	/**
	 * Measures what upgrading to the targets costs and writes it, changing nothing, and with the options of the plan
	 * orders it into the steps of an upgrade, which it shows or saves.
	 */
	private function offer(
		ParseResult $args,
		?string $target,
		?string $version,
		UpgradeOptions $options,
		ConfigOptions $configuration,
	): int
	{
		$project = $this->loadProject($args, $configuration);
		$console = ($options->format ?? 'console') === 'console' ? $this->context->out : null;
		$estimator = new Estimator(
			$project,
			$this->decideTypes($options, $project->config),
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
			$console->writeLine(CommandContext::formatName($console));
			$console->writeLine($console->color('gray', 'Config     ') . ($project->configFile === null
				? 'none, the scope `init` would write: ' . implode(', ', $project->config->paths)
				: FileSystem::platformSlashes($project->configFile)));
		}

		try {
			$estimate = $estimator->estimate($targets, array_map($this->context->resolvePath(...), $options->paths));
		} finally {
			$console?->clearStatus();
		}

		$plan = $options->makesPlan()
			? new PlanBuilder(ProjectPackages::read($project->root), PlanBuilder::isLibrary($project->root))
				->build($estimate, $options->testCommand, $options->quickTestCommand)
			: null;
		$printer = $plan === null ? new EstimatePrinter($estimate) : new PlanPrinter($plan);
		if ($console === null) {
			$json = json_encode($printer->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
			fwrite($this->context->stdout, "$json\n");
		} else {
			$console->write(Markup::highlightCode($console, $printer->print($console)));
		}

		if ($plan !== null && $options->savePlan) {
			PlanFile::write($plan, "$project->root/" . PlanFile::Name);
			$console?->writeLine($console->color('gray', 'Saved      ') . PlanFile::Name);
		}

		return 0;
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
			new CheckOptions(fixRisky: $options->fixRisky, only: $options->only),
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
