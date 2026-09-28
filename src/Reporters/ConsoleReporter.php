<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Reporters;

use DressCode\Config\RuleRegistry;
use DressCode\Console\Markup;
use DressCode\Engine\Diff;
use DressCode\{FileResult, Reporter, Risk, RunResult, Severity, Violation};
use Nette\CommandLine\{Ansi, Console};
use Nette\Utils\FileSystem;
use function array_slice, count, sprintf, strlen;


/**
 * Human-readable listing: what the user has to deal with, grouped by file and optionally with a diff of the
 * fixes, then the verdict. A fix run lists what the fixed text still violates and says of a file it changed
 * that it rewrote it.
 */
final class ConsoleReporter implements Reporter
{
	/** the message column follows the longest message but never grows past this */
	private const MessageWidth = 100;

	/** a run shorter than this is not worth timing */
	private const LongRun = 1.0;

	/** the bare format has no room for it */
	private readonly bool $diff;

	private bool $fix = false;
	private int $fileCount = 0;
	private float $started = 0.0;

	/** some file was listed, so the verdict needs a blank line above it */
	private bool $listed = false;

	/** the file before this one printed lines under its name and wants a blank line after it */
	private bool $separate = false;


	public function __construct(
		private readonly Console $console,
		bool $diff = false,
		/** the paths of the results are relative to it */
		private readonly string $root = '',
		/** a file under it is reported relative to it, the others absolutely */
		private readonly string $cwd = '',
		/** only what is left to the user and which files were rewritten, nothing else */
		private readonly bool $bare = false,
	) {
		$this->diff = $diff && !$bare;
	}


	public function start(int $fileCount, bool $fix): void
	{
		$this->fix = $fix;
		$this->fileCount = $fileCount;
		$this->started = microtime(as_float: true);
		$this->listed = $this->separate = false;
	}


	public function reportFile(FileResult $result): void
	{
		$violations = $this->fix ? $result->remaining : $result->violations;
		$rewritten = $this->fix && $result->isChanged();
		if (
			!$violations
			&& !$rewritten
			&& !$result->warnings
			&& $result->error === null
			&& $result->failure === null
		) {
			return;
		}

		if ($this->separate) {
			$this->write("\n");
		}

		$this->listed = true;
		$this->write($this->console->color('white', $this->formatPath($result->path))
			. ($rewritten ? $this->console->color('gray', '  rewritten') : '') . "\n");
		if ($result->failure !== null) {
			[$message, $diff] = explode("\n", $result->failure, 2) + [1 => ''];
			$this->write('  ' . Markup::highlightCode($this->console, $message, 'red') . "\n" . Markup::highlightDiff($this->console, $diff));
			if ($result->failureDocs !== null) {
				$this->write('  ' . Markup::formatDocsLink($this->console, $result->failureDocs) . "\n");
			}
		}

		if ($result->error !== null) {
			$position = $result->errorLine === null ? '' : "$result->errorLine  ";
			$this->write('  ' . Markup::highlightCode($this->console, $position . $result->error, 'red') . "\n");
		}

		$this->writeViolations($violations);
		foreach ($result->warnings as $warning) {
			$this->write('  ' . Markup::highlightCode($this->console, $warning, 'yellow') . "\n");
		}

		if ($this->diff && $result->isChanged()) {
			$this->write(Markup::highlightDiff($this->console, Diff::unified($result->code, (string) $result->output, $result->path)));
		}

		// a file with nothing below its name stays a single line, the others are set apart
		$this->separate = $violations || $result->warnings || $result->error !== null
			|| $result->failure !== null || ($this->diff && $result->isChanged());
	}


	/**
	 * The positions belong to the text the violations were found in: the file as it was read in a check, the
	 * fixed text in a fix. A violation that follows from another one is a note under it rather than a line of its
	 * own, unless the two differ in severity, when it must not hide behind a state that is not its own. The bare
	 * format folds nothing.
	 * @param  list<Violation>  $violations
	 */
	private function writeViolations(array $violations): void
	{
		if (!$violations) {
			return;
		}

		$positionWidth = max(array_map(fn(Violation $v) => Ansi::measure(self::formatPosition($v)), $violations));
		$show = fn(Violation $v) => Markup::highlightCode($this->console, $v->message);
		$messageWidth = min(self::MessageWidth, max(array_map(fn(Violation $v) => Ansi::measure($show($v)), $violations)));
		$stateWidth = max(array_map(fn(Violation $v) => Ansi::measure(self::formatState($v)), $violations));

		$derived = [];
		$listed = [];
		$byFingerprint = $this->bare ? [] : array_column($violations, null, 'fingerprint');
		foreach ($violations as $violation) {
			$ancestor = $byFingerprint[$violation->derivedFrom ?? ''] ?? null;
			if ($ancestor !== null && self::formatState($ancestor) === self::formatState($violation)) {
				$derived[$ancestor->fingerprint][] = $violation;
			} else {
				$listed[] = $violation;
			}
		}

		foreach ($listed as $violation) {
			$state = self::formatState($violation);
			$this->write(sprintf(
				"  %s  %s  %s  %s\n",
				$this->console->color($state === 'warning' ? 'olive' : 'maroon', Ansi::pad($state, $stateWidth)),
				$this->console->color('gray', Ansi::pad(self::formatPosition($violation), $positionWidth, STR_PAD_LEFT)),
				Ansi::pad($show($violation), $messageWidth),
				$this->console->color('gray', RuleRegistry::abbreviate($violation->ruleName)),
			));
			$indent = str_repeat(' ', $stateWidth + $positionWidth + 6);
			if ($violation->because !== null) {
				$this->write($indent . Markup::highlightCode($this->console, $violation->because, 'gray') . "\n");
			}

			if (isset($derived[$violation->fingerprint])) {
				$this->write($indent . $this->console->color('gray', self::describeDerived($derived[$violation->fingerprint])) . "\n");
			}
		}
	}


	/**
	 * What follows from a violation, by rule and line: `followed by indentation on lines 37, 38, 39`.
	 * @param  list<Violation>  $violations
	 */
	private static function describeDerived(array $violations): string
	{
		$lines = [];
		foreach ($violations as $violation) {
			$lines[$violation->ruleName][$violation->line] = true;
		}

		$parts = [];
		foreach ($lines as $rule => $ruleLines) {
			$numbers = array_keys($ruleLines);
			sort($numbers);
			$parts[] = RuleRegistry::abbreviate($rule) . ' on ' . match (true) {
				count($numbers) === 1 => "line $numbers[0]",
				count($numbers) <= 4 => 'lines ' . implode(', ', $numbers),
				default => sprintf('%d lines from %d to %d', count($numbers), $numbers[0], end($numbers)),
			};
		}

		return 'followed by ' . (count($parts) === 1
			? $parts[0]
			: implode(', ', array_slice($parts, 0, -1)) . ' and ' . end($parts));
	}


	public function finish(RunResult $result): void
	{
		if ($this->bare || $this->fileCount === 0) { // an empty scope is the header's business, not a verdict
			return;
		}

		if ($this->listed) {
			$this->write("\n");
		}

		foreach ($result->warnings as $warning) {
			$this->write(Markup::highlightCode($this->console, "Warning: $warning", 'yellow') . "\n\n");
		}

		// the fixes left for want of consent, by what would decide them, each with how its risk is taken away
		$risks = array_filter(Risk::cases(), fn(Risk $risk) => $result->countRiskyDeferredBy($risk) > 0);
		foreach ($risks as $risk) {
			$this->write($this->formatRefused($risk, $result) . "\n");
		}

		if ($risks) {
			$this->write("\n");
		}

		$this->write($this->console->color(
			match (true) {
				$result->getExitCode() !== 0 => 'white/red',
				$this->fix && $result->countChangedFiles() > 0 => 'white/blue',
				default => 'white/green',
			},
			$this->formatVerdict($result),
		) . "\n");
	}


	/**
	 * The line of the summary for the fixes the run refused for the risk: how many, why, how the risk is taken away,
	 * and the page of the manual that says so.
	 */
	private function formatRefused(Risk $risk, RunResult $result): string
	{
		$count = $result->countRiskyDeferredBy($risk);
		$wait = $count === 1 ? '1 risky fix waits' : "$count risky fixes wait";
		$text = match ($risk) {
			Risk::NameUncertain => "$wait, a name may reach a function of the namespace: " . ($result->namespacesListed
				? 'set `nameResolution: certain`'
				: 'run `dresscode init`, which lists what the namespaces declare, or set `nameResolution: certain` if they declare nothing'),
			Risk::TypeUnknown => match ($result->types) {
				false => "$wait, the type is unknown: set `types: phpstan`",
				true => "$wait, not even the types tell: check them by hand",
				null => "$wait, the type is unknown: check them by hand",
			},
			Risk::BehaviorChanges => ($count === 1 ? '1 risky fix changes' : "$count risky fixes change")
				. ' what the code does: decide them in `fixRisky` or by hand',
		};
		$docs = match ($risk) {
			Risk::TypeUnknown => 'types#enable',
			Risk::NameUncertain => 'namespaces#name-resolution',
			Risk::BehaviorChanges => 'configuration#risky-fixes',
		};
		return Markup::highlightCode($this->console, "$text.") . ' ' . Markup::formatDocsLink($this->console, $docs);
	}


	/**
	 * The state of the run, then every count with the noun it counts, then the scope it all happened in. A fix
	 * says how many violations it found and how many the fixed text still has, which are the two numbers the
	 * text can tell; a check says how many a fix would leave. A run with nothing to count names only the scope.
	 */
	private function formatVerdict(RunResult $result): string
	{
		$remaining = $result->countRemaining(Severity::Error);
		$warnings = $result->countRemaining(Severity::Warning);
		$found = $result->countViolations(Severity::Error);
		$left = $result->countLeftAfterFix();
		$changed = $this->fix ? $result->countChangedFiles() : 0;
		$failures = $result->countFailures();
		$affected = count(array_filter(
			$result->files,
			fn(FileResult $f) => $f->violations || $f->error !== null || $f->failure !== null,
		));

		$derived = $result->countDerived(Severity::Error);
		$parts = array_filter([
			match (true) {
				$this->fix && ($found || $remaining) => self::plural($found, 'violation') . ' found, ' . ($remaining ?: 'none') . ' remaining',
				!$this->fix && $remaining > 0 => self::plural($remaining, 'violation'),
				default => null,
			},
			$derived ? "$derived of them following from others" : null,
			$warnings ? self::plural($warnings, 'warning') : null,
			!$this->fix && $left !== $result->countViolations() ? 'a fix leaves ' . ($left ?: 'none') : null,
			$result->countSyntaxErrors() ? self::plural($result->countSyntaxErrors(), 'file') . ' with syntax errors' : null,
			$failures ? self::plural($failures, 'failed file') : null,
			$result->baselined ? self::plural($result->baselined, 'violation') . ' in the baseline' : null,
		]);

		$state = match (true) {
			$failures > 0 => 'FAILED',
			$changed > 0 => 'FIXED',
			$remaining > 0 || $warnings > 0 || $result->countSyntaxErrors() > 0 => 'FOUND',
			default => 'OK',
		};
		$scope = $affected > 0 && $affected < $this->fileCount
			? sprintf('%d of %s', $affected, self::plural($this->fileCount, 'file'))
			: self::plural($this->fileCount, 'file');
		$elapsed = microtime(as_float: true) - $this->started;

		$summary = $parts
			? implode(', ', $parts) . " in $scope"
			: self::plural($this->fileCount, 'file') . ', ' . ($this->fileCount > 1 ? 'all ' : '') . 'up to the dress code';
		return "$state  $summary" . ($elapsed < self::LongRun ? '' : sprintf(', %.1f s', $elapsed));
	}


	/**
	 * Relative to the working directory when the file lies under it, absolute otherwise, so that the path
	 * can be clicked and copied wherever the run was started from.
	 */
	private function formatPath(string $path): string
	{
		// a path outside the root is absolute already
		$path = $this->root === '' || FileSystem::isAbsolute($path) ? $path : "$this->root/$path";
		if ($this->cwd !== '' && str_starts_with($path, "$this->cwd/")) {
			$path = substr($path, strlen($this->cwd) + 1);
		}

		return FileSystem::platformSlashes($path);
	}


	private static function formatState(Violation $violation): string
	{
		return $violation->severity === Severity::Warning ? 'warning' : 'error';
	}


	private static function formatPosition(Violation $violation): string
	{
		return $violation->line . ($violation->column === null ? '' : ":$violation->column");
	}


	private static function plural(int $count, string $noun): string
	{
		return $count . ' ' . $noun . ($count === 1 ? '' : 's');
	}


	private function write(string $text): void
	{
		$this->console->write($text);
	}
}
