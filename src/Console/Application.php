<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\{Config, ConfigurationException, ConvergenceException, Preset, PresetInfo, Profile, Reporter, Reporters, RuleException};
use DressCode\Config\{Loader, PhpVersionSource, RuleRegistry, RunnerFactory};
use DressCode\Engine\{FileSummary, Helpers, RunInfo, Runner, RunResult};
use Nette\CommandLine\{ColorDepth, Command, Console, HelpRenderer, ParseException as CommandLineException, Parser, ParseResult};
use Nette\Neon\{Exception as NeonException, Neon};
use Nette\Utils\FileSystem;
use function array_slice, count, extension_loaded, in_array, is_array, is_bool, is_int, is_string, sprintf;


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

	private Console $out;
	private Console $err;

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
			'configuration file; the nearest `dresscode.neon` or `dresscode.php` when omitted',
			alias: '-c',
			valueName: 'file',
		);
		$program->addOption('--preset', 'add a preset, which also runs without a configuration file', valueName: 'name', repeatable: true);
		$program->addOption('--rule', 'set a rule, `name` or `name=value` with the value in NEON: `true`, `false`, `keep`, the value of its decision such as `forbidden`, or its options as `{minImports: 2}`; a bare name enables it', valueName: 'spec', repeatable: true);
		$program->addFlag('--no-color', 'plain output');
		$program->addFlag('--help', 'print this help', standalone: true);
		$program->addFlag('--version', 'print the name and the version', standalone: true);

		$check = $program->addCommand('check', 'report violations');
		$fix = $program->addCommand('fix', 'fix what the rules can and report the rest');
		$program->addText('Exit codes: `0` clean, `1` violations or syntax errors, `2` a file failed, `3` a mistake of the command line or of the configuration.');

		foreach ([$check, $fix] as $command) {
			$command->addArgument('paths', 'files or directories; the configured paths when omitted', optional: true, repeatable: true);
		}

		foreach ([$check, $fix] as $command) {
			$command->addOption(
				'--format',
				'`github` when running there; `bare` says only what is left to the user and which files were rewritten, so a clean run says nothing at all',
				alias: '-f',
				enum: ['console', 'bare', 'github', 'json', 'checkstyle'],
			);
			$command->addFlag('--diff', 'show the fixes as a unified diff (`console` format)');
			$command->addOption(
				'--stdin',
				'read the code from stdin as if it were the file at the path; `fix` writes the result to stdout',
				valueName: 'path',
			);
			$command->addFlag('--skip-excluded', 'skip a named file that `excludePaths` of the configuration leaves out, which a hook or an editor naming every file it touches wants');
			$command->addOption(
				'--max-warnings',
				'exit with `1` when more than `n` warnings are left; without it any number of them keeps the run clean',
				valueName: 'n',
			);
			$command->addFlag('--fix-risky', 'also make the fixes that may change what the code does, not only those of the rules the configuration names in `fixRisky`; they are reported either way');
			$command->addFlag('--strict-rules', 'a rule breaking its contract is an error, not a warning');
		}

		foreach ([$check, $fix] as $command) {
			$command->addOption(
				'--only',
				'run only these of the rules the configuration comes to, a preset standing for all of its rules',
				valueName: 'name',
				repeatable: true,
			);
		}

		return $program;
	}


	private function runCheckOrFix(ParseResult $args, bool $fix): int
	{
		$factory = new RunnerFactory;
		[$config, $root, $configFile, $commandLine] = $this->loadConfig($args);
		$only = self::parseOnly($args);
		$runner = $factory->createRunner(
			$config,
			$root,
			$commandLine,
			$only,
			strict: (bool) $args['--strict-rules'],
			fixRisky: (bool) $args['--fix-risky'],
		);
		foreach ($factory->getWarnings() as $warning) {
			$this->err->writeLine(Markup::highlightCode($this->err, "Warning: $warning", 'yellow'));
		}

		$stdinPath = $args['--stdin'];
		$paths = array_values(array_unique(array_map($this->resolvePath(...), self::parsePaths($args))));
		$maxWarnings = $args['--max-warnings'] === null ? null : max(0, (int) $args['--max-warnings']);
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
		$files = $runner->findFiles($scope, skipExcluded: (bool) $args['--skip-excluded']);
		// the machine-readable formats must not be prefaced
		if (in_array($format, ['console', 'github'], true)) {
			if ($this->xdebug) {
				$this->err->writeLine('Warning: Xdebug is loaded and makes the run many times slower.', 'red');
			}

			$this->writeHeader($configFile, $config, $commandLine, self::describePhpVersion($factory));
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
		RuleRegistry $registry,
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
		$result = $runner->processFile($this->resolvePath($path), $code);
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
		$presets = array_map(
			fn(string $preset) => is_subclass_of($preset, Preset::class) ? PresetInfo::of($preset)->name : $preset,
			[...$config->presets, ...$commandLine->presets ?? []],
		);
		$this->out->writeLine($this->out->color('gray', 'Config     ') . ($configFile === null
			? 'none, preset ' . (implode(', ', $presets) ?: 'none')
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
	private static function describePhpVersion(RunnerFactory $factory): string
	{
		[$version, $source] = $factory->getPhpVersion();
		return $version . match ($source) {
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
	 * The configuration, the root directory, the file it came from, and the profile the command line lays over them.
	 * @return array{Config, string, ?string, ?Profile}
	 * @throws UsageException
	 */
	private function loadConfig(ParseResult $args): array
	{
		/** @var list<string> $presets */
		$presets = $args['--preset'];
		[$config, $root, $file] = (new Loader)->load(
			$args['--config'],
			$this->workingDirectory,
			// without a configuration file the run has only the presets named on the command line
			$this->defaultConfig ?? ($presets ? new Config : null),
		);
		$rules = [];
		foreach ($args['--rule'] as $rule) {
			[$name, $value] = explode('=', $rule, 2) + [1 => 'true'];
			try {
				$decoded = Neon::decode($value);
			} catch (NeonException $e) {
				throw new UsageException("Option `--rule` has an invalid value in `$rule`: {$e->getMessage()}", previous: $e);
			}

			if (!is_bool($decoded) && !is_string($decoded) && !is_int($decoded) && !is_array($decoded)) {
				throw new UsageException("Option `--rule` expects `true`, `false`, `keep`, a value of the decision or a map of options after `=`, `$rule` given.");
			}

			$rules[$name] = $decoded;
		}

		return [
			$config,
			$root,
			$file,
			$presets || $rules ? new Profile(presets: $presets, rules: $rules) : null,
		];
	}


	/**
	 * The rules and presets the run is narrowed to; null when it is not.
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
		RuleRegistry $registry,
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
				findRuleUrl: $registry->getRuleUrl(...),
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
