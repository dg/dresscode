<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use DressCode\Engine\Suppression;
use PhpSyntax\{Node, Token, Trivia};
use PhpSyntax\Nodes\{FileNode, MemberNode, StatementNode};


/**
 * What a rule sees of the file it runs on: the tree, the style, analyses, its storage, and `report()`.
 */
final class RuleContext
{
	/** @var array<string, mixed>  state of the rule for this pass over the file; rules are stateless, this is where such state goes */
	public array $storage = [];

	/** @var list<Engine\Report>  reports of the current callback */
	private array $reports = [];


	/** @internal created by the pass loop */
	public function __construct(
		public readonly FileNode $file,
		public readonly string $path,
		public readonly Style $style,
		/** the oldest version the checked code runs on; compare it with `version_compare()` */
		public readonly string $phpVersion,
		private readonly Analyses\Registry $analyses,
		private readonly Suppression $suppression,
		private readonly Engine\Fingerprints $fingerprints,
		private readonly Engine\ReportPolicy $policy = new Engine\ReportPolicy,
		/** the gate of the reports of the rule: which of its decisions the run reports */
		private readonly Engine\Gate $gate = new Engine\Gate,
		/** the attribute of the rule, whose analyses a strict run holds it to; null for a context of no rule */
		private readonly ?RuleInfo $info = null,
	) {
	}


	/**
	 * Reports a violation at the node, or at one of the trivia of the token when the problem lies in whitespace
	 * or a comment; returns false when a comment silences it, and then the rule must not fix it.
	 * `$risk` says that fixing this occurrence may change what the code does, and what would decide that it does
	 * not: the violation is reported either way, and false says the run does not allow the fix, so the rule must
	 * leave the code alone. `$because` says what may go wrong at this occurrence, where the risk alone does not
	 * say it; it stands beside the message, which stays the same whatever the run knows, and goes with a `$risk`,
	 * without which a strict run refuses it.
	 * `$follows` names the token opening the line the reported whitespace is counted from, and says that the rule
	 * writes the whitespace of the reported line: where that line was opened, closed or moved by a violation of
	 * this run, the report is recorded as derived from it.
	 * `$byLine` says the violation is the shape of the line the reported token stands on, whatever whitespace it
	 * holds: where that line was opened or closed by a violation of this run, the report is derived from it.
	 * `$fixable` false says the rule has no fix for this occurrence and only reports it: the report is never risky,
	 * false comes back whatever the run allows, and a mutation after it breaks the contract.
	 * `$decision` is the path of the decision the occurrence violates, which only a rule of one requirement leaves
	 * out; one the run does not report, being `keep` or narrowed away, records nothing and returns false.
	 */
	public function report(
		Node|Token $at,
		string $message,
		?string $decision = null,
		?Trivia $trivia = null,
		?Risk $risk = null,
		?Token $follows = null,
		bool $byLine = false,
		bool $fixable = true,
		?string $because = null,
	): bool
	{
		$decision = $this->gate->admit($decision);
		if ($decision === null) {
			return false;
		}

		$gap = $trivia === null ? null : self::findGap($at, $trivia);
		if ($follows !== null) {
			$gap ??= $at->getFirstToken();
		}

		return $this->record($decision, $at, $message, $trivia, $risk, $gap, $follows, byLine: $byLine, fixable: $fixable, because: $because);
	}


	/**
	 * Reports what the engine decided about the gap before the token, under the decision of the claim
	 * made there; `$breaks` says the fix puts a line break in or takes one out, opening or closing the line. The
	 * gaps of a construct the claim was decided about are one violation in the pass, the shape of the construct
	 * being what is wrong and not each of its breaks: placed and silenced as the first of them.
	 * @internal
	 */
	public function reportGap(
		Token $gap,
		Node|Token $at,
		string $message,
		?Trivia $trivia = null,
		bool $breaks = false,
		?Node $construct = null,
		?string $decision = null,
	): bool
	{
		$decision = $this->gate->admit($decision);
		if ($decision === null) {
			return false;
		} elseif ($construct !== null) {
			[$at, $trivia] = $this->fingerprints->placeConstruct($construct, $decision, $at, $trivia);
		}

		return $this->record($decision, $at, $message, $trivia, risk: null, gap: $gap, follows: null, breaks: $breaks, construct: $construct);
	}


	/**
	 * Whether a report of the decision at the node records nothing: a comment silences it, or the run does not
	 * report the decision; unlike a report refused as risky.
	 */
	public function isSilenced(Node|Token $at, ?Trivia $trivia = null, ?string $decision = null): bool
	{
		$decision = $this->gate->admit($decision);
		if ($decision === null) {
			return true;
		}

		$line = self::findOriginalLine($at, $trivia);
		return $line !== null && $this->suppression->isSilenced($decision, $line);
	}


	private function record(
		string $decision,
		Node|Token $at,
		string $message,
		?Trivia $trivia,
		?Risk $risk,
		?Token $gap,
		?Token $follows,
		bool $byLine = false,
		bool $breaks = false,
		?Node $construct = null,
		bool $fixable = true,
		?string $because = null,
	): bool
	{
		if ($this->policy->strict && $because !== null && $risk === null) {
			throw new \LogicException('It reported `because` without a `risk`, which it explains.');
		}

		$line = self::findOriginalLine($at, $trivia);
		if ($line !== null && $this->suppression->isSilenced($decision, $line)) {
			$this->reports[] = new Engine\Report($decision, $at, $trivia, $message, $this->file->revision, silenced: true, fingerprint: null, line: $line, risk: null);
			return false;
		}

		// a report a comment silenced is never counted into the identity, so the numbering of the occurrences
		// means the same whether the comment is there or not
		$line ??= 1;
		$fingerprint = $construct === null
			? $this->fingerprints->create($decision, $message, $line)
			: $this->fingerprints->createFor($construct, $decision, $message, $line);
		$risk = $fixable ? $risk : null;
		$refused = $risk !== null && !$this->policy->acceptsRisk($decision);
		$this->reports[] = new Engine\Report(
			decision: $decision,
			at: $at,
			trivia: $trivia,
			message: $message,
			revision: $this->file->revision,
			silenced: false,
			fingerprint: $fingerprint,
			line: $line,
			risk: $risk,
			gap: $gap,
			follows: $follows,
			byLine: $byLine,
			breaks: $breaks,
			fixable: $fixable,
			because: $risk === null ? null : $because,
			refused: $refused,
			construct: $construct,
		);
		return $fixable && !$refused;
	}


	/**
	 * The token whose gap before it holds the whitespace: the token itself when the trivia stands before it,
	 * the next one when it stands after; a comment is nobody's gap, its problem is the comment's own.
	 */
	private static function findGap(Node|Token $at, Trivia $trivia): ?Token
	{
		if ($trivia->id !== Trivia::Whitespace && $trivia->id !== Trivia::LineEnding) {
			return null;
		}

		$first = $at->getFirstToken();
		if ($first !== null && in_array($trivia, $first->leadingTrivia, true)) {
			return $first;
		}

		$last = $at->getLastToken();
		return $last !== null && in_array($trivia, $last->trailingTrivia, true) ? $last->getNext() : null;
	}


	/**
	 * An analysis of the file: any class built from the FileNode (or from nothing), kept until the file mutates;
	 * those of the core are in DressCode\Analyses and PhpSyntax\Analyses, a plugin registers its own under the key
	 * analyses of the configuration. Throws for one the run does not have, which `findAnalysis()` answers with null,
	 * and in a strict run for one the rule does not name in `RuleInfo::$analyses`.
	 * @template T of object
	 * @param  class-string<T>  $class
	 * @return T
	 */
	public function getAnalysis(string $class): object
	{
		$this->checkDeclared($class);
		return $this->analyses->get($this->file, $class, $this->path);
	}


	/**
	 * The analysis of the file where the run has it, null where it does not: an analysis nothing registered whose
	 * constructor takes more than the FileNode. What the factory of a registered one throws is thrown.
	 * @template T of object
	 * @param  class-string<T>  $class
	 * @return ?T
	 */
	public function findAnalysis(string $class): ?object
	{
		$this->checkDeclared($class);
		return $this->analyses->find($this->file, $class, $this->path);
	}


	/**
	 * A strict run holds a rule to the analyses it declares, so that what it depends on is in its attribute.
	 * @throws \LogicException
	 */
	private function checkDeclared(string $class): void
	{
		if ($this->policy->strict) {
			$this->info?->checkAnalysis($class);
		}
	}


	/** @internal */
	public function hasReports(): bool
	{
		return $this->reports !== [];
	}


	/**
	 * Takes the reports made since the last call, in the order they were made.
	 * @return list<Engine\Report>
	 * @internal
	 */
	public function takeReports(): array
	{
		$reports = $this->reports;
		$this->reports = [];
		return $reports;
	}


	/**
	 * Line in the original file: of the trivia when it comes from the file, otherwise of the first token
	 * of the node with an original position. A synthetic token stands where the code a fix replaced stood, which
	 * the first original token after it within its statement or member still marks, what the fix moved or left
	 * there; failing that, the nearest original token before it.
	 */
	private static function findOriginalLine(Node|Token $at, ?Trivia $trivia): ?int
	{
		if ($trivia !== null && $trivia->line >= 0) {
			return $trivia->line;
		}

		$token = $at->getFirstToken();
		if ($token === null || $token->line >= 0) {
			return $token?->line;
		}

		$next = $token;
		for ($node = $token->parent; $node !== null; $node = $node->parent) {
			for ($last = $node->getLastToken(); $next !== null && $next !== $last;) {
				$next = $next->getNext();
				if ($next !== null && $next->line >= 0) {
					return $next->line;
				}
			}

			if ($node instanceof StatementNode || $node instanceof MemberNode) {
				break;
			}
		}

		while ($token && $token->line < 0) {
			$token = $token->getPrevious();
		}

		return $token?->line;
	}
}
