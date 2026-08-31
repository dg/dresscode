<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\{Config, ConfigurationException, ConvergenceException, Plugin, Profile, Reporter, Reporters, RuleException};
use DressCode\Config\{Catalogue, ConfigResolver, CorePlugin, Loader, PhpVersionSource, PluginRegistry, ResolvedProject, RunnerFactory};
use DressCode\Engine\{FileSummary, Helpers, RunInfo, Runner, RunResult};
use Nette\CommandLine\{Ansi, ColorDepth, Command, Console, HelpRenderer, Normalizers, ParseException as CommandLineException, Parser, ParseResult};
use Nette\Neon\{Exception as NeonException, Neon};
use Nette\Utils\{FileSystem, Json};
use function count, extension_loaded, in_array, is_string, sprintf;


/**
 * The dresscode command line.
 */
final class Application
{
	public const Version = '1.0-dev';

	/** @var resource */
	private $stdout;

	/** @var resource */
	private $stderr;

	/** @var resource */
	private $stdin;

	private readonly Console $out;
	private readonly Console $err;

	/** PHP runs with Xdebug, which makes a run many times slower */
	private readonly bool $xdebug;

	/** the directory the paths of the command line are relative to */
	private readonly string $workingDirectory;


	/**
	 * @param  ?resource  $stdout
	 * @param  ?resource  $stderr
	 * @param  ?resource  $stdin
	 * @param  ?bool  $xdebug  whether Xdebug is loaded; detected when the output is the process's own
	 */
	public function __construct(
		$stdout = null,
		$stderr = null,
		$stdin = null,
		private readonly ?string $cwd = null,
		/** what applies when the project has no configuration file */
		private readonly ?Config $defaultConfig = null,
		?bool $xdebug = null,
	) {
		$this->stdout = $stdout ?? STDOUT;
		$this->stderr = $stderr ?? STDERR;
		$this->stdin = $stdin ?? STDIN;
		// what the caller hands in is captured output, which FORCE_COLOR must not color
		$this->out = new Console($this->stdout, colorDepth: $stdout === null ? null : ColorDepth::None);
		$this->err = new Console($this->stderr, colorDepth: $stderr === null ? null : ColorDepth::None);
		$this->xdebug = $xdebug ?? ($stdout === null && extension_loaded('xdebug'));
		$this->workingDirectory = $cwd ?? (string) getcwd();
	}


	/**
	 * Runs the command line and returns the exit code: 0 clean, 1 violations or syntax errors, 2 a file that
	 * failed, 3 a mistake of the command line or of the configuration.
	 * @param  list<string>  $argv  including the script name
	 */
	public function run(array $argv): int
	{
		$program = self::defineCommandLine();
		$command = $program;
		try {
			$args = (new Parser)->parse($program, array_slice($argv, 1));
			$command = $args->command;
			if ($args['--no-color']) {
				$this->out->setColorDepth(ColorDepth::None);
				$this->err->setColorDepth(ColorDepth::None);
			}

			if ($args['--version']) {
				$this->out->writeLine($this->formatName($this->out));
				return 0;
			} elseif ($args['--help'] || $command === $program) {
				$this->out->writeLine($this->formatName($this->out) . "\n");
				new HelpRenderer($this->out)->render($command);
				return $command === $program && !$args['--help'] ? 3 : 0;
			}

			return match ($command->name) {
				'check' => $this->runCheckOrFix($args, fix: false),
				'fix' => $this->runCheckOrFix($args, fix: true),
				'catalogue' => $this->runCatalogue($args),
				default => throw new \LogicException("Command '{$command->name}' has no handler."),
			};

		} catch (CommandLineException $e) {
			$this->writeUsageError($e->getMessage(), $e->command);
			return 3;

		} catch (UsageException $e) {
			$this->writeUsageError($e->getMessage(), $command);
			return 3;

		} catch (ConfigurationException $e) {
			$this->writeError($e->getMessage(), $e->docs);
			return 3;

		} catch (RuleException|\RuntimeException $e) {
			$this->writeError($e->getMessage());
			return 2;

		} catch (ConvergenceException $e) {
			$this->writeError(
				$e->getMessage(),
				ConvergenceException::Docs,
				$e->diff === '' ? '' : "The two states differ:\n" . Markup::highlightDiff($this->err, $e->diff),
			);
			return 2;

		} catch (\Throwable $e) { // an error of the tool itself
			$this->writeError($e->getMessage(), detail: get_debug_type($e) . ' in ' . FileSystem::platformSlashes($e->getFile()) . ":{$e->getLine()}\n" . $e->getTraceAsString() . "\n");
			return 2;
		}
	}


	/**
	 * The commands of the tool; the options of the program apply to every one of them.
	 */
	private static function defineCommandLine(): Command
	{
		$program = new Command('dresscode', 'A dress code for PHP: checks, fixes and upgrades the code.');
		$program->addOption(
			'--config',
			'Configuration file; the nearest `dresscode.neon` or `dresscode.php` when omitted',
			alias: '-c',
			valueName: 'file',
		);
		$program->addOption(
			'--use',
			'Add a preset or a plugin over the configuration, even without a configuration file',
			valueName: 'name',
			repeatable: true,
		);
		$program->addOption(
			'--set',
			'Set a decision over the configuration as `path=value`, the value in NEON: `blankLines.betweenMethods=1`, `spacing.fnKeyword="fn ($x) => $x"`',
			valueName: 'path=value',
			repeatable: true,
		);
		$program->addFlag('--no-color', 'Print plain output without colors');
		$program->addFlag('--help', 'Print this help', standalone: true);
		$program->addFlag('--version', 'Print the name and the version', standalone: true);

		$check = $program->addCommand('check', 'Report the violations');
		$fix = $program->addCommand('fix', 'Fix what the rules can and report the rest');
		$catalogue = $program->addCommand('catalogue', 'List every decision the rules of the run declare, those this configuration makes marked');
		$program->addText('Exit codes: `0` clean, `1` violations or syntax errors, `2` a file failed, `3` a mistake of the command line or of the configuration.');

		foreach ([$check, $fix] as $command) {
			$command->addArgument('paths', 'Files or directories; the configured paths when omitted', optional: true, repeatable: true);
			$command->addOption(
				'--format',
				'Output format, `github` by default in GitHub Actions; `bare` prints only what is left to the user and which files were rewritten, so a clean run prints nothing',
				alias: '-f',
				enum: ['console', 'bare', 'github', 'json', 'checkstyle'],
			);
			$command->addFlag('--diff', 'Show the fixes as a unified diff (`console` format)');
			$command->addOption(
				'--stdin',
				'Read the code from stdin as if it were the file at the path; `fix` writes the result to stdout',
				valueName: 'path',
			);
			$command->addFlag('--skip-excluded', 'Skip a named file that `excludePaths` leaves out, as a hook or an editor passing every file it touches needs');
			$command->addOption(
				'--max-warnings',
				'Exit with `1` when more than `n` warnings are left; without it any number of warnings keeps the run clean',
				valueName: 'n',
				normalizer: Normalizers::int(min: 0),
			);
			$command->addFlag('--fix-risky', 'Also make the fixes that may change what the code does, beyond those `fixRisky` allows; they are reported either way');
			$command->addFlag('--strict-rules', 'Treat a rule breaking its contract as an error, not a warning');
		}

		foreach ([$check, $fix, $catalogue] as $command) {
			$command->addOption(
				'--only',
				'Only these: a decision, a section, a rule or a preset, narrowed to what the configuration runs; the values stay as the configuration resolves them',
				valueName: 'name',
				repeatable: true,
			);
		}

		$catalogue->addOption('--format', 'Output format: `console` to read, `json` as data', alias: '-f', enum: ['console', 'json']);
		return $program;
	}


	private function runCheckOrFix(ParseResult $args, bool $fix): int
	{
		$factory = new RunnerFactory;
		['config' => $config, 'root' => $root, 'file' => $configFile, 'commandLine' => $commandLine] = $this->loadConfig($args);
		$only = self::parseOnly($args);
		$resolution = $factory->resolve($config, $root, $commandLine, $only);
		$runner = $factory->createRunner(
			$resolution,
			strict: (bool) $args['--strict-rules'],
			fixRisky: (bool) $args['--fix-risky'],
		);
		foreach ($resolution->warnings as $warning => $docs) {
			$this->err->writeLine(Markup::highlightCode($this->err, "Warning: $warning", 'yellow'));
			if ($docs !== null) {
				$this->err->writeLine(Markup::formatDocsLink($this->err, $docs));
			}
		}

		$stdinPath = $args['--stdin'];
		$paths = array_values(array_unique(array_map($this->resolvePath(...), self::parsePaths($args))));
		$maxWarnings = $args['--max-warnings'];
		if (is_string($stdinPath)) {
			if ($paths) {
				throw new UsageException('Paths cannot be combined with `--stdin`.');
			}

			return $this->runStdin($args, $runner, $factory->registry, $root, $stdinPath, $fix, $maxWarnings);
		}

		$scope = $paths ? $runner->narrowPaths($paths, $config->paths) : $config->paths;
		if (!$scope) {
			throw new UsageException('No paths given and none configured.');
		}

		$format = self::resolveFormat($args, detect: true);
		// the machine-readable formats must not be prefaced
		$preface = in_array($format, ['console', 'github'], true);
		if ($preface) {
			if ($this->xdebug) {
				$this->err->writeLine('Warning: Xdebug is loaded and makes the run many times slower.', 'red');
			}

			$this->writeHeader($configFile, $config, $commandLine, self::describePhpVersion($resolution));
			$this->out->setStatus($this->out->color('gray', $fix ? 'Fixing     ' : 'Checking   ') . 'looking for files…');
		}

		try {
			$files = $runner->findFiles($scope, skipExcluded: (bool) $args['--skip-excluded']);
		} finally {
			$this->out->clearStatus();
		}

		if ($preface) {
			$this->writeScope(files: $files, paths: $scope, root: $root, fix: $fix, narrowed: $paths && $scope !== $paths);
		}

		$progress = $format === 'console' && count($files) > 1 && $this->out->isTerminal()
			? new ProgressBar($this->out, count($files))
			: null;
		$onProgress = $progress === null ? null : $progress->advance(...);
		$reporter = $this->createReporter($args, $this->out, $this->stdout, $root, $format, $factory->registry);

		try {
			return $runner->run($files, $fix, $reporter, $onProgress, $maxWarnings)->getExitCode();
		} finally {
			$progress?->clear(); // an error must not be written into the bar
		}
	}


	/**
	 * Processes the code read from stdin as if it were the file at the path; a fix writes the fixed code to stdout and
	 * its report to stderr.
	 */
	private function runStdin(
		ParseResult $args,
		Runner $runner,
		PluginRegistry $registry,
		string $root,
		string $path,
		bool $fix,
		?int $maxWarnings,
	): int
	{
		// the caller of stdin is an editor or a hook waiting for the format it asked for
		$format = self::resolveFormat($args, detect: false);
		$reporter = $fix
			? $this->createReporter($args, $this->err, $this->stderr, $root, $format, $registry)
			: $this->createReporter($args, $this->out, $this->stdout, $root, $format, $registry);
		$code = (string) stream_get_contents($this->stdin);
		$reporter->start(new RunInfo($root, $fix, 1));
		$result = $runner->processPath($this->resolvePath($path), fix: false, code: $code);
		$reporter->reportFile($result);
		$run = new RunResult([FileSummary::of($result)], $fix, maxWarnings: $maxWarnings);
		$reporter->finish($run);
		if ($fix) {
			$this->out->write($result->output ?? $code);
		}

		return $run->getExitCode();
	}


	/** Where the rules come from, which is nothing the command line shows. */
	private function writeHeader(?string $configFile, Config $config, ?Profile $commandLine, string $phpVersion): void
	{
		$this->out->writeLine($this->formatName($this->out));
		$use = [
			...array_map(fn(string|Plugin $plugin) => is_string($plugin) ? $plugin : $plugin::class, [...$config->plugins, ...$commandLine instanceof Config ? $commandLine->plugins : []]),
			...$config->use,
			...$commandLine->use ?? [],
		];
		$this->out->writeLine($this->out->color('gray', 'Config     ') . ($configFile === null
			? 'none, using ' . (implode(', ', $use) ?: 'nothing')
			: FileSystem::platformSlashes($configFile)));
		$this->out->writeLine($this->out->color('gray', 'Target     ') . Markup::highlightCode($this->out, "PHP $phpVersion"));
	}


	/**
	 * What the rules are applied to: the scope reaches wherever the configuration was found,
	 * not where the run was started.
	 * @param  list<string>  $files  relative to the root, or absolute when outside it
	 * @param  list<string>  $paths  they were found under these
	 * @param  bool  $narrowed  a directory named on the command line was narrowed to the configured paths
	 */
	private function writeScope(array $files, array $paths, string $root, bool $fix, bool $narrowed): void
	{
		$absolute = array_map(fn(string $file) => FileSystem::isAbsolute($file) ? $file : "$root/$file", $files);
		$scope = count($files) === 1
			? FileSystem::platformSlashes($absolute[0])
			: sprintf('%d files in %s', count($files), FileSystem::platformSlashes(self::findCommonDirectory($absolute) ?: $root));
		$this->out->write($files
			? $this->out->color('gray', $fix ? 'Fixing     ' : 'Checking   ') . $scope
				. ($narrowed ? $this->out->color('gray', ', narrowed to the configured paths') : '') . "\n\n"
			: $this->out->color('yellow', sprintf(
				($fix ? 'Nothing to fix' : 'Nothing to check') . ': no file in %s',
				implode(', ', array_map(FileSystem::platformSlashes(...), $paths)),
			)) . "\n");
	}


	/** The name of the tool as it is written everywhere it appears. */
	private function formatName(Console $console): string
	{
		return $console->color('white', 'DRESS') . $console->color('red', '|')
			. $console->color('white', 'CODE') . ' ' . $console->color('gray', self::Version);
	}


	/** The version the rules target, said with where it was taken from when the user did not choose it. */
	private static function describePhpVersion(ResolvedProject $resolution): string
	{
		return $resolution->phpVersion . match ($resolution->phpVersionSource) {
			PhpVersionSource::Configuration => '',
			PhpVersionSource::Composer => ' from `composer.json`',
			PhpVersionSource::Default => ' by default, `composer.json` names no PHP version in `require`; set `targets: {php: …}` in the configuration',
		};
	}


	/**
	 * The directory all the files share; that is the scope the run really has.
	 * @param  list<string>  $files
	 */
	private static function findCommonDirectory(array $files): string
	{
		$common = null;
		foreach ($files as $file) {
			$segments = explode('/', $file);
			array_pop($segments);
			if ($common === null) {
				$common = $segments;
				continue;
			}

			$length = 0;
			while (isset($common[$length], $segments[$length]) && $common[$length] === $segments[$length]) {
				$length++;
			}

			$common = array_slice($common, 0, $length);
		}

		return implode('/', $common ?? []);
	}


	/**
	 * Lists every decision of the catalogue with its description, those the configuration makes marked; or writes the
	 * catalogue as data, the values of the standards included.
	 */
	private function runCatalogue(ParseResult $args): int
	{
		$factory = new RunnerFactory;
		['config' => $config, 'root' => $root, 'commandLine' => $commandLine] = $this->loadConfig($args);
		$resolution = $factory->resolve($config, $root, $commandLine, self::parseOnly($args));
		$catalogue = $resolution->getCatalogue();
		if ($args['--format'] === 'json') {
			$data = $catalogue->toArray();
			foreach (self::resolveStandards($factory->registry, $resolution->resolvedConfig->phpVersion) as $standard => $decisions) {
				foreach ($decisions as $path => $decision) {
					if (isset($data['decisions'][$path])) {
						$data['decisions'][$path]['standards'][$standard] = $decision->value->toData();
					}
				}
			}

			$this->out->write(Json::encode($data, pretty: true) . "\n");
			return 0;
		}

		$made = $resolution->resolvedConfig->decisions;
		// the sections of the core in their order, those of the plugins and of the project after them
		$decisions = $catalogue->getDecisions();
		$order = array_flip(Catalogue::CoreSections);
		uksort($decisions, fn(string $a, string $b) => ($order[explode('.', $a)[0]] ?? PHP_INT_MAX) <=> ($order[explode('.', $b)[0]] ?? PHP_INT_MAX));
		foreach ($decisions as $path => $decision) {
			$set = isset($made[$path]) && !$made[$path]->value->isKept();
			$this->out->writeLine(
				($set ? '*' : ' ')
				. ' ' . Ansi::pad($this->out->color($set ? 'white' : null, $path), 50)
				. ' ' . Markup::highlightCode($this->out, $decision->description),
			);
		}

		$this->out->writeLine("\n* made by the configuration");
		return 0;
	}


	/**
	 * The decisions each of the three standards makes, resolved alone.
	 * @return array<string, array<string, Config\ResolvedDecision>>  standard => path => its decision
	 */
	private static function resolveStandards(PluginRegistry $registry, string $phpVersion): array
	{
		$standards = [];
		foreach (CorePlugin::Standards as $standard) {
			$standards[$standard] = new ConfigResolver($registry)
				->resolve(new Config(use: ["dresscode/$standard"]), $phpVersion)
				->decisions;
		}

		return $standards;
	}


	/**
	 * The configuration, the root directory, the file it came from, and the profile the command line lays over them.
	 * @return array{config: Config, root: string, file: ?string, commandLine: ?Profile}
	 * @throws UsageException
	 */
	private function loadConfig(ParseResult $args): array
	{
		/** @var list<string> $specs */
		$specs = $args['--use'];
		[$config, $root, $file] = Loader::load(
			$args['--config'],
			$this->workingDirectory,
			// without a configuration file the run has only what the command line uses
			$this->defaultConfig ?? ($specs ? new Config : null),
		);
		$use = [];
		foreach ($specs as $spec) {
			if (str_contains($spec, '=')) {
				throw new UsageException("Option `--use` takes a preset or a plugin, `$spec` given; a decision is set by `--set path=value`.");
			}

			// a file named on the command line is relative to the working directory, not to the root
			$use[] = str_ends_with($spec, '.neon') ? RunnerFactory::toAbsolutePath($spec, $this->workingDirectory) : $spec;
		}

		$decisions = [];
		foreach ((array) ($args['--set'] ?? []) as $spec) {
			$parts = explode('=', (string) $spec, 2);
			if (count($parts) !== 2 || !preg_match('~^\w+(\.\w+)+$~D', $parts[0])) {
				throw new UsageException("Option `--set` takes `path=value`, `$spec` given.");
			}

			$decisions = Helpers::placeValue($decisions, $parts[0], self::decodeValue($spec, $parts[1]));
		}

		try {
			$commandLine = $use || $decisions ? new Config(use: $use, decisions: $decisions) : null;
		} catch (\InvalidArgumentException $e) {
			throw new UsageException("Option `--use`: {$e->getMessage()}", previous: $e);
		}

		return ['config' => $config, 'root' => $root, 'file' => $file, 'commandLine' => $commandLine];
	}


	/**
	 * The value of a decision after `=`, read as NEON the way the configuration reads it.
	 * @throws UsageException
	 */
	private static function decodeValue(string $spec, string $value): mixed
	{
		try {
			return Neon::decode($value);
		} catch (NeonException $e) {
			throw new UsageException("Option `--set` has an invalid value in `$spec`: {$e->getMessage()}", previous: $e);
		}
	}


	/**
	 * The decisions, sections, rules and presets the run is narrowed to; null when it is not.
	 * @return ?list<string>
	 */
	private static function parseOnly(ParseResult $args): ?array
	{
		/** @var list<string> $only */
		$only = $args['--only'];
		return $only ?: null;
	}


	/** @return list<string> */
	private static function parsePaths(ParseResult $args): array
	{
		/** @var list<string> $paths */
		$paths = $args['paths'];
		return $paths;
	}


	/**
	 * A path named on the command line is relative to the working directory, unlike those of the configuration,
	 * and spelled the way the root is, so that it is recognized under it.
	 */
	private function resolvePath(string $path): string
	{
		$resolved = FileSystem::resolvePath($this->workingDirectory, $path);
		return Helpers::canonicalizePath(realpath($resolved) ?: $resolved);
	}


	/**
	 * @param  resource  $stream  the same place as the console; a machine-readable format is no text of a console
	 * @param  string  $root  the paths of the results are relative to it
	 */
	private function createReporter(
		ParseResult $args,
		Console $console,
		$stream,
		string $root,
		string $format,
		PluginRegistry $registry,
	): Reporter
	{
		return match ($format) {
			'json' => new Reporters\JsonReporter($stream),
			'checkstyle' => new Reporters\CheckstyleReporter($stream),
			'github' => new Reporters\GithubReporter($stream, $root, self::findEnv('GITHUB_WORKSPACE')),
			default => new Reporters\ConsoleReporter(
				$console,
				diff: (bool) $args['--diff'],
				root: $root,
				cwd: Helpers::canonicalizePath($this->workingDirectory),
				bare: $format === 'bare',
				findDecisionUrl: $registry->findUrl(...),
			),
		};
	}


	/**
	 * The format asked for, or the one the surroundings call for: annotations when the run is a step
	 * of a GitHub Actions workflow, where nobody reads the log.
	 * @param  bool  $detect  let the surroundings decide when the command line does not
	 */
	private static function resolveFormat(ParseResult $args, bool $detect): string
	{
		return is_string($args['--format'])
			? $args['--format']
			: ($detect && self::findEnv('GITHUB_ACTIONS') === 'true' ? 'github' : 'console');
	}


	private static function findEnv(string $name): ?string
	{
		$value = getenv($name);
		return $value === false || $value === '' ? null : $value;
	}


	/** An error that ends the run, followed by the detail and the page of the manual that says more. */
	private function writeError(string $message, ?string $docs = null, string $detail = ''): void
	{
		$this->err->write($this->err->color('red', 'Error:') . ' ' . Markup::highlightCode($this->err, $message) . "\n" . $detail);
		if ($docs !== null) {
			$this->err->writeLine(Markup::formatDocsLink($this->err, $docs));
		}
	}


	/** A mistake in how the tool was called, followed by the help of the command it was called with. */
	private function writeUsageError(string $message, Command $command): void
	{
		$this->err->writeLine($this->err->color('red', 'Error:') . ' ' . Markup::highlightCode($this->err, $message) . "\n");
		new HelpRenderer($this->err)->render($command);
	}
}
