<?php declare(strict_types=1);

namespace DressCode\Console;

use DressCode\Config;
use DressCode\Config\Loader;
use DressCode\Config\PhpVersionSource;
use DressCode\Config\RunnerFactory;
use DressCode\ConfigurationException;
use DressCode\ConvergenceException;
use DressCode\Engine\Baseline;
use DressCode\Helpers;
use DressCode\Preset;
use DressCode\PresetInfo;
use DressCode\Profile;
use DressCode\Reporter;
use DressCode\Reporters;
use DressCode\RuleException;
use DressCode\RuleInfo;
use DressCode\RunResult;
use Nette\CommandLine\Ansi;
use Nette\CommandLine\Command;
use Nette\CommandLine\Console;
use Nette\CommandLine\HelpRenderer;
use Nette\CommandLine\ParseException as CommandLineException;
use Nette\CommandLine\Parser;
use Nette\CommandLine\Result;
use Nette\Utils\FileSystem;
use function array_slice, count, extension_loaded, in_array, is_string, sprintf;


/**
 * The dresscode command: check, fix and rules.
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


	/**
	 * @param ?resource $stdout
	 * @param ?resource $stderr
	 * @param ?resource $stdin
	 * @param ?bool $xdebug  whether Xdebug is loaded; detected when the output is the process's own
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
		$this->out = new Console($this->stdout, colors: $stdout === null ? null : false);
		$this->err = new Console($this->stderr, colors: $stderr === null ? null : false);
		$this->xdebug = $xdebug ?? ($stdout === null && extension_loaded('xdebug'));
	}


	/**
	 * Runs the command line and returns the exit code: 0 clean, 1 violations or syntax errors, 2 a failure.
	 * @param list<string> $argv  including the script name
	 */
	public function run(array $argv): int
	{
		$program = self::defineCommandLine();
		$command = $program;
		try {
			$args = (new Parser)->parse($program, array_slice($argv, 1));
			$command = $args->command;
			if ($args['--no-color']) {
				$this->out->useColors(false);
				$this->err->useColors(false);
			}

			if ($args['--version']) {
				$this->out->writeLine($this->formatName($this->out));
				return 0;
			} elseif ($args['--help'] || $command === $program) {
				$this->out->writeLine($this->formatName($this->out) . "\n");
				new HelpRenderer($this->out)->render($command);
				return $command === $program && !$args['--help'] ? 2 : 0;
			}

			return match ($command->name) {
				'check' => $this->runCheckOrFix($args, fix: false),
				'fix' => $this->runCheckOrFix($args, fix: true),
				'config' => $this->runConfig($args),
				'explain' => $this->runExplain($args),
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
		$program->addOption('--preset', 'add a preset', valueName: 'name', repeatable: true);
		$program->addOption('--group', 'add a group of rules, such as cleanup or modernization', valueName: 'name', repeatable: true);
		$program->addOption('--rule', 'enable or disable a rule: name=on or name=off', valueName: 'spec', repeatable: true);
		$program->addFlag('--no-color', 'plain output');
		$program->addFlag('--help', 'print this help', standalone: true);
		$program->addFlag('--version', hidden: true); // the name and the version are in the header of every run anyway

		$check = $program->addCommand('check', 'report violations');
		$fix = $program->addCommand('fix', 'fix what the rules can and report the rest');
		$config = $program->addCommand('config', 'print the configuration as the run resolves it');
		$explain = $program->addCommand('explain', 'what a rule is for, its options here and its examples; every rule that runs when none is named');
		$rules = $program->addCommand('rules', 'list the known rules');
		$program->addText('Exit codes: 0 clean, 1 violations or syntax errors, 2 failure.');

		foreach ([$check, $fix] as $command) {
			$command->addArgument('paths', 'files or directories; the configured paths when omitted', optional: true, repeatable: true);
		}

		$explain->addArgument('rule', 'name of the rule; every rule that runs when omitted', optional: true);

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
			$command->addFlag('--no-cache', 'process every file, even one whose content is known to be clean');
			$command->addFlag('--strict-rules', 'a rule breaking its contract is an error, not a warning');
		}

		$check->addFlag('--generate-baseline', 'write the violations found into the configured baseline file instead of reporting them, once a fix changes nothing');

		foreach ([$check, $fix, $config, $explain, $rules] as $command) {
			$command->addOption(
				'--only',
				'run only these of the rules the configuration comes to, a preset standing for all of its rules',
				valueName: 'name',
				repeatable: true,
			);
		}

		$config->addOption('--file', 'what the configuration comes to for that one file', valueName: 'path');
		$config->addFlag('--json', 'the configuration as data');
		$explain->addOption('--output', 'write the explanation there, in Markdown', valueName: 'file');
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
			cache: !$args['--no-cache'],
			configFile: $configFile,
			fixRisky: (bool) $args['--fix-risky'],
		);
		foreach ($factory->getWarnings() as $warning) {
			$this->err->writeLine("Warning: $warning", 'yellow');
		}

		$stdinPath = $args['--stdin'];
		$paths = $args['paths'];

		if (is_string($stdinPath)) {
			if ($paths) {
				throw new UsageException('Paths cannot be combined with --stdin.');
			}

			// the caller of stdin is an editor or a hook waiting for the format it asked for
			$format = self::resolveFormat($args, detect: false);
			// a fix writes the fixed code to stdout, so its report goes to stderr
			$reporter = $fix
				? $this->createReporter($args, $this->err, $this->stderr, $root, $format)
				: $this->createReporter($args, $this->out, $this->stdout, $root, $format);
			$code = (string) stream_get_contents($this->stdin);
			$reporter->start(1, $fix);
			$result = $runner->processFile($stdinPath, $code);
			$reporter->reportFile($result);
			$run = new RunResult([$result], $fix);
			$reporter->finish($run);
			if ($fix) {
				$this->out->write($result->output ?? $code);
			}

			return $run->getExitCode();
		}

		$paths = $paths ?: $config->paths;
		if (!$paths) {
			throw new UsageException('No paths given and none configured.');
		}

		$format = self::resolveFormat($args, detect: true);
		$generate = isset($args['--generate-baseline']);
		if ($generate && $only) { // it would forget what every other rule found
			throw new UsageException('The baseline is generated by the whole configuration, not with --only.');
		} elseif ($generate && $args['--fix-risky']) { // it holds the risky fixes the project does not allow
			throw new UsageException('The baseline is generated with the risky fixes the configuration accepts, not with --fix-risky.');
		}

		$files = $runner->findFiles($paths, skipExcluded: (bool) $args['--skip-excluded']);
		// the machine-readable formats must not be prefaced, and a generated baseline is not a report
		if (!$generate && in_array($format, ['console', 'github'], true)) {
			if ($this->xdebug) {
				$this->err->writeLine('Warning: Xdebug is loaded and makes the run many times slower.', 'red');
			}

			$this->writeHeader($configFile, $config, $commandLine, self::describePhpVersion($factory));
			$this->writeScope(files: $files, paths: $paths, root: $root, fix: $fix);
		}

		if ($generate) {
			return $this->generateBaseline($factory, $config, $root, $configFile, $commandLine, $files, $format);
		}

		$progress = $format === 'console' && count($files) > 1 && $this->out->isTerminal()
			? new ProgressBar($this->out, count($files))
			: null;
		$onProgress = $progress === null ? null : $progress->advance(...);
		$reporter = $this->createReporter($args, $this->out, $this->stdout, $root, $format);

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
		$this->out->writeLine($this->formatName($this->out));
		$presets = array_map(
			fn(string $preset) => is_subclass_of($preset, Preset::class) ? PresetInfo::of($preset)->name : $preset,
			[...$config->presets, ...$commandLine->presets ?? []],
		);
		$this->out->writeLine($this->out->color('gray', 'Config     ') . ($configFile === null
			? 'none, preset ' . (implode(', ', $presets) ?: 'none')
			: FileSystem::platformSlashes($configFile)));
		$this->out->writeLine($this->out->color('gray', 'Target     ') . "PHP $phpVersion");
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
		$this->out->write($files
			? $this->out->color('gray', $fix ? 'Fixing     ' : 'Checking   ') . "$scope\n\n"
			: $this->out->color('yellow', sprintf(
				'Nothing to check: %s holds no file to check',
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
			PhpVersionSource::Composer => ' from composer.json',
			PhpVersionSource::Default => ' by default, no composer.json with a readable require.php found; set php in the configuration',
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
	 * Runs the check without the current baseline over code a fix leaves alone and writes what remains into the
	 * configured baseline file, or into the default one beside the configuration, which the user then has to name
	 * to make it apply; code a fix would still change, or a file that fails or does not parse, is refused.
	 * @param  list<string>  $files
	 */
	private function generateBaseline(
		RunnerFactory $factory,
		Config $config,
		string $root,
		?string $configFile,
		?Profile $commandLine,
		array $files,
		string $format,
	): int
	{
		$name = $config->baseline ?? self::defaultBaselineName($configFile);
		$file = RunnerFactory::toAbsolutePath($name, $root);
		$runner = $factory->createRunner($config, $root, $commandLine, cache: false, baseline: false);
		$run = $runner->run($files, fix: false, reporter: new Reporters\NullReporter);
		$changed = $failed = [];
		foreach ($run->files as $result) {
			if ($result->failure !== null || $result->error !== null) {
				$failed[] = $result->path;
			} elseif ($result->isChanged()) {
				$changed[] = $result->path;
			}
		}

		if ($changed || $failed) {
			$counts = array_filter([
				$changed ? sprintf('a fix would change %d file%s', count($changed), count($changed) === 1 ? '' : 's') : null,
				$failed ? count($failed) . ' failed' : null,
			]);
			$paths = [...$changed, ...$failed];
			$this->writeError('Error: The baseline is generated over code a fix leaves alone: ' . implode(', ', $counts) . ". Run fix first.\n"
				. implode('', array_map(fn(string $path) => "  $path\n", array_slice($paths, 0, 10)))
				. (count($paths) > 10 ? '  and ' . (count($paths) - 10) . " more\n" : ''));
			return 2;
		}

		$baseline = Baseline::fromResults($run->files);
		$baseline->save($file);
		$message = sprintf("Baseline with %d violation%s written to %s.\n", $baseline->count(), $baseline->count() === 1 ? '' : 's', $name);
		if ($config->baseline === null) {
			$message .= "Name it in the configuration to make it apply.\n";
		}

		// every other format keeps its stream to itself
		$format === 'console' ? $this->write($message) : $this->writeError($message);
		return 0;
	}


	/** The baseline is written in the format the configuration is written in, so there is no third format. */
	private static function defaultBaselineName(?string $configFile): string
	{
		$extension = pathinfo((string) preg_replace('~\.dist$~', '', $configFile ?? ''), PATHINFO_EXTENSION);
		return 'dresscode-baseline.' . (strtolower($extension) === 'php' ? 'php' : 'neon');
	}


	/**
	 * Prints the configuration as the run resolves it: which rule runs with which options, which layer gave
	 * every value and what it overrode, and why a rule does not run.
	 */
	private function runConfig(Result $args): int
	{
		$factory = new RunnerFactory;
		[$config, $root, $configFile, $commandLine] = $this->loadConfig($args);
		$runner = $factory->createRunner($config, $root, $commandLine, self::parseOnly($args), cache: false);
		$file = $args['--file'];
		$resolved = is_string($file)
			? $factory->resolveConfigFor($runner->findOverridesFor($file))
			: $factory->getResolvedConfig();
		$printer = new ConfigPrinter($resolved);
		if ($args['--json']) {
			$this->write($printer->printJson());
			return 0;
		}

		$this->writeHeader($configFile, $config, $commandLine, self::describePhpVersion($factory));
		if (is_string($file)) {
			$this->out->writeLine($this->out->color('gray', 'File       ') . FileSystem::platformSlashes($file));
		}

		$this->out->write($printer->print($this->out));
		return 0;
	}


	/**
	 * Explains a rule, or every rule that runs when none is named: what it is for, the options it has under
	 * this configuration, and the examples someone chose for it; with --output as Markdown into that file.
	 * @throws UsageException
	 */
	private function runExplain(Result $args): int
	{
		$name = $args['rule'];
		$factory = new RunnerFactory;
		[$config, $root, $configFile, $commandLine] = $this->loadConfig($args);
		$factory->createRunner($config, $root, $commandLine, self::parseOnly($args), cache: false);
		$resolved = $factory->getResolvedConfig();
		$fixtures = __DIR__ . '/../../tests/DressCode/Rules/fixtures';
		$rules = $resolved->getActiveRules();
		if (is_string($name)) {
			$rule = $resolved->getRule(RuleInfo::of($factory->getRegistry()->resolveRule($name))->name);
			if ($rule === null) {
				throw new UsageException("Unknown rule '$name'.");
			}

			$rules = [$rule];
		}

		$file = $args['--output'];
		if (is_string($file)) {
			$printer = new ExplainMarkdownPrinter($resolved, $fixtures);
			FileSystem::write($file, is_string($name) ? $printer->printRule($rules[0]) : $printer->print());
			$this->write('Written to ' . FileSystem::platformSlashes($file) . ".\n");
			return 0;
		}

		$this->writeHeader($configFile, $config, $commandLine, self::describePhpVersion($factory));
		foreach ($rules as $rule) {
			$this->out->write("\n" . new ExplainPrinter($rule, $fixtures)->print($this->out));
		}

		return 0;
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
			$this->out->writeLine(
				(isset($enabled[$name]) ? '*' : ' ')
				. ' ' . Ansi::pad($this->out->color(isset($enabled[$name]) ? 'white' : null, $name), 45)
				. ' ' . Ansi::pad($info->stage->name, 10)
				. ' ' . $info->description,
			);
		}

		$this->out->writeLine("\n* enabled by the configuration");
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
	 * @param resource $stream  the same place as the console; a machine-readable format is no text of a console
	 * @param string $root  the paths of the results are relative to it
	 */
	private function createReporter(
		Result $args,
		Console $console,
		$stream,
		string $root,
		string $format,
	): Reporter
	{
		return match ($format) {
			'json' => new Reporters\JsonReporter($stream),
			'checkstyle' => new Reporters\CheckstyleReporter($stream),
			'github' => new Reporters\GithubReporter($stream, $root, self::getEnv('GITHUB_WORKSPACE')),
			default => new Reporters\ConsoleReporter(
				$console,
				diff: (bool) $args['--diff'],
				root: $root,
				cwd: Helpers::canonicalizePath($this->cwd ?? (string) getcwd()),
				bare: $format === 'bare',
			),
		};
	}


	/**
	 * The format asked for, or the one the surroundings call for: annotations when the run is a step
	 * of a GitHub Actions workflow, where nobody reads the log.
	 * @param bool $detect  let the surroundings decide when the command line does not
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
		$this->out->write($text);
	}


	private function writeError(string $text): void
	{
		$this->err->write($text);
	}


	/** A mistake in how the tool was called, followed by the help of the command it was called with. */
	private function writeUsageError(string $message, Command $command): void
	{
		$this->err->writeLine("Error: $message\n");
		new HelpRenderer($this->err)->render($command);
	}
}
