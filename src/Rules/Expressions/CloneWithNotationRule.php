<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\Expression\{AssignmentNode, CloneNode, ParenthesizedNode, PropertyFetchNode, VariableNode};
use PhpSyntax\Nodes\{ExpressionNode, IdentifierNode, PlainNodeList};
use PhpSyntax\Nodes\Statement\{ExpressionStatementNode, ReturnNode};
use function count;


/**
 * A clone whose properties the next statements assign is written as the `clone()` of PHP 8.5 that takes them:
 * `$c = clone $this; $c->a = $a; return $c;` is `return clone($this, ['a' => $a]);`, and where the clone is used
 * further, `$c = clone($this, ['a' => $a]);`. PHP writes the properties the same way, through a hook or `__set()`
 * alike, after `__clone()`.
 *
 * The values are evaluated before the clone is made rather than after it, so only a value that does nothing when
 * it is evaluated is taken, and one that reads the clone keeps the statements as they are. A value read out of a
 * property or an element makes the fix risky: a `__clone()` changing an object the clone shares with the original
 * would show it the other way.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.5'])]
final class CloneWithNotationRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.syntax.cloneWith', Domain::adopted(), '`clone($o, [...])` for a `clone` followed by assignments to the copy')];
	}


	public function getVisitedNodes(): array
	{
		return [ExpressionStatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$assignment = $node instanceof ExpressionStatementNode ? $node->expression : null;
		$list = $node->parent;
		if (
			!$node instanceof ExpressionStatementNode
			|| !$assignment instanceof AssignmentNode
			|| !($clone = $assignment->expression) instanceof CloneNode
			|| !$assignment->target instanceof VariableNode
			|| ($name = $assignment->target->plainName) === null
			|| !$list instanceof PlainNodeList
		) {
			return;
		}

		$statements = $list->getItems();
		$index = $list->indexOf($node);
		$properties = [];
		$risky = false;
		while (($found = self::readPropertyAssignment($statements[$index + count($properties) + 1] ?? null, $name)) !== null) {
			$read = self::classifyValue($found[1], $name);
			if ($read === null || in_array($found[0], array_column($properties, 0), true)) {
				break; // a property assigned twice is written twice, which one item of the array would not do
			}
			$risky = $risky || $read;
			$properties[] = $found;
		}

		$next = $statements[$index + count($properties) + 1] ?? null;
		$returns = $next instanceof ReturnNode
			&& $next->expression instanceof VariableNode
			&& $next->expression->plainName === $name;
		$removed = array_slice($statements, $index + 1, count($properties) + ($returns ? 1 : 0));
		$end = $removed === [] ? null : $removed[count($removed) - 1]->getLastToken();
		if (
			$properties === []
			|| $end === null
			|| $node->getFirstToken()->hasCommentUpTo($end)
			|| !$context->report(
				$clone,
				'The properties assigned to a clone must be given to `clone()`.',
				risk: $risky ? Risk::BehaviorChanges : null,
				because: $risky ? 'the values are read before `__clone()` runs, not after it' : null,
			)
		) {
			return;
		}

		$subject = $clone->expression;
		while ($subject instanceof ParenthesizedNode) { // clone($x) written as a call
			$subject = $subject->expression;
		}

		$items = [];
		$values = [];
		foreach ($properties as $i => [$property, $value]) {
			$items[] = var_export($property, true) . " => \$v$i";
			$values["v$i"] = $value;
		}

		$call = 'clone($s, [' . implode(', ', $items) . '])';
		$replacement = $returns
			? (new Builder)->statement("return $call;", ...$values, s: $subject)
			: (new Builder)->statement("\$t = $call;", ...$values, s: $subject, t: $assignment->target);
		foreach ($removed as $statement) {
			$statement->remove();
		}
		$node->replaceWith($replacement);
	}


	/**
	 * The name of the property the statement assigns to the variable, and the value; null for any other statement.
	 * @return ?array{string, ExpressionNode}
	 */
	private static function readPropertyAssignment(?Node $statement, string $variable): ?array
	{
		$assignment = $statement instanceof ExpressionStatementNode ? $statement->expression : null;
		$target = $assignment instanceof AssignmentNode ? $assignment->target : null;
		return $assignment instanceof AssignmentNode
			&& $target instanceof PropertyFetchNode
			&& !$target->nullsafe
			&& $target->name instanceof IdentifierNode
			&& $target->object instanceof VariableNode
			&& $target->object->plainName === $variable
				? [$target->name->text, $assignment->expression]
				: null;
	}


	/**
	 * Whether evaluating the value before the clone is made may tell: false where it may not, true for a read of a
	 * property or an element, null for a value that does something or reads the clone.
	 */
	private static function classifyValue(ExpressionNode $value, string $clone): ?bool
	{
		foreach ([$value, ...$value->find(VariableNode::class)] as $variable) {
			if ($variable instanceof VariableNode && $variable->plainName === $clone) {
				return null;
			}
		}

		return match (true) {
			$value->hasValue(), $value instanceof VariableNode, $value->isConstantRead() => false,
			$value->isRepeatableRead() => true,
			default => null,
		};
	}
}
