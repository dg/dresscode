<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\{Config, ConfigurationException};
use DressCode\Config\{DataPackageCatalogue, PackageDiscovery, ProjectPackages, RunnerFactory};
use DressCode\Console\{CommandContext, RunExecutor};
use DressCode\Engine\WorkerPool;
use Nette\CommandLine\{Attributes as Cli, ParseResult};


/**
 * The command `upgrade`: the code moved to the lowest versions of PHP and of the libraries the project allows.
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


	#[Cli\Command('Upgrade the code to the lowest versions of PHP and of the libraries `composer.json` allows, after raising them: the set `deprecations` and the packages of upgrading data with the types of the code, in the style the project keeps')]
	public function upgrade(ParseResult $args, UpgradeOptions $options, ConfigOptions $configuration): int
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
