<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage, Values};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\Expression\{NewNode, ParenthesizedNode};


/**
 * The parentheses around an instantiation whose member is accessed right away, `->`, `?->` or `::`: since PHP 8.4
 * forbidden ones go, `new Foo()->bar()`, and required ones are written, `(new Foo())->bar()`. An instantiation without
 * the parentheses of its arguments keeps the wrapping, PHP refusing `new Foo->bar()`, unless it is of an anonymous
 * class; one followed by `[` or `(` stays as it is.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.4'])]
final class InstantiationWrappingRule extends NodeRule
{
	private const Path = 'classes.instantiationWrapping';

	private bool $required = false;


	public static function getDecisions(): array
	{
		return [new Decision(
			self::Path,
			Domain::state('forbidden', 'required'),
			'The parentheses around an instantiation a member is accessed on, `new Foo()->bar()` or `(new Foo())->bar()`; one without the parentheses of its arguments stays wrapped',
		)];
	}


	public function configure(Values $values): void
	{
		$this->required = $values->find(self::Path)?->getWord() === 'required';
	}


	public function getVisitedNodes(): array
	{
		return [$this->required ? NewNode::class : ParenthesizedNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof NewNode) {
			if (
				!$node->parent instanceof ParenthesizedNode
				&& self::isAccessed($node->getLastToken())
				&& $context->report($node, 'The instantiation whose member is accessed must be wrapped in parentheses.')
			) {
				$node->replaceWith((new Builder)->parenthesize($node));
			}

		} elseif (
			$node instanceof ParenthesizedNode
			&& ($new = $node->expression) instanceof NewNode
			&& ($new->arguments !== null || $new->class instanceof AnonymousClassNode)
			&& self::isAccessed($node->closeParen)
			&& !$node->openParen->hasCommentUpTo($new->getFirstToken())
			&& !$new->getLastToken()->hasCommentUpTo($node->closeParen)
			&& $context->report($node->openParen, 'Useless parentheses around `new`, because PHP 8.4 reaches a member without them.')
		) {
			$node->replaceWith($new->withoutEdgeTrivia());
		}
	}


	/** Whether a member is accessed right after the token. */
	private static function isAccessed(Token $last): bool
	{
		return $last->getNext()?->is(['->', '?->', '::']) ?? false;
	}
}
