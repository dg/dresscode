<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\Types;
use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\CodeWriter;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{AnonymousClassNode, ArgumentNode, ClassLikeNode, IdentifierNode, NameNode};
use PhpSyntax\Nodes\Expression\{StaticMethodCallNode, VariableNode};
use PhpSyntax\Nodes\Member\{MethodNode, TraitUseNode};
use PhpSyntax\Nodes\Statement\{ClassNode, ExpressionStatementNode, ReturnNode};
use function count, in_array;


/**
 * A method whose whole body passes its parameters on to the parent method of the same name,
 * `return parent::load($id)`, says nothing the parent does not, and goes. The types decide whether a caller could
 * tell the two apart, which the method alone does not show: the parent must declare it with the same visibility,
 * staticness, parameters and return type (`Types::hasParentSignature()`).
 *
 * The method stays where it says more than its code: a comment, an attribute other than `#[\Override]`, `final`,
 * a trait the class uses, whose method of the name would take the place of the parent's, and a call that discards
 * the return value of a method that is not void. A body that passes the parameters in another order, or not all
 * of them, is not a repetition either.
 *
 * Every fix is risky: a parent reading `func_get_args()` sees the arguments a caller passed beyond the parameters,
 * which the override dropped, and the frame of the method leaves the stack traces and the debugger.
 */
#[RuleInfo(
	'dresscode/useless-overriding-method',
	Stage::Structure,
	description: 'Removes a method that only calls the parent method with the same arguments',
	group: Group::Cleanup,
	requiresTypes: true,
	risky: true,
)]
final class UselessOverridingMethodRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [MethodNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$class = $node instanceof Node ? $node->findAncestor(ClassLikeNode::class) : null;
		if (
			!$node instanceof MethodNode
			|| !($class instanceof ClassNode || $class instanceof AnonymousClassNode)
			|| $class->extends === null
			|| array_any($class->members->getItems(), fn(Node $member) => $member instanceof TraitUseNode)
			|| !$this->isRepetition($node)
			|| array_any($node->getTokens(), fn(Token $token) => $token->hasComment())
			|| !$context->getAnalysis(Types::class)->hasParentSignature($node)
			|| !$context->report($node->name, "Useless method {$node->name->text}(), it only calls the parent method")
		) {
			return;
		}

		CodeWriter::removeBetweenGaps($node, $context->getStyle()->eol);
	}


	/** Whether the method is nothing but the call of the parent method of its name with its parameters, in their order. */
	private function isRepetition(MethodNode $method): bool
	{
		$statements = $method->body?->statements->getItems() ?? [];
		$statement = $statements[0] ?? null;
		$call = match (true) {
			$statement instanceof ReturnNode => $statement->expression,
			$statement instanceof ExpressionStatementNode && self::returnsNothing($method) => $statement->expression,
			default => null,
		};
		if (
			count($statements) !== 1
			|| !$call instanceof StaticMethodCallNode
			|| !$call->class instanceof NameNode
			|| strtolower($call->class->text) !== 'parent'
			|| !$call->name instanceof IdentifierNode
			|| strcasecmp($call->name->text, $method->name->text) !== 0
			|| $method->modifiers->isFinal()
			|| array_any($method->attributes->getItems(), fn(Node $group) => preg_match('~^#\[\s*\\\\?Override\s*\]$~i', $group->text) !== 1)
		) {
			return false;
		}

		$parameters = $method->parameters->getItems();
		$arguments = $call->arguments->items->getItems();
		if (count($parameters) !== count($arguments)) {
			return false;
		}

		foreach ($parameters as $i => $parameter) {
			$argument = $arguments[$i];
			if (
				$parameter->isPromoted() // a promoted parameter declares a property, which would go with the constructor
				|| !$argument instanceof ArgumentNode
				|| $argument->name !== null
				|| ($argument->ellipsis !== null) !== ($parameter->ellipsis !== null)
				|| !$argument->value instanceof VariableNode
				|| $argument->value->plainName === null
				|| $argument->value->plainName !== $parameter->variable->plainName
			) {
				return false;
			}
		}

		return true;
	}


	/** Whether the method returns no value a call of the parent could give it: void, never, or a constructor or destructor. */
	private static function returnsNothing(MethodNode $method): bool
	{
		return in_array(strtolower($method->name->text), ['__construct', '__destruct'], true)
			|| in_array(strtolower((string) $method->returnType?->text), ['void', 'never'], true);
	}
}
