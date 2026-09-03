<?php declare(strict_types=1);

namespace DressCode\Console;

use DressCode\Config;
use DressCode\Config\ConfigLoader;
use DressCode\Config\EngineFactory;
use DressCode\Config\PhpVersionSource;
use DressCode\ConfigurationException;
use DressCode\ConvergenceException;
use DressCode\Engine\Baseline;
use DressCode\Engine\RunResult;
use DressCode\Engine\SuppressionMigration;
use DressCode\Engine\WorkerClient;
use DressCode\Engine\WorkerPool;
use DressCode\Interop\PhpCodeSniffer;
use DressCode\Interop\PhpCsFixer;
use DressCode\Interop\Translator;
use DressCode\Preset;
use DressCode\PresetInfo;
use DressCode\Reporter;
use DressCode\Reporters;
use DressCode\RuleException;
use DressCode\RuleInfo;
use Nette\CommandLine\Console;
use Nette\CommandLine\Parser;
use PhpSyntax\ParseException;
use PhpSyntax\Printer;


/**
 * The dresscode command: check, fix and rules.
 */
final class Application
{
	public const Version = '1.0-dev';

	private const Help = <<<'XX'
		Usage:
		  dresscode check [paths...] [options]   report violations
		  dresscode fix [paths...] [options]     fix what the rules can and report the rest
		  dresscode rules [options]              list the known rules
		  dresscode import <file>                translate a php-cs-fixer or phpcs configuration
		  dresscode migrate-suppressions [paths...] [options]
		                                         rewrite phpcs suppression comments to the dresscode form

		Options:
		  -c, --config <file>       configuration file; the nearest dresscode.neon
		                            or dresscode.php when omitted
		  -f, --format <name>       console, bare, github, json or checkstyle; github when running
		                            there. bare says only what is left to the user and which files
		                            were rewritten, so a clean run says nothing at all
		  --diff                    show the fixes as a unified diff (console format)
		  --preset <name>...        add a preset
		  --rule <spec>...          enable or disable a rule: name=on or name=off
		  --stdin <path>            read the code from stdin as if it were the file at the path;
		                            fix writes the result to stdout
		  --generate-baseline       write the violations found into the configured baseline file
		                            instead of reporting them (check only)
		  --no-cache                process every file, even one whose content is known to be clean
		  --jobs <n>                worker processes; by default the number of processors, at most one per four files; 1 runs in-process
		  --strict-rules            a rule breaking its contract is an error, not a warning
		  --no-color                plain output
		  --help                    print this help

		Exit codes: 0 clean, 1 violations or syntax errors, 2 failure.

		XX;

	/** @var resource */
	private $stdout;

	/** @var resource */
	private $stderr;

	/** @var resource */
	private $stdin;

	private Console $console;

	/** the script the workers are started with */
	private string $scriptFile = 'dresscode';


	/**
	 * @param ?resource $stdout
	 * @param ?resource $stderr
	 * @param ?resource $stdin
	 * @param ?Config $defaultConfig  what applies when the project has no configuration file
	 */
	public function __construct(
		$stdout = null,
		$stderr = null,
		$stdin = null,
		private readonly ?string $cwd = null,
		private readonly ?string $script = null,
		private readonly ?Config $defaultConfig = null,
	) {
		$this->stdout = $stdout ?? STDOUT;
		$this->stderr = $stderr ?? STDERR;
		$this->stdin = $stdin ?? STDIN;
		$this->console = new Console;
		$this->console->useColors($stdout === null && Console::detectColors());
	}


	/**
	 * Runs the command line and returns the exit code: 0 clean, 1 violations or syntax errors, 2 a failure.
	 * @param list<string> $argv  including the script name
	 */
	public function run(array $argv): int
	{
		try {
			$this->scriptFile = $this->script ?? $argv[0] ?? 'dresscode';
			$args = $this->parseArguments(array_slice($argv, 1));
			if ($args['--no-color']) {
				$this->console->useColors(false);
			}

			if ($args['--version']) {
				$this->write($this->formatName() . "\n");
				return 0;
			} elseif ($args['--help'] || $args['command'] === null) {
				$this->write($this->formatName() . "\n\n" . self::Help);
				return $args['command'] === null && !$args['--help'] ? 2 : 0;
			}

			return match ($args['command']) {
				'check' => $this->runCheckOrFix($args, fix: false),
				'fix' => $this->runCheckOrFix($args, fix: true),
				'rules' => $this->runRules($args),
				'import' => $this->runImport($args),
				'migrate-suppressions' => $this->runMigrateSuppressions($args),
				default => throw new UsageException("Unknown command '{$args['command']}'."),
			};

		} catch (UsageException $e) {
			$this->writeError("Error: {$e->getMessage()}\n\n" . self::Help);
			return 2;

		} catch (ConfigurationException | RuleException | \RuntimeException $e) {
			$this->writeError("Error: {$e->getMessage()}\n");
			return 2;

		} catch (ConvergenceException $e) {
			$this->writeError("Error: {$e->getMessage()}\n" . ($e->diff === '' ? '' : "The two states differ:\n$e->diff"));
			return 2;
		}
	}


	/**
	 * @param  list<string>  $args
	 * @return array<string, mixed>
	 * @throws UsageException
	 */
	private function parseArguments(array $args): array
	{
		$parser = (new Parser(self::Help, ['--format' => [Parser::Enum => ['console', 'bare', 'github', 'json', 'checkstyle']]]))
			->addArgument('command', optional: true)
			->addArgument('paths', optional: true, repeatable: true)
			->addSwitch('--version') // the name and the version are in the header of every run anyway
			->addOption('--worker'); // the address of the parent; a worker started by WorkerPool
		try {
			return $parser->parse($args);
		} catch (\Throwable $e) {
			throw new UsageException($e->getMessage(), previous: $e);
		}
	}


	/** @param array<string, mixed> $args */
	private function runCheckOrFix(array $args, bool $fix): int
	{
		$factory = new EngineFactory;
		[$config, $root, $configFile] = $this->loadConfig($args);
		if (is_string($args['--worker'])) { // the parent keeps the cache and the baseline
			$engine = $factory->createEngine($config->baseline(null), $root, strict: (bool) $args['--strict-rules'], cache: false);
			return WorkerClient::serve($args['--worker'], $engine, $fix);
		}

		$engine = $factory->createEngine($config, $root, strict: (bool) $args['--strict-rules'], cache: !$args['--no-cache']);
		foreach ($factory->getWarnings() as $warning) { // a worker says nothing, the parent already did
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
			$result = $engine->processFile($stdinPath, $code);
			$reporter->reportFile($result);
			$run = new RunResult([$result], $fix);
			$reporter->finish($run);
			if ($fix) {
				$this->write($result->output ?? $code);
			}

			return $run->getExitCode();
		}

		$paths = $paths ?: $config->getPaths();
		if (!$paths) {
			throw new UsageException('No paths given and none configured.');
		}

		$format = self::resolveFormat($args, detect: true);
		if ($args['--generate-baseline']) {
			return $this->generateBaseline($factory, $config, $root, $paths, $fix, $format);
		}

		$files = $engine->findFiles($paths);
		// the machine-readable formats must not be prefaced
		if (in_array($format, ['console', 'github'], true)) {
			$php = self::describePhpVersion($factory);
			$this->writeHeader($configFile, $config, $php, $files, $paths, $root, $fix);
		}

		// a worker costs about the processing of a few files to start, so by default one for every four files at most
		$jobs = $args['--jobs'] === null
			? max(1, min(WorkerPool::detectCpuCount(), intdiv(count($files), 4)))
			: max(1, (int) $args['--jobs']);
		$workers = $jobs > 1 && $files ? new WorkerPool($this->buildWorkerCommand($args, $fix), $jobs, $this->cwd) : null;
		$reporter = $this->createReporter($args, $this->stdout, $root, $format);
		$progress = $format === 'console' && count($files) > 1 && Console::detectTerminal()
			? new ProgressBar($this->stdout, $this->console, count($files))
			: null;
		$onProgress = $progress === null
			? null
			: fn(int $done, array $running) => $progress->advance($done, $running);

		try {
			return $engine->run($files, $fix, $reporter, $workers, $onProgress)->getExitCode();
		} finally {
			$progress?->finish(); // an error must not be written into the bar
		}
	}


	/**
	 * Where the rules come from and what they are applied to: neither is visible on the command line,
	 * and the scope reaches wherever the configuration was found, not where the run was started.
	 * @param  list<string>  $files  relative to the root
	 * @param  list<string>  $paths  they were found under these
	 */
	private function writeHeader(
		?string $configFile,
		Config $config,
		string $phpVersion,
		array $files,
		array $paths,
		string $root,
		bool $fix,
	): void
	{
		$common = $files ? self::findCommonDirectory($files) : '';
		$scope = self::toNativePath($common === '' ? $root : "$root/$common");
		$this->write($this->formatName() . "\n");
		$presets = array_map(
			fn(string $preset) => is_subclass_of($preset, Preset::class) ? PresetInfo::of($preset)->name : $preset,
			$config->getPresets(),
		);
		$this->write($this->console->color('gray', 'Config     ') . ($configFile === null
			? 'none, preset ' . (implode(', ', $presets) ?: 'none')
			: self::toNativePath($configFile)) . "\n");
		$this->write($this->console->color('gray', 'Target     ') . "PHP $phpVersion\n");

		$this->write($files
			? $this->console->color('gray', $fix ? 'Fixing     ' : 'Checking   ')
				. sprintf("%d file%s in %s\n\n", count($files), count($files) === 1 ? '' : 's', $scope)
			: $this->console->color('yellow', sprintf(
				'Nothing to check: %s holds no file to check',
				implode(', ', array_map(self::toNativePath(...), $paths)),
			)) . "\n");
	}


	/** The name of the tool as it is written everywhere it appears. */
	private function formatName(): string
	{
		return $this->console->color('white', 'DRESS') . $this->console->color('red', '|')
			. $this->console->color('white', 'CODE') . ' ' . $this->console->color('gray', self::Version);
	}


	/** The version the rules target, said with where it was taken from when the user did not choose it. */
	private static function describePhpVersion(EngineFactory $factory): string
	{
		[$version, $source] = $factory->getPhpVersion();
		return $version . match ($source) {
			PhpVersionSource::Configuration => '',
			PhpVersionSource::Composer => ' from composer.json',
			PhpVersionSource::Default => ' by default, no composer.json found; set phpVersion in the configuration',
		};
	}


	/**
	 * The directory all the files share, relative to the root; that is the scope the run really has.
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


	private static function toNativePath(string $path): string
	{
		return str_replace('/', DIRECTORY_SEPARATOR, $path);
	}


	/**
	 * The command line of a worker: the same PHP with the same ini file (the binary alone would load the default
	 * one), the same command and configuration; the paths come over the connection.
	 * @param  array<string, mixed>  $args
	 * @return list<string>
	 */
	private function buildWorkerCommand(array $args, bool $fix): array
	{
		$ini = php_ini_loaded_file();
		$command = [
			PHP_BINARY,
			...($ini === false ? (php_ini_scanned_files() === false ? ['-n'] : []) : ['-c', $ini]),
			$this->scriptFile,
			$fix ? 'fix' : 'check',
			'--no-color',
		];
		foreach (['--config', '--preset', '--rule'] as $option) {
			foreach ((array) $args[$option] as $value) {
				if (is_string($value)) {
					$command[] = $option;
					$command[] = $value;
				}
			}
		}

		if ($args['--strict-rules']) {
			$command[] = '--strict-rules';
		}

		return $command;
	}


	/**
	 * Runs the check without the current baseline and writes what it found into the configured baseline file.
	 * @param  list<string>  $paths
	 */
	private function generateBaseline(
		EngineFactory $factory,
		Config $config,
		string $root,
		array $paths,
		bool $fix,
		string $format,
	): int
	{
		$name = $config->getBaseline();
		$file = EngineFactory::resolveBaselineFile($config, $root);
		if ($fix) {
			throw new UsageException('The baseline is generated by check, not by fix.');
		} elseif ($name === null || $file === null) {
			throw new UsageException('Configure the baseline file with Config::baseline() first.');
		}

		$engine = $factory->createEngine($config->baseline(null), $root);
		$run = $engine->run($engine->findFiles($paths), fix: false, reporter: new Reporters\NullReporter);
		$baseline = Baseline::fromResults($run->files);
		$baseline->save($file);
		$message = sprintf("Baseline with %d violation%s written to %s.\n", $baseline->count(), $baseline->count() === 1 ? '' : 's', $name);
		// every other format keeps its stream to itself
		$format === 'console' ? $this->write($message) : $this->writeError($message);
		return $run->countFailures() || $run->countErrors() ? 2 : 0;
	}


	/**
	 * Rewrites the phpcs suppression comments of the files to the dresscode form and writes them back.
	 * @param array<string, mixed> $args
	 */
	private function runMigrateSuppressions(array $args): int
	{
		$factory = new EngineFactory;
		[$config, $root] = $this->loadConfig($args);
		$engine = $factory->createEngine($config, $root);
		$paths = $args['paths'] ?: $config->getPaths();
		if (!$paths) {
			throw new UsageException('No paths given and none configured.');
		}

		$migration = new SuppressionMigration($factory->getRegistry()->resolveNames(...));
		$parser = new \PhpSyntax\Parser\Parser;
		$files = 0;
		foreach ($engine->findFiles($paths) as $path) {
			$absolute = $engine->toAbsolute($path);
			$code = @file_get_contents($absolute); // @ - reported as exception
			if ($code === false) {
				throw new \RuntimeException("Cannot read file $path.");
			}

			try {
				$file = $parser->parse($code);
			} catch (ParseException $e) {
				$this->write("$path: skipped, {$e->getMessage()}\n");
				continue;
			}

			if ($migration->migrate($file)) {
				if (@file_put_contents($absolute, Printer::print($file)) === false) { // @ - reported as exception
					throw new \RuntimeException("Cannot write file $path.");
				}

				$files++;
			}
		}

		$this->write(sprintf("Migrated %d suppression comment%s in %d file%s.\n", $migration->count, $migration->count === 1 ? '' : 's', $files, $files === 1 ? '' : 's'));
		if ($migration->unknownNames) {
			$this->write('Warning: unknown rule names kept as they are: ' . implode(', ', array_keys($migration->unknownNames)) . "\n");
		}

		if ($migration->ownLineIgnore) {
			$this->write("Note: dresscode:ignore on a line of its own covers the whole statement below it, not just the next line; review the migrated ones.\n");
		}

		return 0;
	}


	/** @param array<string, mixed> $args */
	private function runRules(array $args): int
	{
		$factory = new EngineFactory;
		[$config, $root] = $this->loadConfig($args);
		$engine = $factory->createEngine($config, $root);
		$enabled = [];
		foreach ($engine->getProcessor()->getRules() as $rule) {
			$enabled[RuleInfo::of($rule)->name] = true;
		}

		$registry = $factory->getRegistry();
		$rules = $registry->getRules();
		ksort($rules, SORT_STRING);
		foreach ($rules as $name => $class) {
			$info = RuleInfo::of($class);
			$covers = $registry->getTranslator()->findForeignNames($name);
			$this->write(sprintf(
				"%s %-45s %-10s %s%s\n",
				isset($enabled[$name]) ? '*' : ' ',
				$this->console->color(isset($enabled[$name]) ? 'white' : null, $name),
				$info->stage->name,
				$info->description,
				$covers ? $this->console->color('gray', '  (covers ' . implode(', ', $covers) . ')') : '',
			));
		}

		$this->write("\n* enabled by the configuration\n");
		return 0;
	}


	/**
	 * @param  array<string, mixed>  $args
	 * @throws UsageException
	 */
	private function runImport(array $args): int
	{
		$file = $args['paths'][0] ?? throw new UsageException('No configuration file given.');
		$rules = preg_match('~\.xml(\.dist)?$~Di', $file)
			? PhpCodeSniffer::readConfig($file)
			: PhpCsFixer::readConfig($file);
		$translation = (new Translator)->translate($rules);
		$this->write($translation->toConfig());
		$this->writeError(sprintf(
			"\nRead %d rule%s, enabled %d and %d preset%s.\n",
			count($rules),
			count($rules) === 1 ? '' : 's',
			count($translation->rules),
			count($translation->presets),
			count($translation->presets) === 1 ? '' : 's',
		));
		foreach ($translation->warnings as $warning) {
			$this->writeError("  $warning\n");
		}

		if (!$translation->presets) {
			$this->writeError("  The indentation and the line ending are not read from there; set them with style().\n");
		}

		return 0;
	}


	/**
	 * The configuration with the command line layered over it, the root directory and the file it came from.
	 * @param  array<string, mixed>  $args
	 * @return array{Config, string, ?string}
	 */
	private function loadConfig(array $args): array
	{
		$presets = $args['--preset'];
		[$config, $root, $file] = (new ConfigLoader)->load(
			$args['--config'],
			$this->cwd ?? (string) getcwd(),
			$this->defaultConfig ?? ($presets ? Config::create() : null),
		);
		foreach ($presets as $preset) {
			$config->preset($preset);
		}

		foreach ($args['--rule'] as $rule) {
			if (!preg_match('~^(.+)=(on|off)$~D', $rule, $m)) {
				throw new UsageException("Option --rule expects name=on or name=off, '$rule' given.");
			}

			$m[2] === 'on' ? $config->enable($m[1]) : $config->disable($m[1]);
		}

		return [$config, $root, $file];
	}


	/**
	 * @param array<string, mixed> $args
	 * @param resource $stream
	 * @param string $root  the paths of the results are relative to it
	 */
	private function createReporter(array $args, $stream, string $root, string $format): Reporter
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
				cwd: rtrim(str_replace('\\', '/', $this->cwd ?? (string) getcwd()), '/'),
				bare: $format === 'bare',
			),
		};
	}


	/**
	 * The format asked for, or the one the surroundings call for: annotations when the run is a step
	 * of a GitHub Actions workflow, where nobody reads the log.
	 * @param array<string, mixed> $args
	 * @param bool $detect  let the surroundings decide when the command line does not
	 */
	private static function resolveFormat(array $args, bool $detect): string
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


	public static function getHelp(): string
	{
		return self::Help;
	}


	private function write(string $text): void
	{
		fwrite($this->stdout, $text);
	}


	private function writeError(string $text): void
	{
		fwrite($this->stderr, $text);
	}
}
