<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use PhpSyntax\{Node, Token, Trivia};
use function count, strval;


/**
 * The identity of the violations of one file while they are being reported: the fingerprint of a report and
 * whether the baseline knows it. The place of a violation among those of its rule on a line is kept across the
 * passes, so that a pass repeating a report of the pass before arrives at the same fingerprint and adds no violation.
 * @internal
 */
final class Fingerprints
{
	/** @var array<string, int>  rule\nmessage\nline content => occurrences so far in the pass */
	private array $occurrences = [];

	/** @var array<string, array<string, int>>  rule\nline content => message\noccurrence => its place among them, kept over the passes */
	private array $places = [];

	/** @var array<string, true>  fingerprints the baseline silenced */
	private array $silenced = [];

	/** @var \WeakMap<Node, array<string, array{Node|Token, ?Trivia, ?string}>>  a construct => rule => the place of its violation in this pass and its fingerprint */
	private \WeakMap $constructs;


	public function __construct(
		/** @var list<string>  lines of the original file */
		private readonly array $lines,
		private readonly string $path,
		private readonly ?Baseline $baseline = null,
	) {
		$this->constructs = new \WeakMap;
	}


	/** A new pass counts the occurrences from the start; what the baseline silenced stays known. */
	public function startPass(): void
	{
		$this->occurrences = [];
		$this->constructs = new \WeakMap;
	}


	/**
	 * The identity of the violation about to be reported; every call counts as one occurrence of its message. The
	 * message tells the violations of the rule on the line apart while the file is processed, a pass repeating one
	 * arriving at the same identity, but is not part of it: the violations of the rule on the line are numbered in the
	 * order they first come, so that a message worded otherwise by a newer version keeps what the baseline knows.
	 */
	public function create(string $ruleName, string $message, int $line): string
	{
		$content = self::normalizeLineContent($this->lines[$line - 1] ?? '');
		$key = "$ruleName\n$message\n$content";
		$this->occurrences[$key] = ($this->occurrences[$key] ?? 0) + 1;
		$group = "$ruleName\n$content";
		$place = $this->places[$group]["$message\n{$this->occurrences[$key]}"] ??= count($this->places[$group] ?? []) + 1;
		return self::createFingerprint($ruleName, $content, $place);
	}


	/** @param string $lineContent  normalized by `normalizeLineContent()` */
	public static function createFingerprint(string $ruleName, string $lineContent, int $occurrence): string
	{
		return hash('xxh3', "$ruleName\n$lineContent\n$occurrence");
	}


	/**
	 * Line content as the fingerprint sees it: trimmed, whitespace collapsed.
	 */
	public static function normalizeLineContent(string $content): string
	{
		return (string) preg_replace('~\s+~', ' ', trim($content));
	}


	/**
	 * Where the violation of the rule about the construct stands in this pass: at the first report of it, which
	 * the place given becomes when there has been none.
	 * @return array{Node|Token, ?Trivia}
	 */
	public function placeConstruct(Node $construct, string $ruleName, Node|Token $at, ?Trivia $trivia): array
	{
		$reports = $this->constructs[$construct] ?? [];
		$reports[$ruleName] ??= [$at, $trivia, null];
		$this->constructs[$construct] = $reports;
		return [$reports[$ruleName][0], $reports[$ruleName][1]];
	}


	/**
	 * The identity of the violation about a construct: the first report of the rule about it in the pass counts
	 * as an occurrence, the ones after it are the same violation.
	 */
	public function createFor(Node $construct, string $ruleName, string $message, int $line): string
	{
		$reports = $this->constructs[$construct] ?? [];
		$report = $reports[$ruleName] ?? [$construct, null, null];
		$report[2] ??= $this->create($ruleName, $message, $line);
		$reports[$ruleName] = $report;
		$this->constructs[$construct] = $reports;
		return $report[2];
	}


	/** Whether the baseline knows the violation, which is then not recorded. */
	public function isKnown(string $fingerprint): bool
	{
		if ($this->baseline?->knows($this->path, $fingerprint) !== true) {
			return false;
		}

		$this->silenced[$fingerprint] = true;
		return true;
	}


	/** @return list<string>  the fingerprints the baseline silenced, each once */
	public function getSilenced(): array
	{
		// a fingerprint of nothing but digits comes back from the array key as an int
		return array_map(strval(...), array_keys($this->silenced));
	}
}
