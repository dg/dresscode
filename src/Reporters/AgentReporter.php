<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Reporters;

use DressCode\{Decision, FileResult, Reporter, Risk, Rule, RuleInfo, Severity, Violation};
use DressCode\Engine\{Diff, DiffHunk, Helpers, RunInfo, RunResult};
use Nette\Utils\FileSystem;
use function count, strlen;


/**
 * Plain sentences for an AI agent that has just edited the files: what DressCode changed in them, as the diff of what
 * is already in the file, the imports it removed by name, and what is left, each with what to do about it. A clean
 * file says nothing. The lines are those of the files as they are now. The whole message keeps to a budget, and what
 * does not fit is counted.
 * @internal
 */
final class AgentReporter implements Reporter
{
	/** the message stays within both, so that a client does not cut it */
	public const MaxLines = 150;
	public const MaxBytes = 12_000;

	/** how many decisions the summary of a rewrite too long to show names */
	private const ShownDecisions = 8;

	/** @var resource */
	private $stream;
	private bool $fix = false;
	private bool $typesMissing = false;
	private int $lines = 0;
	private int $bytes = 0;


	/**
	 * @param  ?resource  $stream
	 */
	public function __construct(
		$stream = null,
		/** the paths of the results are relative to it */
		private readonly string $root = '',
		/** a file under it is named relative to it, the others absolutely */
		private readonly string $cwd = '',
		/** @var array<string, string>  canonical absolute path => the path as the command line named it */
		private readonly array $givenPaths = [],
		/**
		 * @var ?\Closure(string): array<string, list<string>>  path of a file => decision => examples of the comments that
		 * keep its violation
		 */
		private readonly ?\Closure $findSuppressionExamples = null,
	) {
		$this->stream = $stream ?? STDOUT;
	}


	public function start(RunInfo $run): void
	{
		$this->fix = $run->fix;
		$this->typesMissing = $run->typesMissing;
		$this->lines = $this->bytes = 0;
	}


	public function reportFile(FileResult $result): void
	{
		$path = '`' . $this->formatPath($result->path) . '`';
		$paragraphs = [];
		if ($result->failure !== null) {
			$paragraphs[] = "DressCode failed on $path and left the file as it was: " . strtok($result->failure, "\n");
		}

		if ($result->syntaxError !== null) {
			$paragraphs[] = "$path has a syntax error on line " . ($result->syntaxErrorLine ?? 1)
				. ', so DressCode left it as it was: ' . self::formatSentence($result->syntaxError) . '.';
		}

		$rewritten = $this->fix && $result->written;
		if ($rewritten) {
			$imports = array_map(self::describeRemovedImport(...), $result->removedImports);
			$paragraphs[] = "DressCode changed $path; the changes below are already in the file:"
				. ($imports ? "\n\n" . implode("\n", $imports) : '');
		}

		$violations = $this->fix ? $result->remaining : $result->violations;
		$listing = $violations ? $this->describeViolations($violations, $path, $result->path) : [];
		if ($result->leftDecisions) {
			$listing[] = self::describeLeftDecisions($result->leftDecisions, $path);
		} elseif ($result->unlisted) {
			$one = $result->unlisted === 1;
			$further = $violations ? ' further' : '';
			$listing[] = ($one ? "1$further violation" : "$result->unlisted$further violations") . ($violations ? '' : " in $path")
				. ($one ? ' is' : ' are') . ' outside the edited lines; ' . ($one ? 'it is' : 'they are') . ' not listed here.';
		}

		foreach ($result->warnings as $warning) {
			$listing[] = "DressCode warns about $path: " . self::formatSentence($warning) . '.';
		}

		if ($rewritten) {
			// what is left to do is worth more than the diff, so the diff takes what the rest leaves of the budget
			$hunks = Diff::computeHunks($result->code, (string) $result->output);
			$diff = $this->formatDiff($hunks);
			[$lines, $bytes] = self::measure([...$paragraphs, ...$listing, $diff]);
			$paragraphs[] = $this->lines + $lines <= self::MaxLines && $this->bytes + $bytes <= self::MaxBytes
				? $diff
				: self::summarizeRewrite(count($hunks), $result->mutatedRules);
		}

		$paragraphs = [...$paragraphs, ...$listing];
		if ($paragraphs) {
			$this->write(implode("\n\n", $paragraphs));
		}
	}


	public function finish(RunResult $result): void
	{
		if ($this->lines && $this->typesMissing) {
			$this->write('The checks that need the types of the code did not run, the configuration having no `typeAnalysis: phpstan`.');
		}
	}


	/**
	 * What is left, an item per violation with what to do about it, as far as the budget lets.
	 * @param  list<Violation>  $violations
	 * @return list<string>
	 */
	private function describeViolations(array $violations, string $path, string $file): array
	{
		$examples = $this->findSuppressionExamples === null ? [] : ($this->findSuppressionExamples)($file);
		$byFingerprint = array_column($violations, null, 'fingerprint');
		$followers = $listed = [];
		foreach ($violations as $violation) {
			$ancestor = $byFingerprint[$violation->derivedFrom ?? ''] ?? null;
			if ($ancestor !== null && $ancestor->severity === $violation->severity && $ancestor->risk === $violation->risk) {
				$followers[$ancestor->fingerprint][] = $violation->line;
			} else {
				$listed[] = $violation;
			}
		}

		$items = [];
		$automatic = 0;
		[$lines, $bytes] = [$this->lines + 2, $this->bytes];
		foreach ($listed as $i => $violation) {
			$item = $this->describeViolation($violation, $examples[$violation->decision] ?? [], $followers[$violation->fingerprint] ?? []);
			[$itemLines, $itemBytes] = self::measure([$item]);
			if ($lines + $itemLines > self::MaxLines || $bytes + $itemBytes > self::MaxBytes) {
				$items[] = Helpers::formatCount(count($listed) - $i, 'further violation is', 'further violations are')
					. ' not listed here.';
				break;
			}

			$items[] = $item;
			[$lines, $bytes] = [$lines + $itemLines, $bytes + $itemBytes + 1];
			$automatic += !$this->fix && $violation->fixable && $violation->risk === null ? 1 : 0;
		}

		$heading = $this->fix ? "Left in $path, at the lines of the file as it is now:" : "Found in $path:";
		return [$heading
			. "\n"
			. implode("\n", $items)
			. ($automatic ? "\n\nDressCode fixes $automatic of them itself when run as `fix`." : '')];
	}


	/**
	 * @param  list<string>  $examples  comments the project accepts as the reason to keep the code
	 * @param  list<int>  $followers  lines of the violations that follow from this one
	 */
	private function describeViolation(Violation $violation, array $examples, array $followers): string
	{
		$text = '- line ' . $violation->line . ($violation->severity === Severity::Warning ? ' (warning)' : '') . ': '
			. self::formatSentence($violation->message) . " (`$violation->decision`).";
		if ($followers) {
			$text .= ' Fixing it settles ' . count($followers) . ' more on line' . (count($followers) > 1 ? 's ' : ' ')
				. implode(', ', array_unique($followers)) . '.';
		}

		$keep = $examples
			? 'where the current code is intended and you know why, keep it with a comment on its line, '
				. implode(' or ', array_map(fn(string $example) => "`$example`", $examples))
				. (array_filter($examples, fn(string $example) => str_contains($example, '<why>'))
					? ', giving the real reason for `<why>`'
					: '')
				. '; '
			: '';
		if ($violation->risk !== null) {
			$text .= ' Not fixed, because ' . ($violation->because ?? match ($violation->risk) {
				Risk::TypeUnknown => 'the type of the value is unknown',
				Risk::NameUncertain => 'the name may reach a function or a constant of the namespace',
				Risk::BehaviorChanges => 'the fix may change what the code does',
			}) . '. ' . ($violation->risk->isResolvable()
				? 'Change it where you have verified that the intended behaviour stays the same'
				: 'Change it only where the task asks for the behaviour the fix gives')
				. '; ' . $keep . 'otherwise tell the user.';
		} elseif (!$violation->fixable && $keep !== '') {
			$text .= ' Fix it; ' . $keep . 'otherwise tell the user.';
		}

		return $text;
	}


	/**
	 * The decisions the file did not follow before the edit, which the run leaves as the file writes them.
	 * @param  non-empty-list<string>  $decisions
	 */
	private static function describeLeftDecisions(array $decisions, string $path): string
	{
		$shown = array_map(fn(string $decision) => "`$decision`", array_slice($decisions, 0, self::ShownDecisions));
		$more = count($decisions) - count($shown);
		$names = implode(', ', $shown) . ($more ? " and $more more" : '');
		$one = count($decisions) === 1;
		return "Before the edit $path did not follow $names, so DressCode leaves "
			. ($one ? 'it' : 'them')
			. ' as the file writes '
			. ($one ? 'it' : 'them')
			. '; write the code there in the style of the rest of the file.';
	}


	/** @param  array{kind: 'class'|'function'|'const', name: string, alias: ?string, namespace: string}  $import */
	private static function describeRemovedImport(array $import): string
	{
		$kind = match ($import['kind']) {
			'function' => 'function ',
			'const' => 'constant ',
			default => '',
		};
		return "- removed the import of $kind`{$import['name']}`" . ($import['alias'] === null ? '' : " as `{$import['alias']}`")
			. ': nothing used it; if you meant to use it, add the import in the same edit as the use.';
	}


	/** @param  list<DiffHunk>  $hunks */
	private function formatDiff(array $hunks): string
	{
		$lines = [];
		foreach ($hunks as $hunk) {
			array_push($lines, $hunk->header, ...$hunk->lines);
		}

		// a fence longer than any run of backticks in the code, which would otherwise close it
		preg_match_all('~`+~', implode("\n", $lines), $m);
		$fence = str_repeat('`', max(3, ...array_map(fn(string $run) => strlen($run) + 1, $m[0] ?: [''])));
		return "{$fence}diff\n" . implode("\n", $lines) . "\n$fence";
	}


	/**
	 * A rewrite too long to show, by its size and the decisions whose rules made it.
	 * @param  list<class-string<Rule>>  $rules
	 */
	private static function summarizeRewrite(int $places, array $rules): string
	{
		$decisions = [];
		foreach ($rules as $rule) {
			array_push(
				$decisions,
				...array_map(fn(Decision $decision) => $decision->path, $rule::getDecisions()),
				...RuleInfo::fromRule($rule)->decisions,
			);
		}

		$decisions = array_values(array_unique($decisions));
		$shown = array_map(fn(string $decision) => "`$decision`", array_slice($decisions, 0, self::ShownDecisions));
		$more = count($decisions) - count($shown);
		return 'The changes, in ' . Helpers::formatCount($places, 'place')
			. ', are too long to show here: read the file again before you edit it.'
			. ($shown
				? ' They come from the rules of ' . implode(', ', $shown) . ($more ? " and $more more" : '')
					. ', an indication rather than an exact account.'
				: '');
	}


	/** A message as a part of a sentence: its first word in lower case unless it is a name, without the final period. */
	private static function formatSentence(string $message): string
	{
		$message = rtrim(trim((string) preg_replace('~\s*\n\s*~', ' ', $message)), '.');
		return preg_match('~^[A-Z](?![A-Z\d_])~', $message) ? lcfirst($message) : $message;
	}


	/**
	 * The lines and bytes of the paragraphs written as one message.
	 * @param  list<string>  $paragraphs
	 * @return array{int, int}
	 */
	private static function measure(array $paragraphs): array
	{
		$text = implode("\n\n", array_filter($paragraphs, fn(string $paragraph) => $paragraph !== ''));
		return [substr_count($text, "\n") + 2, strlen($text) + 2];
	}


	/** As the command line named the file, or relative to the working directory when it lies under it. */
	private function formatPath(string $path): string
	{
		// a path outside the root is absolute already
		$path = $this->root === '' || FileSystem::isAbsolute($path) ? $path : "$this->root/$path";
		if (isset($this->givenPaths[$path])) {
			return $this->givenPaths[$path];
		} elseif ($this->cwd !== '' && str_starts_with($path, "$this->cwd/")) {
			$path = substr($path, strlen($this->cwd) + 1);
		}

		return strtr($path, '\\', '/');
	}


	/** A message of its own, set apart from the previous one by a blank line. */
	private function write(string $text): void
	{
		[$lines, $bytes] = self::measure([$text]);
		$this->lines += $lines;
		$this->bytes += $bytes;
		fwrite($this->stream, ($this->lines > $lines ? "\n" : '') . "$text\n");
	}
}
