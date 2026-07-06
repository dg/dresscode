<?php declare(strict_types=1);

namespace DressCode;

use DressCode\Engine\Suppression;
use PhpSyntax\Node;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\PhpVersion;
use PhpSyntax\Style;
use PhpSyntax\Token;


/**
 * What a rule sees of the file it runs on: the tree, the style, analyses, its storage, and report().
 */
final class RuleContext
{
	/** @var list<array{Node|Token, string, Severity, int}>  reports of the current callback with the revision at the time */
	private array $reports = [];

	private RuleStorage $storage;


	/** @internal created by the pass runner */
	public function __construct(
		private readonly FileNode $file,
		private readonly string $path,
		private readonly Style $style,
		private readonly PhpVersion $phpVersion,
		private readonly AnalysisRegistry $analyses,
		private readonly Suppression $suppression,
		private readonly string $ruleName,
	) {
		$this->storage = new RuleStorage;
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


	public function getPhpVersion(): PhpVersion
	{
		return $this->phpVersion;
	}


	/**
	 * Reports a violation at the node; returns false when it is suppressed by a comment, and then the rule
	 * must not fix it.
	 */
	public function report(Node|Token $at, string $message, Severity $severity = Severity::Error): bool
	{
		$line = self::findOriginalLine($at);
		if ($line !== null && $this->suppression->isSuppressed($this->ruleName, $line)) {
			$this->reports[] = [$at, $message, $severity, -1];
			return false;
		}

		$this->reports[] = [$at, $message, $severity, $this->file->revision];
		return true;
	}


	/**
	 * @template T of object
	 * @param  class-string<T>  $class
	 * @return T
	 */
	public function getAnalysis(string $class): object
	{
		return $this->analyses->get($this->file, $class);
	}


	public function getStorage(): RuleStorage
	{
		return $this->storage;
	}


	/**
	 * Takes the reports made since the last call; revision -1 marks a suppressed one.
	 * @return list<array{Node|Token, string, Severity, int}>
	 * @internal
	 */
	public function takeReports(): array
	{
		$reports = $this->reports;
		$this->reports = [];
		return $reports;
	}


	/**
	 * Line of the node in the original file: its first token with an original position, or the nearest
	 * original token before a synthetic one.
	 */
	public static function findOriginalLine(Node|Token $at): ?int
	{
		$token = $at instanceof Token ? $at : $at->getFirstToken();
		while ($token && $token->originalLine === null) {
			$token = $token->getPrevious();
		}

		return $token?->originalLine;
	}
}
