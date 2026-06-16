<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use PhpSyntax\{Node, Token, Trivia};
use function count;


/**
 * The identity of the violations of one file while they are being reported: the fingerprint of a report. The place
 * of a violation among those of its decision on a line is kept across the passes, so that a pass repeating a report
 * of the pass before arrives at the same fingerprint and adds no violation.
 * @internal
 */
final class Fingerprints
{
	/** @var array<string, int>  decision\nmessage\nline content => occurrences so far in the pass */
	private array $occurrences = [];

	/** @var array<string, array<string, int>>  decision\nline content => message\noccurrence => its place among them, kept over the passes */
	private array $places = [];

	/** @var \WeakMap<Node, array<string, array{at: Node|Token, trivia: ?Trivia, fingerprint: ?string}>>  a construct => decision => the place of its violation in this pass and its fingerprint */
	private \WeakMap $constructs;


	public function __construct(
		/** @var list<string>  lines of the original file */
		private readonly array $lines,
	) {
		$this->constructs = new \WeakMap;
	}


	/** A new pass counts the occurrences from the start. */
	public function beginPass(): void
	{
		$this->occurrences = [];
		$this->constructs = new \WeakMap;
	}


	/**
	 * The identity of the violation about to be reported; every call counts as one occurrence of its message. The
	 * message tells the violations of the decision on the line apart while the file is processed, a pass repeating one
	 * arriving at the same identity, but is not part of it: the violations of the decision on the line are numbered in the
	 * order they first come, so that a message worded otherwise by a newer version keeps its identity.
	 */
	public function create(string $decision, string $message, int $line): string
	{
		$content = self::normalizeLineContent($this->lines[$line - 1] ?? '');
		$key = "$decision\n$message\n$content";
		$this->occurrences[$key] = ($this->occurrences[$key] ?? 0) + 1;
		$group = "$decision\n$content";
		$place = $this->places[$group]["$message\n{$this->occurrences[$key]}"] ??= count($this->places[$group] ?? []) + 1;
		return self::createFingerprint($decision, $content, $place);
	}


	/** @param string $lineContent  normalized by `normalizeLineContent()` */
	public static function createFingerprint(string $decision, string $lineContent, int $occurrence): string
	{
		return hash('xxh3', "$decision\n$lineContent\n$occurrence");
	}


	/**
	 * Line content as the fingerprint sees it: trimmed, whitespace collapsed.
	 */
	public static function normalizeLineContent(string $content): string
	{
		return (string) preg_replace('~\s+~', ' ', trim($content));
	}


	/**
	 * Where the violation of the decision about the construct stands in this pass: at the first report of it, which
	 * the place given becomes when there has been none.
	 * @return array{Node|Token, ?Trivia}
	 */
	public function placeConstruct(Node $construct, string $decision, Node|Token $at, ?Trivia $trivia): array
	{
		$reports = $this->constructs[$construct] ?? [];
		$reports[$decision] ??= ['at' => $at, 'trivia' => $trivia, 'fingerprint' => null];
		$this->constructs[$construct] = $reports;
		return [$reports[$decision]['at'], $reports[$decision]['trivia']];
	}


	/**
	 * The identity of the violation about a construct: the first report of the decision about it in the pass counts
	 * as an occurrence, the ones after it are the same violation.
	 */
	public function createFor(Node $construct, string $decision, string $message, int $line): string
	{
		$reports = $this->constructs[$construct] ?? [];
		$report = $reports[$decision] ?? ['at' => $construct, 'trivia' => null, 'fingerprint' => null];
		$report['fingerprint'] ??= $this->create($decision, $message, $line);
		$reports[$decision] = $report;
		$this->constructs[$construct] = $reports;
		return $report['fingerprint'];
	}
}
