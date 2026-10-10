<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Builder, Node, Token, Trivia};
use PhpSyntax\Nodes\Expression\{YieldFromNode, YieldNode};
use PhpSyntax\Nodes\{FunctionLikeNode, PlainNodeList};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\{BlockNode, ReturnNode};
use function count;


/**
 * PHP 8.6 deprecated a value returned from a constructor or a destructor, which nothing receives, and a `yield`
 * in them, which makes the method a generator that never runs. A returned value that cannot do anything when it
 * is evaluated goes, leaving a bare `return`, or nothing at the end of the body; any other one stays as a statement
 * of its own before the `return`, so that what it does still happens. A `yield` is reported, the method having to be
 * written anew. What stands in a closure inside the method belongs to the closure.
 */
#[RuleInfo(Stage::Structure)]
final class NoConstructorReturnValuesRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.php.constructorReturnValue', Domain::state('forbidden'), 'A `return` with a value in a constructor or destructor, deprecated by PHP 8.6')];
	}


	public function getVisitedNodes(): array
	{
		return [MethodNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof MethodNode
			|| $node->body === null
			|| (!$node->isConstructor() && !$node->isDestructor())
		) {
			return;
		}

		$method = $node->isConstructor() ? 'constructor' : 'destructor';
		foreach ([...$node->find(YieldNode::class), ...$node->find(YieldFromNode::class)] as $yield) {
			if ($yield->findAncestor(FunctionLikeNode::class) === $node) {
				$context->report($yield, "Making a $method a generator is deprecated since PHP 8.6.", fixable: false);
			}
		}

		foreach ($node->find(ReturnNode::class) as $return) {
			if ($return->expression !== null && $return->findAncestor(FunctionLikeNode::class) === $node) {
				self::dropValue($return, $node->body, "Returning a value from a $method is deprecated since PHP 8.6.", $context);
			}
		}
	}


	private static function dropValue(ReturnNode $return, BlockNode $body, string $message, RuleContext $context): void
	{
		$value = $return->expression;
		assert($value !== null);
		$list = $return->parent;
		$items = $list instanceof PlainNodeList ? $list->getItems() : [];
		$last = $list === $body->statements && $items[count($items) - 1] === $return;
		$silent = $value->isRepeatableRead() || $value->hasValue();
		// a value with an effect needs a statement of its own, which only a list of statements has room for
		$fixable = !$return->hasInnerComment() && ($silent || $list instanceof PlainNodeList);
		if (!$context->report($return, $message, fixable: $fixable) || !$fixable) {
			return;
		}

		$builder = new Builder;
		if ($silent && $last) {
			// the space before a statement sharing its line stays, so the space after it goes with it
			$end = $return->getLastToken();
			if (
				!$return->getFirstToken()->startsLine()
				&& array_all($end->trailingTrivia, fn(Trivia $trivia) => $trivia->id === Trivia::Whitespace)
			) {
				$end->setTrailingTrivia([]);
			}

			$return->remove();

		} elseif ($silent) {
			$return->expression = null;
			$return->returnKeyword->setTrailingTrivia([]);
		} else {
			$statement = $builder->statement('$value;', value: $value);
			$return->replaceWith($statement);
			if (!$last && $list instanceof PlainNodeList) {
				$indentation = $statement->getFirstToken()->getLineIndentation();
				$bare = $builder->statement('return;');
				$bare->setEdgeTrivia(
					leading: $indentation === '' ? [] : [new Trivia(Trivia::Whitespace, $indentation)],
					trailing: $statement->getLastToken()->trailingTrivia,
				);
				$statement->setEdgeTrivia(trailing: [Trivia::fromText($context->style->lineEnding)]);
				$list->insertAfter($statement, $bare);
			}
		}
	}
}
