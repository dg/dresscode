<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\{Config, ConfigurationException, ConvergenceException, Helpers, Preset, PresetInfo, Profile, Reporter, Reporters, RuleException, RuleInfo, RunResult};
use DressCode\Config\{Loader, PhpVersionSource, RunnerFactory};
use Nette\CommandLine\{Command, Console, HelpRenderer, ParseException as CommandLineException, Parser, Result};
use Nette\Utils\FileSystem;
use function array_slice, count, extension_loaded, in_array, is_string, sprintf;


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

	private Console $console;

	/** PHP runs with Xdebug, which makes a run many times slower */
	private readonly bool $xdebug;


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
		$this->console = new Console($this->stdout, colors: $stdout === null ? null : false);
		$this->xdebug = $xdebug ?? ($stdout === null && extension_loaded('xdebug'));
	}


	/**
	 * Runs the command line and returns the exit code: 0 clean, 1 violations or syntax errors, 2 a failure.
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
				$this->console->useColors(false);
			}

			if ($args['--version']) {
				$this->write($this->formatName() . "\n");
				return 0;
			} elseif ($args['--help'] || $command === $program) {
				$this->write($this->formatName() . "\n\n");
				new HelpRenderer($this->console)->render($command);
				return $command === $program && !$args['--help'] ? 2 : 0;
			}

			return match ($command->name) {
				'check' => $this->runCheckOrFix($args, fix: false),
				'fix' => $this->runCheckOrFix($args, fix: true),
				'rules' => $this->runRules($args),
				default => throw new \LogicException("Command '{$command->name}' has no handler."),
			};

		} catch (CommandLineException $e) {
			$this->writeUsageError($e->getMessage(), $e->command);
			return 2;

		} catch (UsageException $e) {
			$this->writeUsageError($e->getMessage(), $command);
			return 2;

		} catch (ConfigurationException|RuleException|\RuntimeException $e) {
			$this->writeError("Error: {$e->getMessage()}\n");
			return 2;

		} catch (ConvergenceException $e) {
			$this->writeError("Error: {$e->getMessage()}\n" . ($e->diff === '' ? '' : "The two states differ:\n$e->diff"));
			return 2;
		}
	}


	/**
	 * The commands of the tool; the options of the program apply to every one of them.
	 */
	private static function defineCommandLine(): Command
	{
		$program = new Command('dresscode');
		$program->addOption(
			'--config',
			'configuration file; the nearest dresscode.neon or dresscode.php when omitted',
			alias: '-c',
			valueName: 'file',
		);
		$program->addOption('--preset', 'add a preset, which without a configuration file replaces per', valueName: 'name', repeatable: true);
		$program->addOption('--group', 'add a group of rules, such as cleanup or modernization, which without a configuration file replaces per', valueName: 'name', repeatable: true);
		$program->addOption('--rule', 'enable or disable a rule: name=on or name=off', valueName: 'spec', repeatable: true);
		$program->addFlag('--no-color', 'plain output');
		$program->addFlag('--help', 'print this help', standalone: true);
		$program->addFlag('--version', hidden: true); // the name and the version are in the header of every run anyway

		$check = $program->addCommand('check', 'report violations');
		$fix = $program->addCommand('fix', 'fix what the rules can and report the rest');
		$rules = $program->addCommand('rules', 'list the known rules');
		$program->addText('Exit codes: 0 clean, 1 violations or syntax errors, 2 failure.');

		foreach ([$check, $fix] as $command) {
			$command->addArgument('paths', 'files or directories; the configured paths when omitted', optional: true, repeatable: true);
		}

		foreach ([$check, $fix] as $command) {
			$command->addOption(
				'--format',
				'github when running there; bare says only what is left to the user and which files were rewritten, so a clean run says nothing at all',
				alias: '-f',
				enum: ['console', 'bare', 'github', 'json', 'checkstyle'],
			);
			$command->addFlag('--diff', 'show the fixes as a unified diff (console format)');
			$command->addOption(
				'--stdin',
				'read the code from stdin as if it were the file at the path; fix writes the result to stdout',
				valueName: 'path',
			);
			$command->addFlag('--skip-excluded', 'skip a named file that excludePaths of the configuration leaves out, which a hook or an editor naming every file it touches wants');
			$command->addOption(
				'--max-warnings',
				'exit with 1 when more than n warnings are left; without it any number of them keeps the run clean',
				valueName: 'n',
			);
			$command->addFlag('--fix-risky', 'also make the fixes that may change what the code does, not only those of the rules the configuration names in fixRisky; they are reported either way');
			$command->addFlag('--strict-rules', 'a rule breaking its contract is an error, not a warning');
		}

		foreach ([$check, $fix, $rules] as $command) {
			$command->addOption(
				'--only',
				'run only these of the rules the configuration comes to, a preset standing for all of its rules',
				valueName: 'name',
				repeatable: true,
			);
		}

		return $program;
	}


	private function runCheckOrFix(Result $args, bool $fix): int
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
			$this->writeError($this->console->color('yellow', "Warning: $warning") . "\n");
		}

		$stdinPath = $args['--stdin'];
		$paths = $args['paths'];

		if (is_string($stdinPath)) {
			if ($paths) {
				throw new UsageException('Paths cannot be combined with --stdin.');
			}

			// the caller of stdin is an editor or a hook waiting for the format it asked for
			$format = self::resolveFormat($args, detect: false);
			$reporter = $this->createReporter($args, $fix ? $this->stderr : $this->stdout, $root, $format);
			$code = (string) stream_get_contents($this->stdin);
			$reporter->start(1, $fix);
			$result = $runner->processFile($stdinPath, $code);
			$reporter->reportFile($result);
			$run = new RunResult([$result], $fix);
			$reporter->finish($run);
			if ($fix) {
				$this->write($result->output ?? $code);
			}

			return $run->getExitCode();
		}

		$paths = $paths ?: $config->paths;
		if (!$paths) {
			throw new UsageException('No paths given and none configured.');
		}

		$format = self::resolveFormat($args, detect: true);
		$files = $runner->findFiles($paths, skipExcluded: (bool) $args['--skip-excluded']);
		// the machine-readable formats must not be prefaced
		if (in_array($format, ['console', 'github'], true)) {
			if ($this->xdebug) {
				$this->writeError($this->console->color('red', 'Warning: Xdebug is loaded and makes the run many times slower.') . "\n");
			}

			$this->writeHeader($configFile, $config, $commandLine, self::describePhpVersion($factory));
			$this->writeScope(files: $files, paths: $paths, root: $root, fix: $fix);
		}

		$reporter = $this->createReporter($args, $this->stdout, $root, $format);
		$progress = $format === 'console' && count($files) > 1 && $this->console->isTerminal()
			? new ProgressBar($this->stdout, $this->console, count($files))
			: null;
		$onProgress = $progress === null ? null : $progress->advance(...);

		$maxWarnings = $args['--max-warnings'] === null ? null : max(0, (int) $args['--max-warnings']);
		try {
			return $runner->run($files, $fix, $reporter, $onProgress, $maxWarnings)->getExitCode();
		} finally {
			$progress?->finish(); // an error must not be written into the bar
		}
	}


	/** Where the rules come from, which is nothing the command line shows. */
	private function writeHeader(?string $configFile, Config $config, ?Profile $commandLine, string $phpVersion): void
	{
		$this->write($this->formatName() . "\n");
		$presets = array_map(
			fn(string $preset) => is_subclass_of($preset, Preset::class) ? PresetInfo::of($preset)->name : $preset,
			[...$config->presets, ...$commandLine->presets ?? []],
		);
		$this->write($this->console->color('gray', 'Config     ') . ($configFile === null
			? 'none, preset ' . (implode(', ', $presets) ?: 'none')
			: FileSystem::platformSlashes($configFile)) . "\n");
		$this->write($this->console->color('gray', 'Target     ') . "PHP $phpVersion\n");
	}


	/**
	 * What the rules are applied to: the scope reaches wherever the configuration was found,
	 * not where the run was started.
	 * @param  list<string>  $files  relative to the root, or absolute when outside it
	 * @param  list<string>  $paths  they were found under these
	 */
	private function writeScope(array $files, array $paths, string $root, bool $fix): void
	{
		$absolute = array_map(fn(string $file) => FileSystem::isAbsolute($file) ? $file : "$root/$file", $files);
		$scope = count($files) === 1
			? FileSystem::platformSlashes($absolute[0])
			: sprintf('%d files in %s', count($files), FileSystem::platformSlashes(self::findCommonDirectory($absolute) ?: $root));
		$this->write($files
			? $this->console->color('gray', $fix ? 'Fixing     ' : 'Checking   ') . "$scope\n\n"
			: $this->console->color('yellow', sprintf(
				($fix ? 'Nothing to fix' : 'Nothing to check') . ': no file in %s',
				implode(', ', array_map(FileSystem::platformSlashes(...), $paths)),
			)) . "\n");
	}


	/** The name of the tool as it is written everywhere it appears. */
	private function formatName(): string
	{
		return $this->console->color('white', 'DRESS') . $this->console->color('red', '|')
			. $this->console->color('white', 'CODE') . ' ' . $this->console->color('gray', self::Version);
	}


	/** The version the rules target, said with where it was taken from when the user did not choose it. */
	private static function describePhpVersion(RunnerFactory $factory): string
	{
		[$version, $source] = $factory->getPhpVersion();
		return $version . match ($source) {
			PhpVersionSource::Configuration => '',
			PhpVersionSource::Composer => ' from composer.json',
			PhpVersionSource::Default => " by default, composer.json names no PHP version in 'require'; set 'php' in the configuration",
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


	private function runRules(Result $args): int
	{
		$factory = new RunnerFactory;
		[$config, $root, , $commandLine] = $this->loadConfig($args);
		$runner = $factory->createRunner($config, $root, $commandLine, self::parseOnly($args));
		$enabled = [];
		foreach ($runner->getProcessor()->getRules() as $rule) {
			$enabled[RuleInfo::of($rule)->name] = true;
		}

		$registry = $factory->getRegistry();
		$rules = $registry->getRules();
		ksort($rules, SORT_STRING);
		foreach ($rules as $name => $class) {
			$info = RuleInfo::of($class);
			$this->write(sprintf(
				"%s %-45s %-10s %s\n",
				isset($enabled[$name]) ? '*' : ' ',
				$this->console->color(isset($enabled[$name]) ? 'white' : null, $name),
				$info->stage->name,
				$info->description,
			));
		}

		$this->write("\n* enabled by the configuration\n");
		return 0;
	}


	/**
	 * The configuration, the root directory, the file it came from, and the profile the command line lays over them.
	 * @return array{Config, string, ?string, ?Profile}
	 * @throws UsageException
	 */
	private function loadConfig(Result $args): array
	{
		/** @var list<string> $presets */
		$presets = $args['--preset'];
		/** @var list<string> $groups */
		$groups = $args['--group'];
		[$config, $root, $file] = (new Loader)->load(
			$args['--config'],
			$this->cwd ?? (string) getcwd(),
			// a preset or a group named without a configuration file stands in for per, so that a run can ask for one alone
			$this->defaultConfig ?? ($presets || $groups ? new Config : null),
		);
		$rules = [];
		foreach ($args['--rule'] as $rule) {
			if (!preg_match('~^(.+)=(on|off)$~D', $rule, $m)) {
				throw new UsageException("Option --rule expects name=on or name=off, '$rule' given.");
			}

			$rules[$m[1]] = $m[2] === 'on';
		}

		return [
			$config,
			$root,
			$file,
			$presets || $groups || $rules ? new Profile(presets: $presets, groups: $groups, rules: $rules) : null,
		];
	}


	/**
	 * The rules and presets the run is narrowed to; null when it is not.
	 * @return ?list<string>
	 */
	private static function parseOnly(Result $args): ?array
	{
		/** @var list<string> $only */
		$only = $args['--only'];
		return $only ?: null;
	}


	/**
	 * @param resource $stream
	 * @param string $root  the paths of the results are relative to it
	 */
	private function createReporter(Result $args, $stream, string $root, string $format): Reporter
	{
		return match ($format) {
			'json' => new Reporters\JsonReporter($stream),
			'checkstyle' => new Reporters\CheckstyleReporter($stream),
			'github' => new Reporters\GithubReporter($stream, $root, self::getEnv('GITHUB_WORKSPACE')),
			default => new Reporters\ConsoleReporter(
				$stream,
				diff: (bool) $args['--diff'],
				console: $this->console,
				root: $root,
				cwd: Helpers::canonicalizePath($this->cwd ?? (string) getcwd()),
				bare: $format === 'bare',
			),
		};
	}


	/**
	 * The format asked for, or the one the surroundings call for: annotations when the run is a step
	 * of a GitHub Actions workflow, where nobody reads the log.
	 * @param  bool  $detect  let the surroundings decide when the command line does not
	 */
	private static function resolveFormat(Result $args, bool $detect): string
	{
		return is_string($args['--format'])
			? $args['--format']
			: ($detect && self::getEnv('GITHUB_ACTIONS') === 'true' ? 'github' : 'console');
	}


	private static function getEnv(string $name): ?string
	{
		$value = getenv($name);
		return $value === false || $value === '' ? null : $value;
	}


	private function write(string $text): void
	{
		fwrite($this->stdout, $text);
	}


	private function writeError(string $text): void
	{
		fwrite($this->stderr, $text);
	}


	/** A mistake in how the tool was called, followed by the help of the command it was called with. */
	private function writeUsageError(string $message, Command $command): void
	{
		$this->writeError("Error: $message\n\n");
		new HelpRenderer(new Console($this->stderr))->render($command);
	}
}
