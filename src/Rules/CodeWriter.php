<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use DressCode\RuleContext;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{CommentPolicy, Node, SymbolKind, Token};
use PhpSyntax\Nodes\Statement;


/**
 * What a rule writing code into a file needs so that the code takes the shape the file has: a class spelled the way
 * the file reaches it, a node removed with one gap left of the two around it. A rule shipped by a package writes with it too.
 */
final class CodeWriter
{
	/**
	 * How a class is written where the node stands: fully qualified when asked so, else the shortest way that reaches
	 * it, through an import added where none does, the scope takes one and the short name is free. A global class gets
	 * no import, it is written with its backslash where nothing imports it, which name-notation spells as the project
	 * does. It may add an import, so it is called only after report() returned true.
	 */
	public static function spellClass(string $class, Node $at, RuleContext $context, bool $fullyQualified = false): string
	{
		if ($fullyQualified) {
			return '\\' . $class;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$short = $resolver->getShortName($class, SymbolKind::ClassLike, $at);
		$scope = $at->findAncestor(Statement\NamespaceNode::class);
		if (
			str_starts_with($short, '\\')
			&& str_contains($class, '\\')
			&& $scope !== null
			&& NodeHelpers::canAddImport($scope)
			&& $resolver->isAliasFree(substr($class, (int) strrpos('\\' . $class, '\\')), SymbolKind::ClassLike, $at)
		) {
			NodeHelpers::addImport($scope, SymbolKind::ClassLike, $class, $context);
			$short = $context->getAnalysis(NameResolver::class)->getShortName($class, SymbolKind::ClassLike, $at);
		}

		return $short;
	}


	/**
	 * Removes a node standing on lines of its own between two others, a member of a class among them, and leaves one
	 * gap where there were two, the narrower one, which Node::remove() would add up instead: none after the opening
	 * brace for the first member and before the closing one for the last. The comments of the node go where the policy
	 * says, to the next token by default.
	 */
	public static function removeBetweenGaps(Node $node, string $eol, CommentPolicy $comments = CommentPolicy::MoveToNextToken): void
	{
		$next = $node->getLastToken()?->getNext();
		$gap = $next !== null && $next->startsLine()
			? min(self::countBlankLines($node->getFirstToken()), self::countBlankLines($next))
			: null;
		$node->remove($comments);
		if ($next !== null && $gap !== null) {
			$next->setBlankLinesBefore($gap, $eol);
		}
	}


	private static function countBlankLines(?Token $token): int
	{
		$count = 0;
		while (($token?->leadingTrivia[$count] ?? null)?->isEndOfLine()) {
			$count++;
		}

		return $count;
	}
}
