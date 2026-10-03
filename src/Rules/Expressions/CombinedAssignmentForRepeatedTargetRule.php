<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\Analyses\Types;
use DressCode\{NodeRule, Risk, RuleContext, RuleGroup, RuleInfo, Stage, Tristate};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{AnonymousClassNode, Expression, ExpressionNode};


/**
 * The combined operator for an assignment that repeats its target as the left operand:
 * `$a += $b`, not `$a = $a + $b`; only for targets free of side effects. An offset target stays,
 * because on a string offset the combined operator throws an Error, and without the types a string is
 * not told from an array. A property is a risky target: `??=` writes nothing where the value is not null,
 * so a readonly property, `__set` or a hook is not reached, and the combined operator reads the property
 * only after a right side that may have changed it. A variable reads the same either way. Without the types,
 * a plain property, which `??=` may skip writing, is not told from another.
 */
#[RuleInfo(
	'dresscode/combinedAssignmentForRepeatedTarget',
	Stage::Structure,
	description: 'Uses `+=` and friends where an assignment repeats its target',
	group: RuleGroup::Modernization,
)]
final class CombinedAssignmentForRepeatedTargetRule extends NodeRule
{
	private const Operators = [
		'+' => '+=', '-' => '-=', '*' => '*=', '/' => '/=', '%' => '%=', '**' => '**=',
		'.' => '.=', '&' => '&=', '|' => '|=', '^' => '^=', '<<' => '<<=', '>>' => '>>=', '??' => '??=',
	];


	public function getVisitedTypes(): array
	{
		return [Expression\AssignmentNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof Expression\AssignmentNode
			|| !($var = $node->target) instanceof ExpressionNode
			|| $var instanceof Expression\ArrayAccessNode
			|| !($binary = $node->expression) instanceof Expression\BinaryOpNode
			|| ($combined = self::Operators[$binary->operator->text] ?? null) === null
			|| !$var->isRepeatableRead()
			|| !$var->matches($binary->left)
			|| $node->equals->currentLine !== ($right = $binary->right->getFirstToken())->currentLine
			|| $node->equals->hasCommentUpTo($right)
		) {
			return;
		}

		$property = $var instanceof Expression\PropertyFetchNode || $var instanceof Expression\StaticPropertyFetchNode;
		[$risk, $because] = match (true) {
			!$property => [null, null],
			$combined === '??=' => match ($context->findAnalysis(Types::class)?->isPlainProperty($var) ?? Tristate::Maybe) {
				Tristate::Yes => [null, null],
				Tristate::No => [Risk::BehaviorChanges, '`??=` does not write a value that is not null, which the property notices'],
				Tristate::Maybe => [Risk::TypeUnknown, 'the property may be readonly, hooked or magic, and `??=` does not write a value that is not null'],
			},
			self::mayRunCode($binary->right) => [Risk::BehaviorChanges, "the right side may change the property before `$combined` reads it"],
			default => [null, null],
		};
		if (!$context->report(
			$node,
			"The assignment must be written `$combined` instead of repeating its target",
			risk: $risk,
			because: $because,
		)) {
			return;
		}

		$replacement = (new Builder)->combinedAssign($var, $combined, $binary->right);
		$replacement->target->setEdgeTrivia(trailing: $var->trailingTrivia);
		$replacement->operator
			->setLeadingTrivia($node->equals->leadingTrivia)
			->setTrailingTrivia($node->equals->trailingTrivia);
		$node->replaceWith($replacement);
	}


	/** Whether evaluating the expression may run code, which could change the target before it is read. */
	private static function mayRunCode(ExpressionNode $expression): bool
	{
		foreach ([$expression, ...$expression->find(Node::class)] as $node) {
			if (
				$node instanceof Expression\FunctionCallNode
				|| $node instanceof Expression\MethodCallNode
				|| $node instanceof Expression\StaticMethodCallNode
				|| $node instanceof Expression\NewNode
				|| $node instanceof AnonymousClassNode
				|| $node instanceof Expression\AssignmentNode
				|| $node instanceof Expression\CombinedAssignmentNode
				|| $node instanceof Expression\AssignmentByReferenceNode
				|| $node instanceof Expression\PrefixOpNode
				|| $node instanceof Expression\PostfixOpNode
				|| $node instanceof Expression\IncludeNode
				|| $node instanceof Expression\EvalNode
				|| $node instanceof Expression\YieldNode
				|| $node instanceof Expression\YieldFromNode
				|| $node instanceof Expression\CloneNode
				|| $node instanceof Expression\ShellExecNode
			) {
				return true;
			}
		}

		return false;
	}
}
