<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\{Config, ConfigurationException, ConvergenceException, Plugin, Profile, Reporter, Reporters, RuleException};
use DressCode\Config\{Catalogue, ConfigResolver, CorePlugin, Loader, PhpVersionSource, PluginRegistry, ResolvedProject, RunnerFactory};
use DressCode\Engine\{Baseline, FileSummary, Helpers, RunInfo, Runner, RunResult, Worker, WorkerPool};
use DressCode\Measuring\Proposal;
use Nette\CommandLine\{Ansi, ColorDepth, Command, Console, HelpRenderer, Normalizers, ParseException as CommandLineException, Parser, ParseResult};
use Nette\Neon\{Exception as NeonException, Neon};
use Nette\Utils\{FileSystem, Helpers as UtilsHelpers, Json};
use function count, extension_loaded, in_array, is_string, sprintf;


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

	private readonly Console $out;
	private readonly Console $err;

	/** the script the workers are started with */
	private string $scriptFile = 'dresscode';

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
		private readonly ?string $script = null,
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
				'catalogue' => $this->runCatalogue($args),
				'init' => $this->runInit($args),
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
			'Add a preset or a plugin over the configuration, even without a configuration file; for `init` the standard to write, `perCs` by default',
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
		$baseline = $program->addCommand('baseline', 'Write the violations a fix leaves into the baseline file of the configuration, once a fix changes nothing');
		$config = $program->addCommand('config', 'Print the configuration as the run resolves it');
		$explain = $program->addCommand('explain', 'Explain a decision, its values and its value in this configuration; every decision the configuration makes when none is named');
		$catalogue = $program->addCommand('catalogue', 'List every decision the rules of the run declare, those this configuration makes marked');
		$program->addCommand('init', 'Measure the code of the project and write a `dresscode.neon` to fit');
		$program->addText('Exit codes: `0` clean, `1` violations, syntax errors or a refused baseline, `2` a file failed, `3` a mistake of the command line or of the configuration.');

		$explain->addArgument('decision', 'A decision, or a section or structure of them; the whole configuration when omitted', optional: true);

		foreach ([$check, $fix, $baseline] as $command) {
			$command->addArgument('paths', 'Files or directories; the configured paths when omitted', optional: true, repeatable: true);
			$command->addOption(
				'--format',
				'Output format, `github` by default in GitHub Actions; `bare` prints only what is left to the user and which files were rewritten, so a clean run prints nothing',
				alias: '-f',
				enum: ['console', 'bare', 'github', 'json', 'checkstyle'],
			);
			if ($command !== $baseline) { // a baseline holds what the configuration as it is leaves
				$command->addFlag('--diff', 'Show the fixes as a unified diff (`console` format)');
				$command->addOption(
					'--stdin',
					'Read the code from stdin as if it were the file at the path; `fix` writes the result to stdout',
					valueName: 'path',
				);
			}

			$command->addFlag('--skip-excluded', 'Skip a named file that `excludePaths` leaves out, as a hook or an editor passing every file it touches needs');
			if ($command !== $baseline) {
				$command->addOption(
					'--max-warnings',
					'Exit with `1` when more than `n` warnings are left; without it any number of warnings keeps the run clean',
					valueName: 'n',
					normalizer: Normalizers::int(min: 0),
				);
				$command->addFlag('--fix-risky', 'Also make the fixes that may change what the code does, beyond those `fixRisky` allows; they are reported either way');
			}

			$command->addFlag('--no-cache', 'Process every file, even one whose content is known to be clean');
			$command->addOption(
				'--jobs',
				'Number of worker processes; by default the number of processors, at most one per four files; `1` runs in-process',
				valueName: 'n',
				normalizer: Normalizers::int(min: 1),
			);
			$command->addFlag('--strict-rules', 'Treat a rule breaking its contract as an error, not a warning');
			$command->addOption('--worker', hidden: true); // the address of the parent; a worker started by WorkerPool
		}

		foreach ([$check, $fix, $config, $explain, $catalogue] as $command) {
			$command->addOption(
				'--only',
				'Only these: a decision, a section, a rule or a preset, narrowed to what the configuration runs; the values stay as the configuration resolves them',
				valueName: 'name',
				repeatable: true,
			);
		}

		$config->addOption('--file', 'Print what the configuration comes to for that one file', valueName: 'path');
		$config->addOption('--preset', 'Print what the preset comes to on its own, instead of the configuration', valueName: 'name');
		$config->addOption('--format', 'Output format: `console` to read, `json` as data', alias: '-f', enum: ['console', 'json']);
		$explain->addOption('--format', 'Output format: `console` to read, `markdown` as a document', alias: '-f', enum: ['console', 'markdown']);
		$catalogue->addOption('--format', 'Output format: `console` to read, `json` as data', alias: '-f', enum: ['console', 'json']);
		return $program;
	}


	private function runCheckOrFix(ParseResult $args, bool $fix): int
	{
		$factory = new RunnerFactory;
		['config' => $config, 'root' => $root, 'file' => $configFile, 'commandLine' => $commandLine] = $this->loadConfig($args);
		$only = self::parseOnly($args);
		$resolution = $factory->resolve($config, $root, $commandLine, $only);
		$fixRisky = (bool) ($args['--fix-risky'] ?? false);
		$generate = $args->command->name === 'baseline'; // the baseline command writes the configured baseline, it never reads it
		if (is_string($args['--worker'])) { // the parent keeps the cache; the baseline decides what is reported
			$runner = $factory->createRunner(
				$resolution,
				strict: (bool) $args['--strict-rules'],
				cache: false,
				fixRisky: $fixRisky,
				baseline: !$generate,
			);
			($runner->warmUp)?->__invoke(); // before connecting, which is what starts the other workers
			return Worker::run($args['--worker'], $runner, $fix);
		}

		$runner = $factory->createRunner(
			$resolution,
			strict: (bool) $args['--strict-rules'],
			cache: !$generate && !$args['--no-cache'], // the counts of a file served from the cache are those the baseline left
			configFile: $configFile,
			fixRisky: $fixRisky,
			baseline: !$generate,
		);
		foreach ($resolution->warnings as $warning => $docs) { // only the parent warns, a worker has returned above
			$this->err->writeLine(Markup::highlightCode($this->err, "Warning: $warning", 'yellow'));
			if ($docs !== null) {
				$this->err->writeLine(Markup::formatDocsLink($this->err, $docs));
			}
		}

		$stdinPath = $args['--stdin'] ?? null;
		$paths = array_values(array_unique(array_map($this->resolvePath(...), self::parsePaths($args))));
		$maxWarnings = $args['--max-warnings'] ?? null;
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
		// the machine-readable formats must not be prefaced, and a generated baseline is not a report
		$preface = !$generate && in_array($format, ['console', 'github'], true);
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

		// a worker costs about the processing of a few files to start, so by default one for every four files at most
		$jobs = $args['--jobs'] ?? max(1, min(WorkerPool::detectCpuCount(), intdiv(count($files), 4)));
		$workers = $jobs > 1 && $files ? new WorkerPool($this->buildWorkerCommand($args, $fix), $jobs, $this->cwd, warmFirst: $runner->warmUp !== null) : null;
		if ($generate) {
			return $this->generateBaseline($runner, $config, $root, $configFile, $files, $workers, $format);
		}

		$progress = $format === 'console' && count($files) > 1 && $this->out->isTerminal()
			? new ProgressBar($this->out, count($files))
			: null;
		$onProgress = $progress === null ? null : $progress->advance(...);
		$reporter = $this->createReporter($args, $this->out, $this->stdout, $root, $format, $factory->registry);

		try {
			return $runner->run($files, $fix, $reporter, $workers, $onProgress, $maxWarnings)->getExitCode();
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
		foreach (['--config', '--use', '--set', '--only'] as $option) {
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

		if ($args['--fix-risky'] ?? false) { // what a worker may fix has to be what the parent was asked for
			$command[] = '--fix-risky';
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
		Runner $runner,
		Config $config,
		string $root,
		?string $configFile,
		array $files,
		?WorkerPool $workers,
		string $format,
	): int
	{
		$name = $config->baseline ?? self::getDefaultBaselineName($configFile);
		$file = RunnerFactory::toAbsolutePath($name, $root);
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
	private static function getDefaultBaselineName(?string $configFile): string
	{
		return 'dresscode-baseline.' . (Loader::detectFormat($configFile ?? '') ?? 'neon');
	}


	/**
	 * Prints the configuration as the run resolves it, or a preset alone: every decision a layer set in the shape of
	 * the file with the layer that set it, and why a decision takes no effect where it does not.
	 */
	private function runConfig(ParseResult $args): int
	{
		$factory = new RunnerFactory;
		// a preset alone is what a project using it with nothing of its own comes to
		if (is_string($preset = $args['--preset'])) {
			try {
				$config = new Config(use: [$preset]);
			} catch (\InvalidArgumentException $e) {
				throw new UsageException("Option `--preset`: {$e->getMessage()}", previous: $e);
			}

			[$root, $configFile, $commandLine] = [Helpers::canonicalizePath($this->workingDirectory), null, null];

		} else {
			['config' => $config, 'root' => $root, 'file' => $configFile, 'commandLine' => $commandLine] = $this->loadConfig($args);
		}
		$resolution = $factory->resolve($config, $root, $commandLine, self::parseOnly($args));
		$file = $args['--file'];
		$resolved = is_string($file)
			? $resolution->resolveFor($factory->createRunner($resolution, cache: false, baseline: false)->findOverridesFor($this->resolvePath($file)))
			: $resolution->resolvedConfig;
		$printer = new ConfigPrinter($resolved, $resolution->upgradingData);
		if ($args['--format'] === 'json') {
			$this->out->write($printer->printJson());
			return 0;
		}

		$this->writeHeader($configFile, $config, $commandLine, self::describePhpVersion($resolution));
		if (is_string($file)) {
			$this->out->writeLine($this->out->color('gray', 'File       ') . FileSystem::platformSlashes($file));
		}

		$this->out->write($printer->print($this->out));
		return 0;
	}


	/**
	 * Explains a decision in detail with the values the standards give it, the decisions under a section, or the
	 * configuration as a whole when none is named, which is every decision a layer makes; the Markdown document is
	 * drawn, or with `--format markdown` written as it is.
	 * @throws UsageException
	 */
	private function runExplain(ParseResult $args): int
	{
		$path = $args['decision'];
		$factory = new RunnerFactory;
		['config' => $config, 'root' => $root, 'file' => $configFile, 'commandLine' => $commandLine] = $this->loadConfig($args);
		$resolution = $factory->resolve($config, $root, $commandLine, self::parseOnly($args));
		$resolved = $resolution->resolvedConfig;
		if (!is_string($path)) {
			$markdown = new ExplainPrinter($factory->registry)->printConfig($resolved);

		} elseif (isset($resolved->decisions[$path])) {
			$standards = self::resolveStandards($factory->registry, $resolved->phpVersion);
			$markdown = new ExplainPrinter($factory->registry, $standards)->printDecision($resolved->decisions[$path]);

		} elseif (array_any(array_keys($resolved->decisions), fn(string $known) => str_starts_with($known, "$path."))) {
			$markdown = new ExplainPrinter($factory->registry)->printSection($path, $resolved);

		} else {
			$hint = UtilsHelpers::getSuggestion(array_keys($resolved->decisions), $path);
			throw new UsageException("Unknown decision `$path`." . ($hint === null ? '' : " Did you mean `$hint`?"));
		}

		if ($args['--format'] === 'markdown') {
			$this->out->write($markdown);
			return 0;
		}

		$this->writeHeader($configFile, $config, $commandLine, self::describePhpVersion($resolution));
		$this->out->write("\n" . Markup::renderMarkdown($this->out, $markdown));
		return 0;
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
	 * The decisions each of the four standards makes, resolved alone.
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
	 * Measures how the project writes what can be measured and writes dresscode.neon with it; a configuration
	 * that exists is never overwritten, the proposal is printed instead and the exit code says so.
	 */
	private function runInit(ParseResult $args): int
	{
		$root = Helpers::canonicalizePath($this->workingDirectory);
		/** @var list<string> $use */
		$use = $args['--use'];
		$presets = $use ?: null;
		array_map((new PluginRegistry)->resolvePreset(...), $presets ?? []); // a misspelled one before the measuring, not after it
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
