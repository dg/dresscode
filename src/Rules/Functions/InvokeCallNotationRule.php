<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\{MemberKind, Types};
use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage, Tristate, Values};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\Expression\{FunctionCallNode, MethodCallNode, ParenthesizedNode};
use PhpSyntax\Nodes\{ExpressionNode, IdentifierNode};


/**
 * The call of an invokable object: forbidden, it is called directly, `$handler($a)`, not `$handler->__invoke($a)`; an
 * object held in a property gets parentheses, `($this->handler)($a)`, so that PHP does not read a method call. A call
 * in a nullsafe chain stays: calling the object is no link of the chain, so a null would be called instead of skipped.
 *
 * Required, it is called through `__invoke()`, which a search for the usages of the method finds. Only an expression
 * the types show to be objects with `__invoke()` and no Closure is rewritten, another one may be a callable of any
 * kind; `$f(...)` stays, since it makes a Closure of the object.
 */
#[RuleInfo(Stage::Structure, analyses: [Types::class])]
final class InvokeCallNotationRule extends NodeRule
{
	private const Path = 'hygiene.__invokeCall';

	private bool $required = false;


	public static function getDecisions(): array
	{
		return [new Decision(
			self::Path,
			Domain::state('forbidden', 'required'),
			'`$f->__invoke($x)` is `$f($x)`, or the other way round for an object',
		)];
	}


	public function configure(Values $values): void
	{
		$this->required = $values->find(self::Path)?->getWord() === 'required';
	}


	public function getVisitedNodes(): array
	{
		return [MethodCallNode::class, FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof MethodCallNode && !$this->required) {
			$this->callDirectly($node, $context);
		} elseif ($node instanceof FunctionCallNode && $this->required) {
			$this->callThroughInvoke($node, $context);
		}
	}


	private function callDirectly(MethodCallNode $node, RuleContext $context): void
	{
		if (
			!$node->operator->is('->')
			|| !$node->name instanceof IdentifierNode
			|| !$node->name->equals('__invoke')
			|| $node->object->isInNullsafeChain()
			|| $node->object->getLastToken()->hasCommentUpTo($node->arguments->openParen)
			|| !$context->report($node->name, 'The invokable object must be called directly, not through `__invoke()`.')
		) {
			return;
		}

		$node->replaceWith((new Builder)->call($node->object, $node->arguments));
	}


	private function callThroughInvoke(FunctionCallNode $node, RuleContext $context): void
	{
		$callee = $node->name;
		if (!$callee instanceof ExpressionNode) {
			return;
		}

		$object = $callee instanceof ParenthesizedNode ? $callee->expression : $callee;
		if (
			$node->arguments->isPartialApplication()
			|| ($types = $context->findAnalysis(Types::class)) === null
			|| !$this->isInvokableObject($object, $types)
			|| $this->hasCommentInTheWay($node, $callee, $object)
			|| !$context->report($callee, 'The invokable object must be called through `__invoke()`.')
		) {
			return;
		}

		$node->replaceWith((new Builder)->methodCall($object, '__invoke', $node->arguments));
	}


	private function isInvokableObject(ExpressionNode $object, Types $types): bool
	{
		$classes = $types->findClasses($object);
		return $classes !== []
			&& $types->isOfType($object, 'object') === Tristate::Yes
			&& $types->isOfType($object, \Closure::class) === Tristate::No
			&& array_all($classes, fn(string $class) => $types->hasMember($class, MemberKind::Method, '__invoke'));
	}


	private function hasCommentInTheWay(FunctionCallNode $node, ExpressionNode $callee, ExpressionNode $object): bool
	{
		return $callee->getLastToken()->hasCommentUpTo($node->arguments->openParen)
			|| $callee->getFirstToken()->hasCommentUpTo($object->getFirstToken())
			|| $object->getLastToken()->hasCommentUpTo($callee->getLastToken());
	}
}
