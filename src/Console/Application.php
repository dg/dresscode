<?php declare(strict_types=1);

namespace DressCode\Console;

use DressCode\Config;
use DressCode\Config\Loader;
use DressCode\Config\PhpVersionSource;
use DressCode\Config\Proposal;
use DressCode\Config\RuleRegistry;
use DressCode\Config\RunnerFactory;
use DressCode\ConfigurationException;
use DressCode\ConvergenceException;
use DressCode\Engine\Baseline;
use DressCode\Engine\SuppressionMigration;
use DressCode\Engine\WorkerClient;
use DressCode\Engine\WorkerPool;
use DressCode\Helpers;
use DressCode\Interop\PhpCodeSniffer;
use DressCode\Interop\PhpCsFixer;
use DressCode\Interop\Translator;
use DressCode\Preset;
use DressCode\PresetInfo;
use DressCode\Profile;
use DressCode\Reporter;
use DressCode\Reporters;
use DressCode\RuleException;
use DressCode\RuleInfo;
use DressCode\RunResult;
use Nette\CommandLine\Command;
use Nette\CommandLine\Console;
use Nette\CommandLine\HelpRenderer;
use Nette\CommandLine\ParseException as CommandLineException;
use Nette\CommandLine\Parser;
use Nette\CommandLine\Result;
use Nette\Utils\FileSystem;
use PhpSyntax\ParseException;
use PhpSyntax\Printer;
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

	private Console $console;

	/** the script the workers are started with */
	private string $scriptFile = 'dresscode';

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
		private readonly ?string $script = null,
		/** what applies when the project has no configuration file */
		private readonly ?Config $defaultConfig = null,
		?bool $xdebug = null,
	) {
		$this->stdout = $stdout ?? STDOUT;
		$this->stderr = $stderr ?? STDERR;
		$this->stdin = $stdin ?? STDIN;
		$this->console = new Console($this->stdout);
		$this->console->useColors($stdout === null && Console::detectColors());
		$this->xdebug = $xdebug ?? ($stdout === null && extension_loaded('xdebug'));
	}


	/**
	 * Runs the command line and returns the exit code: 0 clean, 1 violations or syntax errors, 2 a failure.
	 * @param list<string> $argv  including the script name
	 */
	public function run(array $argv): int
	{
		$this->scriptFile = $this->script ?? $argv[0] ?? 'dresscode';
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
				'config' => $this->runConfig($args),
				'explain' => $this->runExplain($args),
				'rules' => $this->runRules($args),
				'init' => $this->runInit($args),
				'import' => $this->runImport($args),
				'migrate-suppressions' => $this->runMigrateSuppressions($args),
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
		$program->addOption('--preset', 'add a preset; for init the standard to write, per by default', valueName: 'name', repeatable: true);
		$program->addOption('--rule', 'enable or disable a rule: name=on or name=off', valueName: 'spec', repeatable: true);
		$program->addFlag('--no-color', 'plain output');
		$program->addFlag('--help', 'print this help', standalone: true);
		$program->addFlag('--version', hidden: true); // the name and the version are in the header of every run anyway

		$check = $program->addCommand('check', 'report violations');
		$fix = $program->addCommand('fix', 'fix what the rules can and report the rest');
		$config = $program->addCommand('config', 'print the configuration as the run resolves it');
		$explain = $program->addCommand('explain', 'what a rule is for, its options here and its examples; every rule that runs when none is named');
		$rules = $program->addCommand('rules', 'list the known rules');
		$program->addCommand('init', 'write dresscode.neon from how the code of the project is written');
		$import = $program->addCommand('import', 'translate a php-cs-fixer or phpcs configuration');
		$migrate = $program->addCommand('migrate-suppressions', 'rewrite phpcs suppression comments to the dresscode form');
		$program->addText('Exit codes: 0 clean, 1 violations or syntax errors, 2 failure.');

		foreach ([$check, $fix, $migrate] as $command) {
			$command->addArgument('paths', 'files or directories; the configured paths when omitted', optional: true, repeatable: true);
		}

		$explain->addArgument('rule', 'name of the rule; every rule that runs when omitted', optional: true);
		$import->addArgument('file', 'php-cs-fixer or phpcs configuration file');

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
			$command->addOption(
				'--jobs',
				'worker processes; by default the number of processors, at most one per four files; 1 runs in-process',
				valueName: 'n',
			);
			$command->addFlag('--strict-rules', 'a rule breaking its contract is an error, not a warning');
			$command->addOption('--worker', hidden: true); // the address of the parent; a worker started by WorkerPool
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
		if (is_string($args['--worker'])) { // the parent keeps the cache; the baseline decides what is reported
			$runner = $factory->createRunner(
				$config,
				$root,
				$commandLine,
				$only,
				strict: (bool) $args['--strict-rules'],
				cache: false,
				fixRisky: (bool) $args['--fix-risky'],
				baseline: !isset($args['--generate-baseline']),
			);
			return WorkerClient::serve($args['--worker'], $runner, $fix);
		}

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
		foreach ($factory->getWarnings() as $warning) { // a worker says nothing, the parent already did
			$this->writeError($this->console->color('yellow', "Warning: $warning") . "\n");
		}

		$stdinPath = $args['--stdin'];
		$paths = array_values(array_unique(array_map($this->resolvePath(...), self::parsePaths($args))));

		if (is_string($stdinPath)) {
			if ($paths) {
				throw new UsageException('Paths cannot be combined with --stdin.');
			}

			// the caller of stdin is an editor or a hook waiting for the format it asked for
			$format = self::resolveFormat($args, detect: false);
			$reporter = $this->createReporter($args, $fix ? $this->stderr : $this->stdout, $root, $format);
			$code = (string) stream_get_contents($this->stdin);
			$reporter->start(1, $fix);
			$result = $runner->processFile($this->resolvePath($stdinPath), $code);
			$reporter->reportFile($result);
			$run = new RunResult([$result], $fix);
			$reporter->finish($run);
			if ($fix) {
				$this->write($result->output ?? $code);
			}

			return $run->getExitCode();
		}

		$scope = $paths ? $runner->narrowPaths($paths, $config->paths) : $config->paths;
		if (!$scope) {
			throw new UsageException('No paths given and none configured.');
		}

		$format = self::resolveFormat($args, detect: true);
		$generate = isset($args['--generate-baseline']);
		if ($generate && $only) { // it would forget what every other rule found
			throw new UsageException('The baseline is generated by the whole configuration, not with --only.');
		} elseif ($generate && $args['--fix-risky']) { // it holds the risky fixes the project does not allow
			throw new UsageException('The baseline is generated with the risky fixes the configuration accepts, not with --fix-risky.');
		}

		$files = $runner->findFiles($scope, skipExcluded: (bool) $args['--skip-excluded']);
		// the machine-readable formats must not be prefaced, and a generated baseline is not a report
		if (!$generate && in_array($format, ['console', 'github'], true)) {
			if ($this->xdebug) {
				$this->writeError($this->console->color('red', 'Warning: Xdebug is loaded and makes the run many times slower.') . "\n");
			}

			$this->writeHeader($configFile, $config, $commandLine, self::describePhpVersion($factory));
			$this->writeScope(files: $files, paths: $scope, root: $root, fix: $fix, narrowed: $paths && $scope !== $paths);
		}

		// a worker costs about the processing of a few files to start, so by default one for every four files at most
		$jobs = $args['--jobs'] === null
			? max(1, min(WorkerPool::detectCpuCount(), intdiv(count($files), 4)))
			: max(1, (int) $args['--jobs']);
		$workers = $jobs > 1 && $files ? new WorkerPool($this->buildWorkerCommand($args, $fix), $jobs, $this->cwd) : null;
		if ($generate) {
			return $this->generateBaseline($factory, $config, $root, $configFile, $commandLine, $files, $workers, $format);
		}

		$progress = $format === 'console' && count($files) > 1 && Console::detectTerminal()
			? new ProgressBar($this->stdout, $this->console, count($files))
			: null;
		$onProgress = $progress === null ? null : $progress->advance(...);
		$reporter = $this->createReporter($args, $this->stdout, $root, $format, $progress === null ? null : $progress->clear(...));

		$maxWarnings = $args['--max-warnings'] === null ? null : max(0, (int) $args['--max-warnings']);
		try {
			return $runner->run($files, $fix, $reporter, $workers, $onProgress, $maxWarnings)->getExitCode();
		} finally {
			$progress?->clear(); // an error must not be written into the bar
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
	 * @param  bool  $narrowed  a directory named on the command line was narrowed to the configured paths
	 */
	private function writeScope(array $files, array $paths, string $root, bool $fix, bool $narrowed): void
	{
		$absolute = array_map(fn(string $file) => FileSystem::isAbsolute($file) ? $file : "$root/$file", $files);
		$scope = count($files) === 1
			? FileSystem::platformSlashes($absolute[0])
			: sprintf('%d files in %s', count($files), FileSystem::platformSlashes(self::findCommonDirectory($absolute) ?: $root));
		$this->write($files
			? $this->console->color('gray', $fix ? 'Fixing     ' : 'Checking   ') . $scope
				. ($narrowed ? $this->console->color('gray', ', narrowed to the configured paths') : '') . "\n\n"
			: $this->console->color('yellow', sprintf(
				'Nothing to check: %s holds no file to check',
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
	 * The command line of a worker: the same PHP with the same ini file (the binary alone would load the default
	 * one), the same command and configuration; the paths come over the connection.
	 * @return list<string>
	 */
	private function buildWorkerCommand(Result $args, bool $fix): array
	{
		$ini = php_ini_loaded_file();
		$command = [
			PHP_BINARY,
			...($ini === false ? (php_ini_scanned_files() === false ? ['-n'] : []) : ['-c', $ini]),
			$this->scriptFile,
			$fix ? 'fix' : 'check',
			'--no-color',
		];
		foreach (['--config', '--preset', '--rule', '--only'] as $option) {
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

		if ($args['--fix-risky']) { // what a worker may fix has to be what the parent was asked for
			$command[] = '--fix-risky';
		}

		if (isset($args['--generate-baseline'])) { // the workers of such a run must see what the baseline knows too
			$command[] = '--generate-baseline';
		}

		return $command;
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
		?WorkerPool $workers,
		string $format,
	): int
	{
		$name = $config->baseline ?? self::defaultBaselineName($configFile);
		$file = RunnerFactory::toAbsolutePath($name, $root);
		$runner = $factory->createRunner($config, $root, $commandLine, cache: false, baseline: false);
		$run = $runner->run($files, fix: false, reporter: new Reporters\NullReporter, workers: $workers);
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
	 * Rewrites the phpcs suppression comments of the files to the dresscode form and writes them back.
	 */
	private function runMigrateSuppressions(Result $args): int
	{
		$factory = new RunnerFactory;
		[$config, $root, , $commandLine] = $this->loadConfig($args);
		$runner = $factory->createRunner($config, $root, $commandLine);
		$named = array_map($this->resolvePath(...), self::parsePaths($args));
		$paths = $named ? $runner->narrowPaths($named, $config->paths) : $config->paths;
		if (!$paths) {
			throw new UsageException('No paths given and none configured.');
		}

		$migration = new SuppressionMigration($factory->getRegistry()->resolveNames(...));
		$parser = new \PhpSyntax\Parser;
		$files = 0;
		foreach ($runner->findFiles($paths) as $path) {
			$absolute = $runner->toAbsolute($path);
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
			$this->write($this->console->color('gray', 'File       ') . FileSystem::platformSlashes($file) . "\n");
		}

		$this->write($printer->print($this->console));
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
			$this->write("\n" . new ExplainPrinter($rule, $fixtures)->print($this->console));
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
	 * Measures how the project writes what can be measured and writes dresscode.neon with it; a configuration
	 * that exists is never overwritten, the proposal is printed instead and the exit code says so.
	 */
	private function runInit(Result $args): int
	{
		$root = Helpers::canonicalizePath($this->cwd ?? (string) getcwd());
		$presets = $args['--preset'] ?: null;
		array_map((new RuleRegistry)->resolvePreset(...), $presets ?? []); // a misspelled one before the measuring, not after it
		$proposal = Proposal::measure($root, $presets);
		$neon = $proposal->toNeon();

		// what the file says must be what was measured, before it is anywhere a run could read it
		$temp = sys_get_temp_dir() . '/dresscode-init-' . uniqid() . '.neon';
		FileSystem::write($temp, $neon);
		try {
			$factory = new RunnerFactory;
			$factory->createRunner(Loader::loadFile($temp), $root, cache: false);
			$proposal->checkResolution($factory->getResolvedConfig());
		} finally {
			@unlink($temp); // @ - may be gone
		}

		$existing = array_filter(
			array_merge(...array_map(fn(string $name) => [$name, $name . Loader::DistSuffix], Loader::FileNames)),
			fn(string $name) => is_file("$root/$name"),
		);
		$report = $existing ? $this->writeError(...) : $this->write(...);
		$report($this->formatName() . "\n");
		$left = array_filter([
			$proposal->sample->oversized ? $proposal->sample->oversized . ' too large' : null,
			$proposal->sample->generated ? $proposal->sample->generated . ' generated' : null,
		]);
		$report($this->console->color('gray', 'Sample     ') . sprintf(
			"%d of %d files in %s%s\n",
			$proposal->countSampled(),
			$proposal->total,
			implode(', ', $proposal->paths),
			$left ? ', ' . implode(' and ', $left) . ' left out' : '',
		));
		$report($this->console->color('gray', 'Standard   ') . implode(', ', $proposal->presets) . ($proposal->given
			? ", as given\n"
			: ", the nearest of the four below is not measured; cheaper is not nearer, and --preset writes another\n"));
		$report($this->console->color('gray', 'Indent     ') . $proposal->indent->describe() . "\n");
		$report($this->console->color('gray', 'Quotes     ') . $proposal->quotes->describe() . "\n");
		$report($this->console->color('gray', 'Conditions ') . $proposal->conditions->describe() . "\n");
		$report($this->console->color('gray', 'Namespaces ') . $proposal->describeNamespaces() . "\n");

		if ($proposal->given) {
			[$changed, $failed] = $proposal->countChanged();
			$report($this->console->color('gray', 'Dry run    ') . sprintf(
				"%d of %d sampled files would change%s\n",
				$changed,
				$proposal->countSampled(),
				$failed ? ", $failed failing" : '',
			));
		} else {
			// what a standard costs is what its first fix would change, the only number about them that measures
			$first = true;
			foreach ($proposal->countChangedByStandard() as $standard => [$changed, $failed]) {
				$report($this->console->color('gray', $first ? 'Dry run    ' : '           ') . sprintf(
					"%-8s %4d of %d%s%s%s\n",
					$standard,
					$changed,
					$proposal->countSampled(),
					$first ? ' sampled files would change' : '',
					in_array($standard, $proposal->presets, true) ? ', the one written' : '',
					$failed ? ", $failed failing" : '',
				));
				$first = false;
			}
		}

		if ($existing) {
			$this->writeError(implode(' and ', $existing) . " exists, so the proposal is printed and nothing is written.\n");
			$this->write($neon);
			return 2;
		}

		FileSystem::write("$root/dresscode.neon", $neon);
		$this->write("\ndresscode.neon written.\n");
		return 0;
	}


	private function runImport(Result $args): int
	{
		$file = $args['file'];
		[$rules, $unread] = preg_match('~\.xml(\.dist)?$~Di', $file)
			? PhpCodeSniffer::readConfig($file)
			: [PhpCsFixer::readConfig($file), []];
		$translation = (new Translator)->translate($rules);
		foreach ($unread as $warning) {
			$translation->warn($warning);
		}

		$this->write($translation->toConfig());
		$disabled = count(array_filter($translation->rules, fn($options) => $options === false));
		$this->writeError(sprintf(
			"\nRead %d rule%s, enabled %d%s and %d preset%s.\n",
			count($rules),
			count($rules) === 1 ? '' : 's',
			count($translation->rules) - $disabled,
			$disabled ? ", turned off $disabled" : '',
			count($translation->presets),
			count($translation->presets) === 1 ? '' : 's',
		));
		foreach ($translation->warnings as $warning) {
			$this->writeError("  $warning\n");
		}

		if (!$translation->presets) {
			$this->writeError("  The indentation and the line ending are not read from there; set them with indent and eol.\n");
		}

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
		[$config, $root, $file] = (new Loader)->load(
			$args['--config'],
			$this->cwd ?? (string) getcwd(),
			$this->defaultConfig ?? ($presets ? new Config : null),
		);
		$rules = [];
		foreach ($args['--rule'] as $rule) {
			if (!preg_match('~^(.+)=(on|off)$~D', $rule, $m)) {
				throw new UsageException("Option --rule expects name=on or name=off, '$rule' given.");
			}

			$rules[$m[1]] = $m[2] === 'on';
		}

		return [$config, $root, $file, $presets || $rules ? new Profile(presets: $presets, rules: $rules) : null];
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


	/** @return list<string> */
	private static function parsePaths(Result $args): array
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
		$resolved = FileSystem::resolvePath($this->cwd ?? (string) getcwd(), $path);
		return Helpers::canonicalizePath(realpath($resolved) ?: $resolved);
	}


	/**
	 * @param resource $stream
	 * @param string $root  the paths of the results are relative to it
	 * @param ?\Closure(): void $beforeWrite  called before the console reporter writes anything
	 */
	private function createReporter(
		Result $args,
		$stream,
		string $root,
		string $format,
		?\Closure $beforeWrite = null,
	): Reporter
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
				beforeWrite: $beforeWrite,
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
