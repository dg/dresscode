<?php declare(strict_types=1);

namespace DressCode;

use DressCode\Engine\Suppression;
use PhpSyntax\Node;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Style;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function in_array;


/**
 * What a rule sees of the file it runs on: the tree, the style, analyses, its storage, and report().
 */
final class RuleContext
{
	/** @var array<string, mixed>  state of the rule for this file; rules are stateless, this is where per-file state goes */
	public array $storage = [];

	/** @var list<Engine\Report>  reports of the current callback */
	private array $reports = [];


	/** @internal created by the pass runner */
	public function __construct(
		private readonly FileNode $file,
		private readonly string $path,
		private readonly Style $style,
		private readonly string $phpVersion,
		private readonly Analyses\Registry $analyses,
		private readonly Suppression $suppression,
		private readonly Engine\Fingerprints $fingerprints,
		private readonly string $ruleName,
		/** whether the run may make a fix that changes what the code does */
		private readonly bool $fixRisky = false,
	) {
	}


	public function getFile(): FileNode
	{
		return $this->file;
	}


	public function getPath(): string
	{
		return $this->path;
	}


	public function getStyle(): Style
	{
		return $this->style;
	}


	/** The version the checked code is written for, as major.minor; compare it with version_compare(). */
	public function getPhpVersion(): string
	{
		return $this->phpVersion;
	}


	/**
	 * Reports a violation at the node, or at one of the trivia of the token when the problem lies in whitespace
	 * or a comment; returns false when a comment or the baseline silences it, and then the rule must not fix it.
	 * `$risky` says that fixing this occurrence may change what the code does: the violation is reported either
	 * way, and false says the run does not allow the fix, so the rule must leave the code alone.
	 * `$follows` names the token opening the line the reported whitespace is counted from: where that line was
	 * opened, closed or moved by a violation of this run, the report is recorded as derived from it.
	 */
	public function report(
		Node|Token $at,
		string $message,
		Severity $severity = Severity::Error,
		?Trivia $trivia = null,
		bool $risky = false,
		?Token $follows = null,
	): bool
	{
		$gap = $trivia === null ? null : self::findGap($at, $trivia);
		if ($follows !== null) {
			$gap ??= $at instanceof Token ? $at : $at->getFirstToken();
		}

		return $this->record($at, $message, $severity, $trivia, $risky, $gap, $follows);
	}


	/**
	 * Reports what the engine decided about the gap before the token, under the name of the rule whose claim
	 * it was; `$breaks` says the fix puts a line break in or takes one out, opening or closing the line.
	 * @internal
	 */
	public function reportGap(
		Token $gap,
		Node|Token $at,
		string $message,
		?Trivia $trivia = null,
		bool $breaks = false,
	): bool
	{
		return $this->record($at, $message, Severity::Error, $trivia, risky: false, gap: $gap, follows: null, breaks: $breaks);
	}


	private function record(
		Node|Token $at,
		string $message,
		Severity $severity,
		?Trivia $trivia,
		bool $risky,
		?Token $gap,
		?Token $follows,
		bool $breaks = false,
	): bool
	{
		$line = self::findOriginalLine($at, $trivia);
		if ($line !== null && $this->suppression->isSuppressed($this->ruleName, $line)) {
			$this->reports[] = new Engine\Report($at, $trivia, $message, $severity, $this->file->revision, silenced: true, fingerprint: null, line: $line, risky: false);
			return false;
		}

		// the identity is counted here, before the baseline is asked: a report a comment silenced was
		// never counted into it either, and the numbering of the occurrences has to mean the same
		$line ??= 1;
		$fingerprint = $this->fingerprints->create($this->ruleName, $message, $line);
		$known = $this->fingerprints->isKnown($fingerprint);
		$this->reports[] = new Engine\Report($at, $trivia, $message, $severity, $this->file->revision, $known, $fingerprint, $line, $risky, $gap, $follows, $breaks);
		return !$known && !($risky && !$this->fixRisky);
	}


	/**
	 * The token whose gap before it holds the whitespace: the token itself when the trivia stands before it,
	 * the next one when it stands after; a comment is nobody's gap, its problem is the comment's own.
	 */
	private static function findGap(Node|Token $at, Trivia $trivia): ?Token
	{
		if ($trivia->kind !== TriviaKind::Whitespace && $trivia->kind !== TriviaKind::EndOfLine) {
			return null;
		}

		$first = $at instanceof Token ? $at : $at->getFirstToken();
		if ($first !== null && in_array($trivia, $first->leadingTrivia, strict: true)) {
			return $first;
		}

		$last = $at instanceof Token ? $at : $at->getLastToken();
		return $last !== null && in_array($trivia, $last->trailingTrivia, strict: true) ? $last->getNext() : null;
	}


	/**
	 * An analysis of the file: any class built from the FileNode (or from nothing) and kept until the file
	 * mutates; the built-in ones are in DressCode\Analyses and PhpSyntax\Analyses, a plugin registers its own
	 * with Config::analysis().
	 * @template T of object
	 * @param  class-string<T>  $class
	 * @return T
	 */
	public function getAnalysis(string $class): object
	{
		return $this->analyses->get($this->file, $class);
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
	 * of the node with an original position, or the nearest original token before a synthetic one.
	 */
	public static function findOriginalLine(Node|Token $at, ?Trivia $trivia = null): ?int
	{
		if ($trivia?->originalLine !== null) {
			return $trivia->originalLine;
		}

		$token = $at instanceof Token ? $at : $at->getFirstToken();
		while ($token && $token->originalLine === null) {
			$token = $token->getPrevious();
		}

		return $token?->originalLine;
	}
}
