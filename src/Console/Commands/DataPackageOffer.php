<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\{Config, ConfigurationException};
use DressCode\Config\RunnerFactory;
use DressCode\Console\CommandContext;
use DressCode\Upgrade\{ComposerException, DataPackageInstallation};
use Nette\CommandLine\ParseResult;


/**
 * Installs the packages of upgrading data the libraries of the project need and DressCode lacks: beside DressCode, or
 * into the project with `require --dev` where its lock holds DressCode; in a terminal after asking, elsewhere only with
 * `--yes`.
 * @internal
 */
final readonly class DataPackageOffer
{
	public function __construct(
		private CommandContext $context,
	) {
	}


	/**
	 * Whether it installed them.
	 * @throws ConfigurationException  where it may not install them, or Composer failed
	 */
	public function install(ParseResult $args, ConfigOptions $configuration, bool $yes, bool $mayAsk): bool
	{
		['config' => $config, 'root' => $root] = $this->context->loadConfig($args, $configuration, new Config(paths: ['.']));
		$installation = DataPackageInstallation::find($root, RunnerFactory::resolveCacheDir($config, $root));
		if ($installation === null) {
			return false;
		}

		$names = implode(', ', array_map(fn(string $package) => "`$package`", $installation->packages));
		$reason = 'The libraries of the project have upgrading data in ' . $names . ', which DressCode does not have';
		if ($installation->directory === null) {
			throw new ConfigurationException("$reason, and its installation is not one Composer can add to; install the package beside DressCode.");
		}

		$command = $installation->formatCommand();
		if (!$yes) {
			if (!$mayAsk) {
				throw new ConfigurationException("$reason; install it with `$command`, or let `dresscode " . TodayUpgrade::Command . ' --yes` do it.');
			}

			$this->context->out->writeLine("$reason.");
			$where = $installation->intoProject
				? 'into the project, which changes its `composer.json`'
				: "beside DressCode in `$installation->directory`";
			if ($this->context->prompt->choose("Install it $where?", ['y', 'n'], 'y') !== 'y') {
				return false;
			}
		}

		// the progress goes to standard error, so that the output of the run stays the one document a format asks for
		$this->context->err->writeLine("Installing $names\u{2026}");
		try {
			$installation->install();
		} catch (ComposerException $e) {
			throw new ConfigurationException(
				"Composer did not install the upgrading data with `$command`: {$e->getMessage()}",
				previous: $e,
			);
		}

		return true;
	}
}
