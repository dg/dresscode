<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\{Config, ConfigurationException, Plugin};
use DressCode\Config\{Loader, PhpVersionSource, ResolvedProject, RunnerFactory};
use DressCode\Console\Commands\ConfigOptions;
use DressCode\Engine\{Helpers, WorkerPool};
use DressCode\Interop\{FormatterConfig, FormatterSearch};
use Nette\CommandLine\{Console, ParseResult, Prompt, UsageException};
use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use function count, is_string;
use const JSON_PRETTY_PRINT, JSON_THROW_ON_ERROR, JSON_UNESCAPED_SLASHES;


/**
 * What every command of the command line gets: the process it runs in, the configuration the command line names, the
 * paths it names and the messages of the tool.
 * @internal
 */
final readonly class CommandContext
{
	/** marks a process of DressCode another one started again, which starts none itself */
	private const RerunVariable = 'DRESSCODE_RERUN';


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
		/** the script the workers and a run started again are started with */
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
	 * Runs DressCode again in a new process with the arguments and the variables given, which loads anew what this one
	 * loaded before the packages changed, its input and output those of this process; null where it cannot, as this
	 * process is embedded, so that its command line is not its own, or was started again itself.
	 * @param  list<string>  $arguments
	 * @param  array<string, string>  $env
	 */
	public function rerun(array $arguments, array $env = []): ?int
	{
		if ($this->stdout !== STDOUT || getenv(self::RerunVariable) !== false) {
			return null;
		}

		$command = [...WorkerPool::buildPhpCommand() ?? [PHP_BINARY], $this->scriptFile, ...$arguments];
		$env = [...getenv(), ...$env, self::RerunVariable => '1'];
		$process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $this->workingDirectory, $env);
		return $process === false ? null : proc_close($process);
	}


	/**
	 * A process started again writes after what the one that started it wrote, as a file of the output it inherits
	 * starts at its beginning on Windows.
	 */
	public function seekOutputEnd(): void
	{
		if (getenv(self::RerunVariable) !== false) {
			foreach ([$this->stdout, $this->stderr] as $stream) {
				@fseek($stream, 0, SEEK_END); // @ a terminal or a pipe has no end to seek
			}
		}
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
			$options->root === null ? null : Helpers::toAbsolutePath($options->root, $this->workingDirectory),
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


	/** Where the rules come from and the baseline that holds violations back, which is nothing the command line shows. */
	public function writeHeader(?string $configFile, ResolvedProject $resolution, ?string $baselineFile = null): void
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
		$this->out->writeLine($configFile === null
			? Markup::formatLine(
				$this->out,
				'Config',
				'none',
				', using ' . (implode(', ', array_map(fn(string $name) => "`$name`", $use)) ?: 'nothing'),
			)
			: Markup::formatLine($this->out, 'Config', FileSystem::platformSlashes($configFile)));
		if ($baselineFile !== null) {
			$this->out->writeLine(Markup::formatLine($this->out, 'Baseline', FileSystem::platformSlashes($baselineFile)));
		}

		$this->out->writeLine(
			Markup::formatLine(
				$this->out,
				'Target',
				'PHP ' . $resolution->resolvedConfig->phpVersion,
				self::describePhpVersionSource($resolution),
			),
		);
	}


	/** The name of the tool as it is written everywhere it appears. */
	public static function formatName(Console $console): string
	{
		return $console->color('white', 'DRESS') . $console->color('red', '|')
			. $console->color('white', 'CODE') . ' ' . $console->color('gray', Application::Version);
	}


	/** Where the version the rules target was taken from when the user did not choose it. */
	private static function describePhpVersionSource(ResolvedProject $resolution): string
	{
		return match ($resolution->phpVersionSource) {
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


	/**
	 * The document of the format `json` on the output, as it is.
	 * @param  array<string, mixed>  $data
	 */
	public function writeJson(array $data): void
	{
		fwrite($this->stdout, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
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


	/**
	 * The configuration of the formatter the project runs: the file named, else of those `FormatterSearch` finds the one
	 * the user chooses in a terminal, elsewhere the first, as it is at the end of the input, the others told.
	 * @throws ConfigurationException  for a file named that cannot be read
	 */
	public function chooseFormatterConfig(string $root, ?string $named, bool $interactive): FormatterChoice
	{
		if ($named !== null) {
			return new FormatterChoice(FormatterConfig::fromFile($root, $this->resolvePath($named)), named: true);
		}

		$candidates = FormatterSearch::find($root);
		if (!$interactive || count($candidates) < 2) {
			return new FormatterChoice(($candidates[0] ?? null)?->read($root), array_slice($candidates, 1));
		}

		$menu = '';
		foreach ($candidates as $i => $candidate) {
			$menu .= '  [' . ($i + 1) . '] ' . $candidate->format() . "\n";
		}

		$this->out->clearStatus();
		$this->write("\nThe project has the configurations of several formatters:\n$menu");
		$choice = $this->prompt->choose('Which one does it run?', array_map(strval(...), range(1, count($candidates))), '1');
		return new FormatterChoice(($candidates[(int) $choice - 1] ?? $candidates[0])->read($root));
	}


	/**
	 * Whether the rules of the configuration chosen may be read: data always, PHP, which runs when it is read, where the
	 * file was named, `$yes` agrees or the user does in a terminal, asked `$question` after what reading does.
	 */
	public function confirmReadingRules(FormatterChoice $choice, bool $interactive, string $question, bool $yes = false): bool
	{
		$config = $choice->config;
		return $config !== null && (!$config->formatter->runsCode() || $choice->named || $yes || ($interactive
			&& $this->prompt->choose(
				Markup::formatQuestion($this->out, $question, "`$config->file` is PHP, so reading it means running it."),
				['y', 'n'],
				'y',
			) === 'y'));
	}
}
