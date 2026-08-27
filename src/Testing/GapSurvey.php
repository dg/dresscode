<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Testing;

use DressCode\Engine\Gaps\{Resolver, Sink};
use DressCode\{Line, Space, Style};
use PhpSyntax\{Node, Token, Traverser, Trivia};
use PhpSyntax\Nodes\FileNode;


/**
 * The gaps the rules govern in a file and what they ask of them, nothing being fixed: for the whitespace fuzz,
 * which damages only what will be fixed back. A space with what it must be; a line with where the token must
 * stand; blank lines with the range they must lie in, the count found and the trivia the run stands above
 * (the token's own line when null).
 * @internal
 */
final class GapSurvey implements Sink
{
	/** @var list<array{'space', Token, Space}|array{'line', Token, Line}|array{'blank', Token, array{int, ?int}, int, ?Trivia}> */
	private array $gaps = [];


	/**
	 * The gaps the rules of the resolver govern in the file.
	 * @return list<array{'space', Token, Space}|array{'line', Token, Line}|array{'blank', Token, array{int, ?int}, int, ?Trivia}>
	 */
	public static function collect(Resolver $resolver, FileNode $file, Style $style): array
	{
		$survey = new self;
		$resolver->begin($style, $survey);
		new Traverser()->traverse($file, fn(Node|Token $node) => $node instanceof Token ? $resolver->enterToken($node) : $resolver->enterNode($node));
		return $survey->gaps;
	}


	public function line(array $claim, ?Token $previous, Token $token, ?Space $space, bool $broken): void
	{
		$this->gaps[] = ['line', $token, $claim[1]];
	}


	public function space(array $claim, Token $previous, Token $token, string $found): void
	{
		$this->gaps[] = ['space', $token, $claim[1]];
	}


	public function blankLines(array $claim, Token $token, array $range, int $from, int $found, ?Trivia $below): void
	{
		$this->gaps[] = ['blank', $token, $range, $found, $token->leadingTrivia[$from + $found] ?? null];
	}
}
