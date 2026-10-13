<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\{Config, Plugin};
use DressCode\Config\{Loader, PhpVersionSource, ResolvedProject, RunnerFactory};
use DressCode\Console\Commands\ConfigOptions;
use DressCode\Engine\Helpers;
use Nette\CommandLine\{Console, ParseResult, Prompt, UsageException};
use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use function is_string;


/**
 * What every command of the command line gets: the process it runs in, the configuration the command line names, the
 * paths it names and the messages of the tool.
 * @internal
 */
final readonly class CommandContext
{
	public function __construct(
		/** @var resource */
		public mixed $stdout,
		/** @var resource */
		public mixed $stderr,
		/** @var resource */
		public mixed $stdin,
		public Console $out,
		public Console $err,
		/** the directory the paths of the command line are relative to */
		public string $workingDirectory,
		/** the script the workers are started with */
		public string $scriptFile,
		/** what applies when the project has no configuration file */
		public ?Config $defaultConfig,
		/** PHP runs with Xdebug, which makes a run many times slower */
		public bool $xdebug,
		/** asks a person at a terminal, which `fix --ask-risky` does */
		public Prompt $prompt,
	) {
	}


	/**
	 * The project as the command line names it, resolved: the factory that resolved it, its resolution and the file
	 * of the configuration.
	 * @param  list<string>  $only  the decisions, sections, rules and presets the run is narrowed to, none when empty
	 * @return array{RunnerFactory, ResolvedProject, ?string}
	 * @throws UsageException
	 */
	public function resolveProject(ParseResult $args, ConfigOptions $options, array $only = []): array
	{
		$factory = new RunnerFactory;
		['config' => $config, 'root' => $root, 'file' => $file, 'commandLine' => $commandLine] = $this->loadConfig($args, $options);
		return [$factory, $factory->resolve($config, $root, $commandLine, $only ?: null), $file];
	}


	/**
	 * The configuration, the root directory, the file it came from, and the layer the command line lays over them.
	 * @param  ?Config  $default  the configuration of a command that runs without a file
	 * @return array{config: Config, root: string, file: ?string, commandLine: ?Config}
	 * @throws UsageException
	 */
	public function loadConfig(ParseResult $args, ConfigOptions $options, ?Config $default = null): array
	{
		[$config, $root, $file] = Loader::load(
			$options->config === null ? null : Helpers::toAbsolutePath($options->config, $this->workingDirectory),
			$this->workingDirectory,
			// without a configuration file the run has only what the command line uses
			$this->defaultConfig ?? $default ?? ($options->use ? new Config : null),
		);
		return ['config' => $config, 'root' => $root, 'file' => $file, 'commandLine' => $this->readCommandLine($args, $options)];
	}


	/**
	 * The layer the command line lays over the configuration: the presets of `--use`, the decisions of `--set`, what
	 * `--severity` says and what `--fix-risky` allows; null where it says none of them.
	 * @throws UsageException
	 */
	public function readCommandLine(ParseResult $args, ConfigOptions $options): ?Config
	{
		$use = [];
		foreach ($options->use as $spec) {
			// a file named on the command line is relative to the working directory, not to the root
			$use[] = str_ends_with($spec, '.neon') ? Helpers::toAbsolutePath($spec, $this->workingDirectory) : $spec;
		}

		$decisions = [];
		foreach ($options->set as $spec) {
			[$path, $value] = explode('=', $spec, 2);
			$decisions = Helpers::placeValue($decisions, $path, Neon::decode($value));
		}

		$severity = [];
		foreach ($options->severity as $spec) {
			[$name, $value] = explode('=', $spec, 2);
			$severity[$name] = $value;
		}

		// the decisions, rules and presets whose risky fixes the run allows; a bare `--fix-risky` allows every one elsewhere,
		// and a command without the option allows none
		$fixRisky = array_values(array_filter((array) ($args->toArray()['--fix-risky'] ?? []), is_string(...)));
		try {
			return $use || $fixRisky || $decisions || $severity
				? new Config(use: $use, fixRisky: $fixRisky, severity: $severity, decisions: $decisions)
				: null;
		} catch (\InvalidArgumentException $e) {
			throw new UsageException("Option `--use`: {$e->getMessage()}", $args->command, $e);
		}
	}


	/**
	 * A path named on the command line is relative to the working directory, unlike those of the configuration,
	 * and spelled the way the root is, so that it is recognized under it.
	 */
	public function resolvePath(string $path): string
	{
		$resolved = FileSystem::resolvePath($this->workingDirectory, $path);
		return Helpers::canonicalizePath(realpath($resolved) ?: $resolved);
	}


	/** Where the rules come from, which is nothing the command line shows. */
	public function writeHeader(?string $configFile, ResolvedProject $resolution): void
	{
		[$config, $commandLine] = [$resolution->config, $resolution->commandLine];
		$this->out->writeLine(self::formatName($this->out));
		$use = [
			...array_map(
				fn(string|Plugin $plugin) => is_string($plugin) ? $plugin : $plugin::class,
				[...$config->plugins, ...$commandLine instanceof Config ? $commandLine->plugins : []],
			),
			...$config->use,
			...$resolution->laid,
			...$commandLine->use ?? [],
		];
		$this->out->writeLine($this->out->color('gray', 'Config     ') . ($configFile === null
			? 'none, using ' . (implode(', ', $use) ?: 'nothing')
			: FileSystem::platformSlashes($configFile)));
		$this->out->writeLine(
			$this->out->color('gray', 'Target     ') . Markup::highlightCode($this->out, 'PHP ' . self::describePhpVersion($resolution)),
		);
	}


	/** The name of the tool as it is written everywhere it appears. */
	public static function formatName(Console $console): string
	{
		return $console->color('white', 'DRESS') . $console->color('red', '|')
			. $console->color('white', 'CODE') . ' ' . $console->color('gray', Application::Version);
	}


	/** The version the rules target, said with where it was taken from when the user did not choose it. */
	private static function describePhpVersion(ResolvedProject $resolution): string
	{
		return $resolution->resolvedConfig->phpVersion . match ($resolution->phpVersionSource) {
			PhpVersionSource::Configuration => '',
			PhpVersionSource::Composer => ' from `composer.json`',
			PhpVersionSource::Default
				=> ' by default, `composer.json` names no PHP version in `require`; set `targets: {php: …}` in the configuration',
		};
	}


	/** A message of the tool on the output, its code drawn; what is not a message (code, JSON) goes to `$out` itself. */
	public function write(string $text): void
	{
		$this->out->write(Markup::highlightCode($this->out, $text));
	}


	/** A message of the tool on the error output, which keeps the output for what the command produces. */
	public function writeNote(string $text): void
	{
		$this->err->write(Markup::highlightCode($this->err, $text));
	}


	/** An error that ends the run, followed by the detail and the page of the manual that says more. */
	public function writeError(string $message, ?string $docs = null, string $detail = ''): void
	{
		$this->err->write($this->err->color('red', 'Error:') . ' ' . Markup::highlightCode($this->err, $message) . "\n" . $detail);
		if ($docs !== null) {
			$this->err->writeLine(Markup::formatDocsLink($this->err, $docs));
		}
	}
}
