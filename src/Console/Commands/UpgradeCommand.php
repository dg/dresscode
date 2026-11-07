<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\{Analyses, Config, ConfigurationException};
use DressCode\Config\{PackageDiscovery, Versions};
use DressCode\Console\{CommandContext, EstimatePrinter, Markup, RunExecutor};
use DressCode\Engine\{Worker, WorkerPool};
use DressCode\Upgrade\{Estimate, Estimator, Project, Target, UpgradeCheck};
use Nette\CommandLine\{Attributes as Cli, Console, ParseResult, UsageException};
use Nette\Utils\FileSystem;


/**
 * The commands of `upgrade`: what moving the code to newer versions of PHP and of the libraries changes, and the code
 * moved to the lowest versions the project allows.
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
			$console->write($printer->print($console));
		}

		return $estimate->isComplete() ? 0 : 2;
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
			new CheckOptions(fixRisky: $options->fixRisky, only: $options->only),
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


	/** @param  array<string, mixed>  $data */
	private function writeJson(Project $project, array $data): void
	{
		$data = [
			'version' => $data['version'],
			'root' => $project->root,
			...$data,
		];
		$this->context->writeJson($data);
	}


	/** The name of the tool and the configuration the command measures with. */
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
