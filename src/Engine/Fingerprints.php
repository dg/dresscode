<?php declare(strict_types=1);

namespace DressCode\Engine;

use DressCode\Violation;
use PhpSyntax\Node;
use PhpSyntax\Token;
use PhpSyntax\Trivia;


/**
 * The identity of the violations of one file while they are being reported: the fingerprint of a report.
 * The counter of occurrences runs over one pass and over every rule, so that a pass repeating a report of
 * the pass before arrives at the same fingerprint and adds no violation.
 * @internal
 */
final class Fingerprints
{
	/** @var array<string, int>  rule\nmessage\nline content → occurrences so far */
	private array $occurrences = [];

	/** @var \WeakMap<Node, array<string, array{Node|Token, ?Trivia, ?string}>>  a construct → rule → the place of its violation in this pass and its fingerprint */
	private \WeakMap $constructs;


	public function __construct(
		/** @var list<string>  lines of the original file */
		private readonly array $lines,
	) {
		$this->constructs = new \WeakMap;
	}


	/** A new pass counts the occurrences from the start. */
	public function startPass(): void
	{
		$this->occurrences = [];
		$this->constructs = new \WeakMap;
	}


	/** The identity of the violation about to be reported; every call counts as one occurrence. */
	public function create(string $ruleName, string $message, int $line): string
	{
		$content = Violation::normalizeLineContent($this->lines[$line - 1] ?? '');
		$key = "$ruleName\n$message\n$content";
		$this->occurrences[$key] = ($this->occurrences[$key] ?? 0) + 1;
		return Violation::createFingerprint($ruleName, $message, $content, $this->occurrences[$key]);
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
}
