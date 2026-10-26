<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\{Config, Reporter, Reporters};
use DressCode\Config\{PluginRegistry, ResolvedProject};
use DressCode\Console\Commands\{CheckOptions, ConfigOptions, RunOptions, TodayUpgrade};
use DressCode\Engine\{Baseline, FileSummary, Helpers, LineRanges, Profiler, Runner, RunResult, Worker, WorkerPool};
use Nette\CommandLine\{Console, ParseResult, UsageException};
use Nette\Utils\FileSystem;
use function count, in_array, is_string, sprintf, strlen;
use const JSON_PRETTY_PRINT, JSON_THROW_ON_ERROR, JSON_UNESCAPED_SLASHES;


/**
 * The run over the files of the project, or over the code of stdin, which `check`, `fix`, `baseline` and `upgrade` share:
 * the workers, the reporter, the progress, the review of the risky fixes and the profile.
 * @internal
 */
final readonly class RunExecutor
{
	public function __construct(
		private CommandContext $context,
	) {
	}


	/**
	 * @param  ?CheckOptions  $check  what check and fix take beyond the run, null for the baseline
	 * @param  bool  $generate  the baseline command writes the configured baseline, it never reads it
	 * @param  ?TodayUpgrade  $upgrade  the run of `upgrade`; null for another command
	 */
	public function run(
		ParseResult $args,
		ConfigOptions $configuration,
		RunOptions $run,
		?CheckOptions $check = null,
		bool $fix = false,
		bool $generate = false,
		bool $askRisky = false,
		?TodayUpgrade $upgrade = null,
	): int
	{
		// `fix --stdin` is the formatter of an editor, which reads the text and the exit code alone
		$formatter = $fix && $check?->stdin !== null;
		foreach ([
			'--format' => $run->format,
			'--diff' => $check?->diff,
			'--max-warnings' => $check?->maxWarnings,
			'--ask-risky' => $askRisky ?: null,
			'--lines' => $check?->lines ?: null,
			'--before' => $check?->before,
		] as $option => $value) {
			if ($formatter && $value !== null) {
				throw new UsageException(
					"`fix --stdin` writes the fixed code alone, so it takes no `$option`; `check --stdin` reports on the code in any format.",
					$args->command,
				);
			}
		}

		[$factory, $resolution, $configFile] = $upgrade === null
			? $this->context->resolveProject($args, $configuration, $check->only ?? [])
			: $upgrade->resolve($args, $configuration);
		[$config, $root] = [$resolution->config, $resolution->root];
		$profiler = $run->profile === null ? null : new Profiler;
		$fixRisky = in_array(true, $check->fixRisky ?? [], true); // a bare `--fix-risky`, the names given go to the configuration
		if ($run->worker !== null) { // the parent keeps the cache; the baseline decides what is reported
			$runner = $factory->createRunner(
				$resolution,
				strict: (bool) $run->strictRules,
				cache: false,
				fixRisky: $fixRisky,
				baseline: !$generate,
				profiler: $profiler,
			);
			($runner->warmUp)?->__invoke(); // before connecting, which is what starts the other workers
			return Worker::run($run->worker, $runner, $fix, $profiler);
		}

		$options = [
			'strict' => (bool) $run->strictRules,
			// a file served from the cache has nothing to measure, and its counts are those the baseline left
			'cache' => !$generate && !$profiler,
			'configFile' => $configFile,
			'fixRisky' => $fixRisky,
			'baseline' => !$generate,
			'profiler' => $profiler,
			'refreshCache' => (bool) $run->noCache,
		];
		$runner = $factory->createRunner($resolution, ...$options);
		[$out, $err] = [$this->context->out, $this->context->err];
		$format = self::resolveFormat($run->format, detect: true);
		// only the parent warns, a worker has returned above; an agent can do nothing about the configuration
		foreach ($formatter || $format === 'agent' ? [] : $resolution->warnings as $warning => $docs) {
			$err->writeLine(Markup::highlightCode($err, "Warning: $warning", 'yellow'));
			if ($docs !== null) {
				$err->writeLine(Markup::formatDocsLink($err, $docs));
			}
		}

		$stdinPath = $check?->stdin;
		$paths = array_values(array_unique(array_map($this->context->resolvePath(...), $run->paths)));
		$maxWarnings = $check?->maxWarnings;
		if ($askRisky && ($check?->fixRisky || is_string($stdinPath))) {
			throw new UsageException(
				'`--ask-risky` asks about the risky fixes one by one, so it goes with neither `--fix-risky` nor `--stdin`.',
				$args->command,
			);
		} elseif ($askRisky && ($format !== 'console' || !$this->context->prompt->isInteractive())) {
			throw new UsageException(
				'`--ask-risky` asks in an interactive terminal; without one, allow the risky fixes with `--fix-risky=<name>`.',
				$args->command,
			);
		}

		$lines = $check?->lines ? new LineRanges($check->lines) : null;
		$before = $check?->before;
		if ($lines && $format !== 'agent') {
			throw new UsageException('`--lines` narrows what the `agent` format reports, so it goes with no other format.', $args->command);
		} elseif ($before !== null && ($format !== 'agent' || is_string($stdinPath))) {
			throw new UsageException(
				'`--before` tells the `agent` format what an edit of a file did, so it goes with no other format and no `--stdin`.',
				$args->command,
			);
		} elseif ($lines && $run->strictRules) {
			throw new UsageException(
				'`--lines` names the lines of the file as it was read, which `--strict-rules` reads again in every round.',
				$args->command,
			);
		}

		if (is_string($stdinPath)) {
			if ($paths) {
				throw new UsageException('Paths cannot be combined with `--stdin`.', $args->command);
			}

			return $formatter
				? $this->runFormatter($runner, $stdinPath, (bool) $run->skipExcluded, $config->paths)
				: $this->runStdin(
					self::resolveFormat($run->format, detect: false), // the caller of stdin is an editor or a hook waiting for the format it asked for
					(bool) $check->diff,
					$runner,
					$factory->registry,
					$resolution,
					$stdinPath,
					$maxWarnings,
					(bool) $run->skipExcluded,
					$config->paths,
					$lines,
				);
		}

		$scope = $paths ? $runner->narrowPaths($paths, $config->paths) : $config->paths;
		if (!$scope) {
			throw new UsageException('No paths given and none configured.', $args->command);
		}

		// the machine-readable formats must not be prefaced, and a generated baseline is not a report
		$preface = !$generate && in_array($format, ['console', 'github'], true);
		if ($preface) {
			if ($this->context->xdebug) {
				$err->writeLine('Warning: Xdebug is loaded and makes the run many times slower.', 'red');
			}

			$this->context->writeHeader($configFile, $resolution, Baseline::findFile($config, $root));
			$upgrade?->writePreface($resolution);

			$out->setStatus($out->color('gray', $fix ? 'Fixing     ' : 'Checking   ') . 'looking for files…');
		}

		try {
			$files = $runner->findFiles($scope, skipExcluded: (bool) $run->skipExcluded, configured: $config->paths);
		} finally {
			$out->clearStatus();
		}

		if ($preface) {
			$this->writeScope(files: $files, paths: $scope, root: $root, fix: $fix, narrowed: $paths && $scope !== $paths);
		}

		if (($lines || $before !== null) && count($files) > 1) {
			throw new UsageException(
				($lines ? '`--lines` names the lines of one file' : '`--before` names the text of one file')
				. ', but ' . count($files) . ' files are to be run.',
				$args->command,
			);
		} elseif ($before !== null && $files) {
			$code = @file_get_contents($this->context->resolvePath($before)); // @ the failure is said below
			if ($code === false) {
				throw new UsageException("Cannot read file `$before`, which `--before` names.", $args->command);
			}

			$left = $this->findBrokenDecisions($runner, $files[0], $code);
			$runner = $factory->createRunner($resolution, ...$options, leftAsWritten: $left);
			$lines = new LineRanges($check->lines, array_fill_keys($left, true));
		}

		$jobs = $run->jobs ?? WorkerPool::countDefaultJobs(count($files));
		$command = $jobs > 1 && $files
			? $this->buildWorkerCommand($args, $configuration, $run, $check, $upgrade?->getWorkerOptions($resolution) ?? [])
			: null;
		$workers = $command
			? new WorkerPool($command, $jobs, $this->context->workingDirectory, profiler: $profiler, warmFirst: $runner->warmUp !== null)
			: null;
		$progress = !$generate && $format === 'console' && count($files) > 1 && $out->isTerminal()
			? new ProgressBar($out, count($files))
			: null;
		$onProgress = $progress === null ? null : $progress->advance(...);

		$profiler?->addPhase('start of the process', (int) ((microtime(as_float: true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1e9));
		$start = hrtime(as_number: true);
		try {
			if ($generate) {
				return $this->generateBaseline($runner, $config, $root, $configFile, $files, $workers, $format);
			}

			$reporter = $this->createReporter(
				(bool) $check?->diff,
				$out,
				$this->context->stdout,
				$root,
				$format,
				$factory->registry,
				$runner,
				$resolution,
				$run->paths,
				$upgrade === null ? 'fix' : TodayUpgrade::Command,
			);
			$result = $runner->run($files, $fix, $reporter, $workers, $onProgress, $maxWarnings, $lines);
			if ($upgrade !== null && $preface && $result->countChangedFiles()) {
				$out->writeLine('Run `dresscode ' . TodayUpgrade::Command . '` again: the code it rewrote may hold more to upgrade.');
			}

			return $askRisky
				? $this->review($runner, $result, $files, $root, $maxWarnings, $factory->registry)
				: $result->getExitCode();

		} finally {
			$progress?->clear(); // an error must not be written into the bar
			if ($profiler && $run->profile !== null) {
				$profiler->addPhase('run', hrtime(as_number: true) - $start);
				FileSystem::write(
					$this->context->resolvePath($run->profile),
					json_encode($profiler->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
				);
			}
		}
	}


	/**
	 * The decisions the file as it was before an edit broke, which the run then leaves as the file writes them.
	 * @return list<string>
	 */
	private function findBrokenDecisions(Runner $runner, string $file, string $code): array
	{
		if ($runner->isKnownClean($file, $code)) { // the text the previous fix of the file wrote, most often
			return [];
		}

		$decisions = array_column($runner->processCode($file, $code)->violations, 'decision');
		return array_values(array_unique($decisions));
	}


	/**
	 * Reports on the code read from stdin as if it were the file at the path; nothing of a path the excluded ones leave
	 * out where the caller asks to skip it.
	 * @param  list<string>  $configured
	 */
	private function runStdin(
		string $format,
		bool $diff,
		Runner $runner,
		PluginRegistry $registry,
		ResolvedProject $resolution,
		string $path,
		?int $maxWarnings,
		bool $skipExcluded,
		array $configured,
		?LineRanges $lines,
	): int
	{
		$reporter = $this->createReporter(
			$diff,
			$this->context->out,
			$this->context->stdout,
			$resolution->root,
			$format,
			$registry,
			$runner,
			$resolution,
			[$path],
		);
		$code = (string) stream_get_contents($this->context->stdin);
		$absolute = $this->context->resolvePath($path);
		$skipped = $skipExcluded && $runner->excludes($absolute, $configured);
		$reporter->start($runner->createRunInfo(false, $skipped ? 0 : 1));
		$result = $skipped ? null : $runner->processPath($absolute, fix: false, code: $code);
		$result = $result === null ? null : $lines?->select($result, fix: false) ?? $result;
		if ($result) {
			$reporter->reportFile($result);
		}

		$run = new RunResult($result ? [FileSummary::of($result)] : [], fix: false, maxWarnings: $maxWarnings);
		$reporter->finish($run);
		return $run->getExitCode();
	}


	/**
	 * Formats the code read from stdin as if it were the file at the path, for an editor: the whole fixed code goes to
	 * stdout with exit code 0, whatever it still violates; a syntax error or a failure leaves the code as it came, its
	 * one line on stderr, and so does a path the excluded ones leave out, without a word.
	 * @param  list<string>  $configured
	 */
	private function runFormatter(Runner $runner, string $path, bool $skipExcluded, array $configured): int
	{
		$code = (string) stream_get_contents($this->context->stdin);
		$path = $this->context->resolvePath($path);
		$result = $skipExcluded && $runner->excludes($path, $configured)
			? null
			: $runner->processPath($path, fix: false, code: $code);
		[$output, $error, $exit] = match (true) {
			$result?->syntaxError !== null => [$code, "$result->path:$result->syntaxErrorLine: Syntax error: $result->syntaxError", 1],
			$result?->failure !== null => [$code, "$result->path: " . strtok($result->failure, "\n"), 2],
			default => [$result->output ?? $code, null, 0],
		};

		if ($error !== null) {
			fwrite($this->context->stderr, "$error\n");
		}

		return fwrite($this->context->stdout, $output) === strlen($output) ? $exit : 2;
	}


	/**
	 * Asks about the risky fixes the fix left and makes those accepted, then says how it ended; the exit code is that of
	 * the files as the review left them.
	 * @param  list<string>  $files
	 */
	private function review(Runner $runner, RunResult $result, array $files, string $root, ?int $maxWarnings, PluginRegistry $registry): int
	{
		$out = $this->context->out;
		if ($result->countRefused() === 0) {
			$out->writeLine("\n" . $out->color($result->getExitCode() === 0 ? 'white/green' : 'white/red', 'REVIEWED  no risky fixes to ask about'));
			return $result->getExitCode();
		}

		$made = new RiskReview($runner, $out, $this->context->prompt, $root, $registry)->review($result);
		$final = $runner->run($files, true, new Reporters\NullReporter, maxWarnings: $maxWarnings);
		$left = $final->countRefused();
		$out->writeLine(
			"\n" . $out->color(
				$final->getExitCode() === 0 ? 'white/green' : 'white/red',
				sprintf('REVIEWED  %d risky %s made, %s', $made, $made === 1 ? 'fix' : 'fixes', $left ? "$left left" : 'none left'),
			),
		);
		return $final->getExitCode();
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
		$out = $this->context->out;
		$absolute = array_map(fn(string $file) => FileSystem::isAbsolute($file) ? $file : "$root/$file", $files);
		$scope = count($files) === 1
			? FileSystem::platformSlashes($absolute[0])
			: sprintf('%d files in %s', count($files), FileSystem::platformSlashes(self::findCommonDirectory($absolute) ?: $root));
		$out->write($files
			? $out->color('gray', $fix ? 'Fixing     ' : 'Checking   ') . $scope
				. ($narrowed ? $out->color('gray', ', narrowed to the configured paths') : '') . "\n\n"
			: $out->color('yellow', sprintf(
				($fix ? 'Nothing to fix' : 'Nothing to check') . ': no file in %s',
				implode(', ', array_map(FileSystem::platformSlashes(...), $paths)),
			)) . "\n");
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
	 * connection. Null where there is no PHP to start one with, and the run stays in the process.
	 * @param  ?CheckOptions  $check  null for the baseline
	 * @param  list<string>  $extra  what the parent decided for the run beyond its command line
	 * @return ?list<string>
	 */
	private function buildWorkerCommand(
		ParseResult $args,
		ConfigOptions $configuration,
		RunOptions $run,
		?CheckOptions $check,
		array $extra = [],
	): ?array
	{
		$php = WorkerPool::buildPhpCommand();
		if ($php === null) {
			return null;
		}

		$command = [
			...$php,
			$this->context->scriptFile,
			(string) $args->command->name,
			'--no-color',
			...$extra,
		];
		foreach ([
			'--config' => (array) $configuration->config,
			'--use' => $configuration->use,
			'--set' => $configuration->set,
			'--severity' => $configuration->severity,
			'--root' => (array) $configuration->root,
			'--only' => $check->only ?? [],
		] as $option => $values) {
			foreach ($values as $value) {
				$command[] = "$option=$value"; // a value starting with a dash would read as an option
			}
		}

		if ($run->strictRules) {
			$command[] = '--strict-rules';
		}

		foreach ($check->fixRisky ?? [] as $value) { // what a worker may fix has to be what the parent was asked for
			$command[] = is_string($value) ? "--fix-risky=$value" : '--fix-risky';
		}

		if ($run->profile !== null) { // a worker measures and hands it over with every file, the parent writes
			$command[] = "--profile=$run->profile";
		}

		return $command;
	}


	/**
	 * Runs the check without the current baseline over code a fix leaves alone and writes what remains into the
	 * configured baseline file, or into the one of the default name the run finds by itself; code a fix would still
	 * change, or a file that fails or does not parse, is refused.
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
		$name = $config->baseline ?? Baseline::getDefaultName($configFile);
		$file = Helpers::toAbsolutePath($name, $root);
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
				$changed ? 'a fix would change ' . Markup::formatCount(count($changed), 'file') : null,
				$failed ? Markup::formatCount(count($failed), 'file') . ' failed' : null,
			]);
			$paths = [...$changed, ...$failed];
			$this->context->writeError(
				'The baseline is generated over code a fix leaves alone: ' . implode(', ', $counts) . '. Run `fix` first.',
				'suppressing#baseline',
				implode('', array_map(fn(string $path) => "  $path\n", array_slice($paths, 0, 10)))
				. (count($paths) > 10 ? '  and ' . (count($paths) - 10) . " more\n" : ''),
			);
			return 1;
		}

		$baseline = Baseline::fromResults($run->files);
		$baseline->save($file);
		$message = 'Baseline with ' . Markup::formatCount($baseline->count(), 'violation') . " written to `$name`.\n";
		// every other format keeps its stream to itself
		$format === 'console' ? $this->context->write($message) : $this->context->writeNote($message);
		return 0;
	}


	/**
	 * @param  bool  $diff  the console shows the fixes as a unified diff
	 * @param  resource  $stream  the same place as the console; a machine-readable format is no text of a console
	 * @param  string  $root  the paths of the results are relative to it
	 * @param  list<string>  $paths  the paths as the command line gave them
	 * @param  string  $command  the command whose `--ask-risky` decides the risky fixes left
	 */
	private function createReporter(
		bool $diff,
		Console $console,
		$stream,
		string $root,
		string $format,
		PluginRegistry $registry,
		Runner $runner,
		ResolvedProject $resolution,
		array $paths,
		string $command = 'fix',
	): Reporter
	{
		return match ($format) {
			'lines' => new Reporters\LinesReporter($stream, $root, Helpers::canonicalizePath($this->context->workingDirectory)),
			'agent' => new Reporters\AgentReporter(
				$stream,
				$root,
				Helpers::canonicalizePath($this->context->workingDirectory),
				givenPaths: array_combine(
					array_map(fn(string $path) => Helpers::canonicalizePath($this->context->resolvePath($path)), $paths),
					$paths,
				),
				findSuppressionExamples: function (string $path) use ($runner, $resolution): array {
					$config = $resolution->resolveFor($runner->findOverridesFor($path));
					$examples = [];
					foreach ($config->suppressionComments as $pattern => $decisions) {
						foreach (isset($config->suppressionExamples[$pattern]) ? $decisions : [] as $decision) {
							$examples[$decision][] = $config->suppressionExamples[$pattern];
						}
					}

					return $examples;
				},
			),
			'json' => new Reporters\JsonReporter($stream),
			'checkstyle' => new Reporters\CheckstyleReporter($stream),
			'github' => new Reporters\GithubReporter($stream, $root, self::findEnv('GITHUB_WORKSPACE')),
			default => new Reporters\ConsoleReporter(
				$console,
				diff: $diff,
				root: $root,
				cwd: Helpers::canonicalizePath($this->context->workingDirectory),
				findDecisionUrl: $registry->findUrl(...),
				command: $command,
			),
		};
	}


	/**
	 * The format asked for, or the one the surroundings call for: annotations when the run is a step
	 * of a GitHub Actions workflow, where nobody reads the log.
	 * @param  bool  $detect  let the surroundings decide when the command line does not
	 */
	private static function resolveFormat(?string $format, bool $detect): string
	{
		return $format ?? ($detect && self::findEnv('GITHUB_ACTIONS') === 'true' ? 'github' : 'console');
	}


	private static function findEnv(string $name): ?string
	{
		$value = getenv($name);
		return $value === false || $value === '' ? null : $value;
	}
}
