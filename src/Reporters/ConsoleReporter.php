<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Reporters;

use DressCode\Console\Markup;
use DressCode\Engine\{Diff, FileSummary, RunInfo, RunResult, TypeAnalysisStatus};
use DressCode\{FileResult, Reporter, Risk, Severity, Violation};
use Nette\CommandLine\{Ansi, Console};
use Nette\Utils\FileSystem;
use function count, sprintf, strlen;


/**
 * Human-readable listing: what the user has to deal with, grouped by file and optionally with a diff of the
 * fixes, then the verdict. A fix run lists what the fixed text still violates and says of a file it changed
 * that it rewrote it.
 * @internal
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

	private TypeAnalysisStatus $typeAnalysis = TypeAnalysisStatus::Unavailable;

	/** the configuration lists functions or constants the namespaces declare */
	private bool $namespacesListed = false;
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
		/** @var ?\Closure(string): ?string  the path of a decision => the address of its page */
		private readonly ?\Closure $findDecisionUrl = null,
	) {
		$this->diff = $diff && !$bare;
	}


	public function start(RunInfo $run): void
	{
		$this->fix = $run->fix;
		$this->fileCount = $run->fileCount;
		$this->typeAnalysis = $run->typeAnalysis;
		$this->namespacesListed = $run->namespacesListed;
		$this->started = microtime(as_float: true);
		$this->listed = $this->separate = false;
	}


	public function reportFile(FileResult $result): void
	{
		$violations = $this->fix ? $result->remaining : $result->violations;
		$rewritten = $this->fix && $result->changed;
		if (
			!$violations
			&& !$rewritten
			&& !$result->warnings
			&& $result->syntaxError === null
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

		if ($result->syntaxError !== null) {
			$position = $result->syntaxErrorLine === null ? '' : "$result->syntaxErrorLine  ";
			$this->write('  ' . Markup::highlightCode($this->console, $position . 'Syntax error: ' . $result->syntaxError, 'red') . "\n");
		}

		$this->writeViolations($violations);
		foreach ($result->warnings as $warning) {
			$this->write('  ' . Markup::highlightCode($this->console, $warning, 'yellow') . "\n");
		}

		if ($this->diff && $result->changed) {
			$this->write(Markup::highlightDiff($this->console, Diff::unified($result->code, (string) $result->output, $result->path)));
		}

		// a file with nothing below its name stays a single line, the others are set apart
		$this->separate = $violations || $result->warnings || $result->syntaxError !== null
			|| $result->failure !== null || ($this->diff && $result->changed);
	}


	/**
	 * The positions belong to the text the violations were found in: the file as it was read in a check, the
	 * fixed text in a fix. A violation that follows from another one is a note under it rather than a line of its
	 * own, unless the two differ in state, when it must not hide behind a state that is not its own. The bare
	 * format folds nothing.
	 * @param  list<Violation>  $violations
	 */
	private function writeViolations(array $violations): void
	{
		if (!$violations) {
			return;
		}

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

		$notes = [];
		foreach ($derived as $fingerprint => $followers) {
			$notes[$fingerprint] = self::describeDerived($byFingerprint[$fingerprint], $followers);
		}

		$show = fn(Violation $v) => Markup::highlightCode($this->console, $v->message);
		$showNote = fn(string $text) => Markup::highlightCode($this->console, $text, 'gray');
		$widths = [0, ...array_map(fn(Violation $v) => Ansi::measure($show($v)), $listed)];
		foreach ($notes as $rows) {
			foreach ($rows as [$text]) {
				$widths[] = Ansi::measure($showNote($text));
			}
		}

		$positionWidth = max(array_map(fn(Violation $v) => Ansi::measure(self::formatPosition($v)), $violations));
		$messageWidth = min(self::MessageWidth, max($widths));
		$stateWidth = max(array_map(fn(Violation $v) => Ansi::measure(self::formatState($v)), $violations));
		$formatDecision = fn(string $path) => $this->console->color('gray', Markup::formatDecision(
			$this->console,
			$path,
			$this->findDecisionUrl === null ? null : ($this->findDecisionUrl)($path),
		));

		foreach ($listed as $violation) {
			$state = self::formatState($violation);
			$this->write(sprintf(
				"  %s  %s  %s  %s\n",
				$this->console->color($violation->severity === Severity::Warning ? 'olive' : 'maroon', Ansi::pad($state, $stateWidth)),
				$this->console->color('gray', Ansi::pad(self::formatPosition($violation), $positionWidth, STR_PAD_LEFT)),
				Ansi::pad($show($violation), $messageWidth),
				$formatDecision($violation->decision),
			));
			$indent = str_repeat(' ', $stateWidth + $positionWidth + 6);
			if ($violation->because !== null) {
				$this->write($indent . $showNote("Risky because $violation->because.") . "\n");
			}

			foreach ($notes[$violation->fingerprint] ?? [] as [$text, $decision]) {
				$this->write($indent . Ansi::pad($showNote($text), $messageWidth) . '  ' . $formatDecision($decision) . "\n");
			}
		}
	}


	/**
	 * What fixing a violation brings, a message per row with its decision, and the lines where they are not the line of
	 * the violation: `Then also, on lines 37, 38, 39: Expected the statement indented by 2 tabs, 1 tab found.`
	 * @param  list<Violation>  $followers
	 * @return list<array{string, string}>
	 */
	private static function describeDerived(Violation $ancestor, array $followers): array
	{
		$lines = [];
		foreach ($followers as $violation) {
			$lines[$violation->decision][$violation->message][$violation->line] = true;
		}

		$rows = [];
		foreach ($lines as $decision => $messages) {
			foreach ($messages as $message => $messageLines) {
				$numbers = array_keys($messageLines);
				sort($numbers);
				$where = match (true) {
					$numbers === [$ancestor->line] => '',
					count($numbers) === 1 => ", on line $numbers[0]",
					count($numbers) <= 4 => ', on lines ' . implode(', ', $numbers),
					default => sprintf(', on %d lines from %d to %d', count($numbers), $numbers[0], end($numbers)),
				};
				$rows[] = ["Then also$where: $message", $decision];
			}
		}

		return $rows;
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
		$risks = array_filter(Risk::cases(), fn(Risk $risk) => $result->countRefusedBy($risk) > 0);
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
		$count = $result->countRefusedBy($risk);
		[$wait, $them, $decisions] = $count === 1
			? ['1 risky fix waits', 'it', 'its decision']
			: ["$count risky fixes wait", 'them', 'their decisions'];
		$text = match ($risk) {
			Risk::NameUncertain => "$wait, a name may reach a function of the namespace: " . ($this->namespacesListed
				? 'set `nameResolution: certain`'
				: 'run `dresscode init`, which lists what the namespaces declare, or set `nameResolution: certain` if they declare nothing'),
			Risk::TypeUnknown => match ($this->typeAnalysis) {
				TypeAnalysisStatus::Available => "$wait, the type is unknown: set `typeAnalysis: phpstan`",
				TypeAnalysisStatus::Enabled => "$wait, not even the types tell: check $them with `fix --ask-risky`",
				TypeAnalysisStatus::Unavailable => "$wait, the type is unknown: check $them with `fix --ask-risky`",
			},
			Risk::BehaviorChanges => ($count === 1 ? '1 risky fix changes' : "$count risky fixes change")
				. " what the code does: decide $them with `fix --ask-risky`, or accept $decisions in `fixRisky`",
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
		$remaining = $result->countReported(Severity::Error);
		$warnings = $result->countReported(Severity::Warning);
		$found = $result->countViolations(Severity::Error);
		$left = $result->countRemaining();
		$changed = $this->fix ? $result->countChangedFiles() : 0;
		$failures = $result->countFailures();
		$affected = count(array_filter(
			$result->files,
			fn(FileSummary $f) => $f->violations || $f->syntaxError !== null || $f->failure !== null,
		));

		$derived = $result->countDerived(Severity::Error);
		$parts = array_filter([
			match (true) {
				$this->fix && ($found || $remaining) => self::formatCount($found, 'violation') . ' found, ' . ($remaining ?: 'none') . ' remaining',
				!$this->fix && $remaining > 0 => self::formatCount($remaining, 'violation'),
				default => null,
			},
			$derived ? "$derived of them following from others" : null,
			$warnings ? self::formatCount($warnings, 'warning') : null,
			!$this->fix && $left !== $result->countViolations() ? 'a fix leaves ' . ($left ?: 'none') : null,
			$result->countSyntaxErrors() ? self::formatCount($result->countSyntaxErrors(), 'syntax error') : null,
			$failures ? self::formatCount($failures, 'failed file') : null,
			$result->baselined ? self::formatCount($result->baselined, 'violation') . ' in the baseline' : null,
		]);

		$state = match (true) {
			$failures > 0 => 'FAILED',
			$changed > 0 => 'FIXED',
			$remaining > 0 || $warnings > 0 || $result->countSyntaxErrors() > 0 => 'FOUND',
			default => 'OK',
		};
		$scope = $affected > 0 && $affected < $this->fileCount
			? sprintf('%d of %s', $affected, self::formatCount($this->fileCount, 'file'))
			: self::formatCount($this->fileCount, 'file');
		$elapsed = microtime(as_float: true) - $this->started;

		$summary = $parts
			? implode(', ', $parts) . " in $scope"
			: self::formatCount($this->fileCount, 'file') . ', ' . ($this->fileCount > 1 ? 'all ' : '') . 'up to the dress code';
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
		return match (true) {
			$violation->refused => 'risky',
			$violation->severity === Severity::Warning => 'warning',
			default => 'error',
		};
	}


	private static function formatPosition(Violation $violation): string
	{
		return $violation->line . ($violation->column === null ? '' : ":$violation->column");
	}


	private static function formatCount(int $count, string $noun): string
	{
		return $count . ' ' . $noun . ($count === 1 ? '' : 's');
	}


	private function write(string $text): void
	{
		$this->console->write($text);
	}
}
