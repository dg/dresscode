<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\{Config, ConfigurationException, ConvergenceException, Preset, PresetInfo, Profile, Reporter, Reporters, RuleException, RuleInfo};
use DressCode\Config\{Loader, PhpVersionSource, Proposal, RuleRegistry, RunnerFactory};
use DressCode\Engine\{Baseline, FileSummary, Helpers, RunInfo, Runner, RunResult, SuppressionMigration, WorkerClient, WorkerPool};
use DressCode\Interop\{PhpCodeSniffer, PhpCsFixer, Translator};
use Nette\CommandLine\{Ansi, ColorDepth, Command, Console, HelpRenderer, ParseException as CommandLineException, Parser, ParseResult};
use Nette\Neon\{Exception as NeonException, Neon};
use Nette\Utils\FileSystem;
use PhpSyntax\{ParseException, Printer};
use function array_slice, count, extension_loaded, in_array, is_array, is_bool, is_int, is_string, sprintf;


/**
 * The dresscode command line.
 */
final class Application
{
	public const Version = '1.0.0';

	/** @var resource */
	private $stdout;

	/** @var resource */
	private $stderr;

	/** @var resource */
	private $stdin;

	private Console $out;
	private Console $err;

	/** the script the workers are started with */
	private string $scriptFile = 'dresscode';

	/** PHP runs with Xdebug, which makes a run many times slower */
	private readonly bool $xdebug;

	/** a person answers at a terminal, which `fix --review` asks */
	private readonly bool $interactive;

	/** the directory the paths of the command line are relative to */
	private readonly string $workingDirectory;


	/**
	 * @param  ?resource  $stdout
	 * @param  ?resource  $stderr
	 * @param  ?resource  $stdin
	 * @param  ?bool  $xdebug  whether Xdebug is loaded; detected when the output is the process's own
	 * @param  ?bool  $interactive  whether a person answers at a terminal; detected from the output and the input
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
		?bool $interactive = null,
	) {
		$this->stdout = $stdout ?? STDOUT;
		$this->stderr = $stderr ?? STDERR;
		$this->stdin = $stdin ?? STDIN;
		// what the caller hands in is captured output, which FORCE_COLOR must not color
		$this->out = new Console($this->stdout, colorDepth: $stdout === null ? null : ColorDepth::None);
		$this->err = new Console($this->stderr, colorDepth: $stderr === null ? null : ColorDepth::None);
		$this->xdebug = $xdebug ?? ($stdout === null && extension_loaded('xdebug'));
		$this->interactive = $interactive ?? ($this->out->isTerminal() && stream_isatty($this->stdin));
		$this->workingDirectory = $cwd ?? (string) getcwd();
	}


	/**
	 * Runs the command line and returns the exit code: 0 clean, 1 violations, syntax errors or a refused
	 * baseline, 2 a file that failed, 3 a mistake of the command line or of the configuration.
	 * @param  list<string>  $argv  including the script name
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
				'check', 'baseline' => $this->runCheckOrFix($args, fix: false),
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
		$program->addOption('--preset', 'add a preset, which also runs without a configuration file; for `init` the standard to write, `perCs` by default', valueName: 'name', repeatable: true);
		$program->addOption('--group', 'add a group of rules, such as `cleanup` or `modernization`, which also runs without a configuration file', valueName: 'name', repeatable: true);
		$program->addOption('--rule', 'set a rule, `name` or `name=value` with the value in NEON: `true`, `false`, `keep`, the value of its decision such as `forbidden`, or its options as `{minImports: 2}`; a bare name enables it', valueName: 'spec', repeatable: true);
		$program->addFlag('--no-color', 'plain output');
		$program->addFlag('--help', 'print this help', standalone: true);
		$program->addFlag('--version', 'print the name and the version', standalone: true);

		$check = $program->addCommand('check', 'report violations');
		$fix = $program->addCommand('fix', 'fix what the rules can and report the rest');
		$baseline = $program->addCommand('baseline', 'write the violations a fix leaves into the baseline file of the configuration, once a fix changes nothing');
		$config = $program->addCommand('config', 'print the configuration as the run resolves it');
		$explain = $program->addCommand('explain', 'explain what a rule is for and its options here; every rule that runs when none is named');
		$rules = $program->addCommand('rules', 'list the known rules');
		$program->addCommand('init', 'take the measurements of the project\'s code and write `dresscode.neon` to fit');
		$import = $program->addCommand('import', 'translate a php-cs-fixer or phpcs configuration');
		$migrate = $program->addCommand('migrate-suppressions', 'rewrite phpcs suppression comments to the dresscode form');
		$program->addText('Exit codes: `0` clean, `1` violations, syntax errors or a refused baseline, `2` a file failed, `3` a mistake of the command line or of the configuration.');

		foreach ([$check, $fix, $baseline, $migrate] as $command) {
			$command->addArgument('paths', 'files or directories; the configured paths when omitted', optional: true, repeatable: true);
		}

		$explain->addArgument('rule', 'name of the rule; every rule that runs when omitted', optional: true);
		$import->addArgument('file', 'php-cs-fixer or phpcs configuration file');

		foreach ([$check, $fix, $baseline] as $command) {
			$command->addOption(
				'--format',
				'`github` when running there; `bare` says only what is left to the user and which files were rewritten, so a clean run says nothing at all',
				alias: '-f',
				enum: ['console', 'bare', 'github', 'json', 'checkstyle'],
			);
			if ($command !== $baseline) { // a baseline holds what the configuration as it is leaves
				$command->addFlag('--diff', 'show the fixes as a unified diff (`console` format)');
				$command->addOption(
					'--stdin',
					'read the code from stdin as if it were the file at the path; `fix` writes the result to stdout',
					valueName: 'path',
				);
			}

			$command->addFlag('--skip-excluded', 'skip a named file that `excludePaths` of the configuration leaves out, which a hook or an editor naming every file it touches wants');
			if ($command !== $baseline) {
				$command->addOption(
					'--max-warnings',
					'exit with `1` when more than `n` warnings are left; without it any number of them keeps the run clean',
					valueName: 'n',
				);
				$command->addOption(
					'--fix-risky',
					'also make the fixes that may change what the code does, not only those of the rules the configuration names in `fixRisky`: of every rule, or with `=name` of that rule, preset or group; they are reported either way',
					valueName: 'name',
					valueOptional: true,
					repeatable: true,
				);
			}

			$command->addFlag('--no-cache', 'process every file, even one whose content is known to be clean');
			$command->addOption(
				'--jobs',
				'worker processes; by default the number of processors, at most one per four files; `1` runs in-process',
				valueName: 'n',
			);
			$command->addFlag('--strict-rules', 'a rule breaking its contract is an error, not a warning');
			$command->addOption('--worker', hidden: true); // the address of the parent; a worker started by WorkerPool
		}

		$fix->addFlag('--review', 'after the fix, ask about every risky fix left, one at a time with its diff, and make those accepted; needs an interactive terminal');

		foreach ([$check, $fix, $config, $explain, $rules] as $command) {
			$command->addOption(
				'--only',
				'run only these of the rules the configuration comes to, a preset standing for all of its rules',
				valueName: 'name',
				repeatable: true,
			);
		}

		$config->addOption('--file', 'what the configuration comes to for that one file', valueName: 'path');
		$config->addOption('--format', '`console` to read, `json` as data', alias: '-f', enum: ['console', 'json']);
		$explain->addOption('--format', '`console` to read, `markdown` as a document', alias: '-f', enum: ['console', 'markdown']);
		return $program;
	}


	private function runCheckOrFix(ParseResult $args, bool $fix): int
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
				fixRisky: in_array(true, (array) ($args['--fix-risky'] ?? []), true),
				baseline: $args->command->name !== 'baseline',
			);
			($runner->warmUp)?->__invoke(); // before connecting, which is what starts the other workers
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
			fixRisky: in_array(true, (array) ($args['--fix-risky'] ?? []), true),
		);
		foreach ($factory->getWarnings() as $warning) { // only the parent warns, a worker has returned above
			$this->err->writeLine(Markup::highlightCode($this->err, "Warning: $warning", 'yellow'));
		}

		$stdinPath = $args['--stdin'] ?? null;
		$paths = array_values(array_unique(array_map($this->resolvePath(...), self::parsePaths($args))));
		$maxWarnings = ($args['--max-warnings'] ?? null) === null ? null : max(0, (int) $args['--max-warnings']);
		$review = $fix && isset($args['--review']) && $args['--review'];
		if ($review && ($args['--fix-risky'] || is_string($stdinPath))) {
			throw new UsageException('`--review` asks about the risky fixes one by one, so it goes with neither `--fix-risky` nor `--stdin`.');
		} elseif (
			$review
			&& (self::resolveFormat($args, detect: true) !== 'console' || !$this->interactive)
		) {
			throw new UsageException('`--review` asks in an interactive terminal; without one, allow the risky fixes with `--fix-risky=<name>`.');
		}

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
		$generate = $args->command->name === 'baseline';
		$files = $runner->findFiles($scope, skipExcluded: (bool) $args['--skip-excluded']);
		// the machine-readable formats must not be prefaced, and a generated baseline is not a report
		if (!$generate && in_array($format, ['console', 'github'], true)) {
			if ($this->xdebug) {
				$this->err->writeLine('Warning: Xdebug is loaded and makes the run many times slower.', 'red');
			}

			$this->writeHeader($configFile, $config, $commandLine, self::describePhpVersion($factory));
			$this->writeScope(files: $files, paths: $scope, root: $root, fix: $fix, narrowed: $paths && $scope !== $paths);
		}

		// a worker costs about the processing of a few files to start, so by default one for every four files at most
		$jobs = $args['--jobs'] === null
			? max(1, min(WorkerPool::detectCpuCount(), intdiv(count($files), 4)))
			: max(1, (int) $args['--jobs']);
		$workers = $jobs > 1 && $files ? new WorkerPool($this->buildWorkerCommand($args, $fix), $jobs, $this->cwd, warmFirst: $runner->warmUp !== null) : null;
		if ($generate) {
			return $this->generateBaseline($factory, $config, $root, $configFile, $commandLine, $files, $workers, $format);
		}

		$progress = $format === 'console' && count($files) > 1 && $this->out->isTerminal()
			? new ProgressBar($this->out, count($files))
			: null;
		$onProgress = $progress === null ? null : $progress->advance(...);
		$reporter = $this->createReporter($args, $this->out, $this->stdout, $root, $format, $factory->registry);

		try {
			$result = $runner->run($files, $fix, $reporter, $workers, $onProgress, $maxWarnings);
			return $review
				? $this->review($runner, $result, $files, $root, $maxWarnings, $factory->registry)
				: $result->getExitCode();
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
		$reporter->start(new RunInfo($root, $fix, 1, $runner->types, $runner->namespacesListed));
		$result = $runner->processFile($this->resolvePath($path), $code);
		$reporter->reportFile($result);
		$run = new RunResult([FileSummary::of($result)], $fix, maxWarnings: $maxWarnings);
		$reporter->finish($run);
		if ($fix) {
			$this->out->write($result->output ?? $code);
		}

		return $run->getExitCode();
	}


	/**
	 * Asks about the risky fixes the fix left and makes those accepted, then says how it ended; the exit code is that of
	 * the files as the review left them.
	 * @param  list<string>  $files
	 */
	private function review(Runner $runner, RunResult $result, array $files, string $root, ?int $maxWarnings, RuleRegistry $registry): int
	{
		if ($result->countRefused() === 0) {
			return $result->getExitCode();
		}

		$made = new RiskReview($runner, $this->out, $this->stdin, $root, $registry)->review($result);
		$final = $runner->run($files, true, new Reporters\NullReporter, maxWarnings: $maxWarnings);
		$left = $final->countRefused();
		$this->out->writeLine(
			"\n" . $this->out->color(
				$final->getExitCode() === 0 ? 'white/green' : 'white/red',
				sprintf('REVIEWED  %d risky %s made, %s', $made, $made === 1 ? 'fix' : 'fixes', $left ? "$left left" : 'none left'),
			),
		);
		return $final->getExitCode();
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
	 * The command line of a worker: the same PHP, the same command and configuration; the paths come over the
	 * connection.
	 * @return list<string>
	 */
	private function buildWorkerCommand(ParseResult $args, bool $fix): array
	{
		$command = [
			...WorkerPool::buildPhpCommand(),
			$this->scriptFile,
			$fix ? 'fix' : ($args->command->name === 'baseline' ? 'baseline' : 'check'),
			'--no-color',
		];
		foreach (['--config', '--preset', '--group', '--rule', '--only'] as $option) {
			foreach ((array) ($args[$option] ?? []) as $value) {
				if (is_string($value)) {
					$command[] = $option;
					$command[] = $value;
				}
			}
		}

		if ($args['--strict-rules']) {
			$command[] = '--strict-rules';
		}

		foreach ((array) ($args['--fix-risky'] ?? []) as $value) { // what a worker may fix has to be what the parent was asked for
			$command[] = is_string($value) ? "--fix-risky=$value" : '--fix-risky';
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
			if ($result->failure !== null || $result->syntaxError !== null) {
				$failed[] = $result->path;
			} elseif ($result->changed) {
				$changed[] = $result->path;
			}
		}

		if ($changed || $failed) {
			$counts = array_filter([
				$changed ? sprintf('a fix would change %d file%s', count($changed), count($changed) === 1 ? '' : 's') : null,
				$failed ? sprintf('%d file%s failed', count($failed), count($failed) === 1 ? '' : 's') : null,
			]);
			$paths = [...$changed, ...$failed];
			$this->writeError(
				'The baseline is generated over code a fix leaves alone: ' . implode(', ', $counts) . '. Run `fix` first.',
				'suppressing#baseline',
				implode('', array_map(fn(string $path) => "  $path\n", array_slice($paths, 0, 10)))
				. (count($paths) > 10 ? '  and ' . (count($paths) - 10) . " more\n" : ''),
			);
			return 1;
		}

		$baseline = Baseline::fromResults($run->files);
		$baseline->save($file);
		$message = sprintf("Baseline with %d violation%s written to `%s`.\n", $baseline->count(), $baseline->count() === 1 ? '' : 's', $name);
		if ($config->baseline === null) {
			$message .= "Name it under `baseline` in the configuration to make it apply.\n";
		}

		// every other format keeps its stream to itself
		$format === 'console' ? $this->write($message) : $this->writeNote($message);
		return 0;
	}


	/** The baseline is written in the format the configuration is written in, so there is no third format. */
	private static function defaultBaselineName(?string $configFile): string
	{
		return 'dresscode-baseline.' . (Loader::detectFormat($configFile ?? '') ?? 'neon');
	}


	/**
	 * Rewrites the phpcs suppression comments of the files to the dresscode form and writes them back.
	 */
	private function runMigrateSuppressions(ParseResult $args): int
	{
		$factory = new RunnerFactory;
		[$config, $root, , $commandLine] = $this->loadConfig($args);
		$runner = $factory->createRunner($config, $root, $commandLine);
		$named = array_map($this->resolvePath(...), self::parsePaths($args));
		$paths = $named ? $runner->narrowPaths($named, $config->paths) : $config->paths;
		if (!$paths) {
			throw new UsageException('No paths given and none configured.');
		}

		$migration = new SuppressionMigration($factory->registry->resolveNames(...));
		$parser = new \PhpSyntax\Parser;
		$files = 0;
		foreach ($runner->findFiles($paths) as $path) {
			$absolute = $runner->toAbsolute($path);
			$code = @file_get_contents($absolute); // @ - reported as exception
			if ($code === false) {
				throw new \RuntimeException("Cannot read file `$path`.");
			}

			try {
				$file = $parser->parse($code);
			} catch (ParseException $e) {
				$this->write("`$path`: skipped, {$e->getMessage()}\n");
				continue;
			}

			if ($migration->migrate($file)) {
				if (@file_put_contents($absolute, Printer::print($file)) === false) { // @ - reported as exception
					throw new \RuntimeException("Cannot write file `$path`.");
				}

				$files++;
			}
		}

		$this->write(sprintf("Migrated %d suppression comment%s in %d file%s.\n", $migration->count, $migration->count === 1 ? '' : 's', $files, $files === 1 ? '' : 's'));
		if ($migration->unknownNames) {
			$this->write('Warning: The names DressCode does not know are kept as they are: `' . implode('`, `', array_keys($migration->unknownNames)) . "`.\n");
		}

		if ($migration->ownLineIgnore) {
			$this->write("Note: A `dresscode:ignore` on a line of its own covers the whole statement below it, not just the next line; review the migrated ones.\n");
		}

		return 0;
	}


	/**
	 * Prints the configuration as the run resolves it: which rule runs with which options, which layer gave
	 * every value and what it overrode, and why a rule does not run.
	 */
	private function runConfig(ParseResult $args): int
	{
		$factory = new RunnerFactory;
		[$config, $root, $configFile, $commandLine] = $this->loadConfig($args);
		$runner = $factory->createRunner($config, $root, $commandLine, self::parseOnly($args), cache: false);
		$file = $args['--file'];
		$resolved = is_string($file)
			? $factory->resolveConfigFor($runner->findOverridesFor($file))
			: $factory->getResolvedConfig();
		$printer = new ConfigPrinter($resolved, $factory->getPackages());
		if ($args['--format'] === 'json') {
			$this->out->write($printer->printJson());
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
	 * Explains a rule in detail, or the configuration as a whole when none is named, which is every rule that runs
	 * with the options it has under this configuration; the Markdown document is drawn, or with `--format markdown`
	 * written as it is.
	 * @throws UsageException
	 */
	private function runExplain(ParseResult $args): int
	{
		$name = $args['rule'];
		$factory = new RunnerFactory;
		[$config, $root, $configFile, $commandLine] = $this->loadConfig($args);
		$factory->createRunner($config, $root, $commandLine, self::parseOnly($args), cache: false);
		$resolved = $factory->getResolvedConfig();
		$printer = new ExplainPrinter($factory->registry);
		if (is_string($name)) {
			$rule = $resolved->getRule(RuleInfo::of($factory->registry->resolveRule($name))->name);
			if ($rule === null) {
				throw new UsageException("Rule `$name` is not part of this configuration.");
			}

			$markdown = $printer->printRule($rule);

		} else {
			$markdown = $printer->printConfig($resolved);
		}

		if ($args['--format'] === 'markdown') {
			$this->out->write($markdown);
			return 0;
		}

		$this->writeHeader($configFile, $config, $commandLine, self::describePhpVersion($factory));
		$this->out->write("\n" . Markup::renderMarkdown($this->out, $markdown));
		return 0;
	}


	private function runRules(ParseResult $args): int
	{
		$factory = new RunnerFactory;
		[$config, $root, , $commandLine] = $this->loadConfig($args);
		$runner = $factory->createRunner($config, $root, $commandLine, self::parseOnly($args));
		$enabled = [];
		foreach ($runner->getProcessor()->rules as $rule) {
			$enabled[RuleInfo::of($rule)->name] = true;
		}

		$registry = $factory->registry;
		$rules = $registry->rules;
		ksort($rules, SORT_STRING);
		foreach ($rules as $name => $class) {
			$info = RuleInfo::of($class);
			$covers = $registry->translator->findForeignNames($name);
			$this->out->writeLine(
				(isset($enabled[$name]) ? '*' : ' ')
				. ' ' . Ansi::pad($this->out->color(isset($enabled[$name]) ? 'white' : null, $name), 45)
				. ' ' . Ansi::pad($info->stage->name, 10)
				. ' ' . Markup::highlightCode($this->out, $info->description)
				. ($covers ? $this->out->color('gray', '  (covers ' . implode(', ', $covers) . ')') : ''),
			);
		}

		$this->out->writeLine("\n* enabled by the configuration");
		return 0;
	}


	/**
	 * Measures how the project writes what can be measured and writes dresscode.neon with it; a configuration
	 * that exists is never overwritten, the proposal is printed instead and the exit code says so.
	 */
	private function runInit(ParseResult $args): int
	{
		$root = Helpers::canonicalizePath($this->workingDirectory);
		$presets = $args['--preset'] ?: null;
		array_map((new RuleRegistry)->resolvePreset(...), $presets ?? []); // a misspelled one before the measuring, not after it
		$proposal = Proposal::measure($root, $presets);
		$proposal->verify($root);
		$neon = $proposal->toNeon();

		$existing = Loader::listFiles($root);
		$console = $existing ? $this->err : $this->out;
		$console->write(Markup::highlightCode($console, $this->formatName($console) . "\n" . new InitPrinter($proposal)->print($console)));
		if ($existing) {
			$this->writeNote('`' . implode('` and `', $existing) . '`' . (count($existing) > 1 ? ' exist' : ' exists') . ", so the proposal is printed and nothing is written.\n");
			$this->out->write($neon);
			return 3;
		}

		FileSystem::write("$root/dresscode.neon", $neon);
		$this->write("\n`dresscode.neon` written, made to measure.\n");
		return 0;
	}


	private function runImport(ParseResult $args): int
	{
		$file = $args['file'];
		[$rules, $unread] = preg_match('~\.xml(\.dist)?$~Di', $file)
			? PhpCodeSniffer::readConfig($file)
			: [PhpCsFixer::readConfig($file), []];
		$translation = (new Translator)->translate($rules);
		foreach ($unread as $warning) {
			$translation->warn($warning);
		}

		$this->out->write($translation->toConfig());
		$disabled = count(array_filter($translation->rules, fn($options) => $options === false));
		$count = fn(int $n, string $noun) => $n . ' ' . $noun . ($n === 1 ? '' : 's');
		$this->writeNote(sprintf(
			"\nRead %s; enabled %s and %s%s.\n",
			$count(count($rules), 'rule'),
			$count(count($translation->rules) - $disabled, 'rule'),
			$count(count($translation->presets), 'preset'),
			$disabled ? ', turned off ' . $count($disabled, 'rule') : '',
		));
		foreach ($translation->warnings as $warning) {
			$this->writeNote("  $warning\n");
		}

		if (!$translation->presets) {
			$this->writeNote("  The indentation and the line ending are not translated; set them with the keys `indent` and `lineEnding`.\n");
		}

		return 0;
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
		/** @var list<string> $groups */
		$groups = $args['--group'];
		[$config, $root, $file] = (new Loader)->load(
			$args['--config'],
			$this->workingDirectory,
			// without a configuration file the run has only the presets and groups named on the command line
			$this->defaultConfig ?? ($presets || $groups ? new Config : null),
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

		// the rules, presets and groups whose risky fixes the run allows; a bare `--fix-risky` allows every one elsewhere
		$fixRisky = array_values(array_filter((array) ($args['--fix-risky'] ?? []), is_string(...)));
		return [
			$config,
			$root,
			$file,
			$presets || $groups || $rules || $fixRisky ? new Profile(presets: $presets, groups: $groups, rules: $rules, fixRisky: $fixRisky) : null,
		];
	}


	/**
	 * The rules and presets the run is narrowed to; null when it is not.
	 * @return ?list<string>
	 */
	private static function parseOnly(ParseResult $args): ?array
	{
		/** @var list<string> $only */
		$only = $args['--only'] ?? [];
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


	/** A message of the tool on the output, its code drawn; what is not a message (code, JSON) goes to `$out` itself. */
	private function write(string $text): void
	{
		$this->out->write(Markup::highlightCode($this->out, $text));
	}


	/** A message of the tool on the error output, which keeps the output for what the command produces. */
	private function writeNote(string $text): void
	{
		$this->err->write(Markup::highlightCode($this->err, $text));
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
