<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\Config;
use DressCode\Config\RunnerFactory;
use DressCode\Console\{CommandContext, ConfigPrinter};
use DressCode\Engine\Helpers;
use Nette\CommandLine\{Attributes as Cli, ParseResult, UsageException};
use Nette\Utils\FileSystem;
use function is_string;


/**
 * The command `config`: prints the configuration as the run resolves it, or a preset alone, every decision a layer set
 * in the shape of the file with the layer that set it, and why a decision takes no effect where it does not.
 * @internal
 */
final readonly class ConfigCommand
{
	public function __construct(
		private CommandContext $context,
	) {
	}


	/**
	 * @param  list<string>  $only
	 */
	#[Cli\Command('Print the configuration as the run resolves it')]
	public function config(
		ParseResult $args,
		#[Cli\Option(
			'Only these: a decision, a section, a rule or a preset, narrowed to what the configuration runs; the values stay as the configuration resolves them',
			valueName: 'name',
			repeatable: true,
		)]
		array $only = [],
		#[Cli\Option('Print what the configuration comes to for that one file', valueName: 'path')]
		?string $file = null,
		#[Cli\Option('Print what the preset comes to on its own, instead of the configuration', valueName: 'name')]
		?string $preset = null,
		#[Cli\Option('Output format: `console` to read, `json` as data', alias: '-f', enum: ['console', 'json'])]
		?string $format = null,
	): int
	{
		$factory = new RunnerFactory;
		// a preset alone is what a project using it with nothing of its own comes to
		if (is_string($preset)) {
			try {
				$config = new Config(use: [$preset]);
			} catch (\InvalidArgumentException $e) {
				throw new UsageException("Option `--preset`: {$e->getMessage()}", $args->command, $e);
			}

			[$root, $configFile, $commandLine] = [
				Helpers::canonicalizePath($this->context->workingDirectory),
				null,
				$this->context->readCommandLine($args),
			];

		} else {
			['config' => $config, 'root' => $root, 'file' => $configFile, 'commandLine' => $commandLine] = $this->context->loadConfig(
				$args,
			);
		}
		$resolution = $factory->resolve($config, $root, $commandLine, $only ?: null);
		$resolved = is_string($file)
			? $resolution->resolveFor(
				$factory->createRunner($resolution, baseline: false)->findOverridesFor($this->context->resolvePath($file)),
			)
			: $resolution->resolvedConfig;
		$printer = new ConfigPrinter($resolved);
		$out = $this->context->out;
		if ($format === 'json') {
			$out->write($printer->printJson());
			return 0;
		}

		$this->context->writeHeader($configFile, $resolution);
		if (is_string($file)) {
			$out->writeLine($out->color('gray', 'File       ') . FileSystem::platformSlashes($file));
		}

		$out->write($printer->print($out));
		return 0;
	}
}
