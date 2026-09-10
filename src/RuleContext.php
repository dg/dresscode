<?php declare(strict_types=1);

namespace DressCode;

use DressCode\Engine\Suppression;
use PhpSyntax\Node;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Style;
use PhpSyntax\Token;
use PhpSyntax\Trivia;


/**
 * What a rule sees of the file it runs on: the tree, the style, analyses, its storage, and report().
 */
final class RuleContext
{
	/** @var array<string, mixed>  state of the rule for this file; rules are stateless, this is where per-file state goes */
	public array $storage = [];

	/** @var list<array{Node|Token, ?Trivia, string, Severity, int, bool, ?string, int, bool}>  reports of the current callback with the revision at the time, whether it was silenced, the fingerprint, the original line and whether the fix was refused as risky */
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
	 */
	public function report(
		Node|Token $at,
		string $message,
		Severity $severity = Severity::Error,
		?Trivia $trivia = null,
		bool $risky = false,
	): bool
	{
		$line = self::findOriginalLine($at, $trivia);
		if ($line !== null && $this->suppression->isSuppressed($this->ruleName, $line)) {
			$this->reports[] = [$at, $trivia, $message, $severity, $this->file->revision, true, null, $line, false];
			return false;
		}

		// the identity is counted here, before the baseline is asked: a report a comment silenced was
		// never counted into it either, and the numbering of the occurrences has to mean the same
		$line ??= 1;
		$fingerprint = $this->fingerprints->create($this->ruleName, $message, $line);
		$known = $this->fingerprints->isKnown($fingerprint);
		$refused = $risky && !$this->fixRisky;
		$this->reports[] = [$at, $trivia, $message, $severity, $this->file->revision, $known, $fingerprint, $line, $risky];
		return !$known && !$refused;
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
	 * @return list<array{Node|Token, ?Trivia, string, Severity, int, bool, ?string, int, bool}>
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
