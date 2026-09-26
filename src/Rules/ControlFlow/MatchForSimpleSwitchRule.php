<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Parser, Token};
use PhpSyntax\Nodes\{CaseNode, Expression, ExpressionNode, Statement, StatementNode};
use function count;


/**
 * A switch whose every case gives one value to the same variable, or returns one, is a match, which says
 * so in one expression instead of repeating the assignment and the break. Several labels sharing a body
 * become the values of one arm.
 *
 * The switch must end with a `default`, because a match with no arm for the subject raises an error where the
 * switch went on quietly, and every case must hold the assignment and its break and nothing else, a body
 * falling through into the next one among the things it must not hold. A comment anywhere in the switch keeps
 * it as it is, the arms of a match having nowhere to put one.
 *
 * Every fix is risky: a switch compares loosely and a match strictly, so a case of `1` catches `'1'` and `true`
 * and an arm of `1` catches neither. Without the types, a subject of the type of the labels is not told from
 * one of another type.
 */
#[RuleInfo(
	'dresscode/match-for-simple-switch',
	Stage::Structure,
	description: 'Writes a switch whose every case assigns or returns one value as a match',
	group: Group::Modernization,
	requires: ['php' => '>=8.0'],
	risky: true,
)]
final class MatchForSimpleSwitchRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [Statement\SwitchNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Statement\SwitchNode || $node->hasComment()) {
			return;
		}

		$arms = self::readArms($node);
		if (
			$arms === null
			|| !$context->report($node->switchKeyword, 'The switch giving one value must be written as a match')
		) {
			return;
		}

		$style = $context->getStyle();
		$indentation = $node->getFirstToken()?->getIndentation() ?? '';
		$lines = [];
		foreach ($arms as [$labels, $body]) {
			$values = $labels === [] ? 'default' : implode(', ', array_fill(0, count($labels), '0'));
			$lines[] = $indentation . $style->indent . $values . ' => 0,';
		}

		$target = $arms[0][2];
		$text = ($target === null ? 'return ' : '$dressCodeTarget = ')
			. 'match (0) {' . $style->eol
			. implode($style->eol, $lines) . $style->eol
			. $indentation . '};';
		$statement = (new Parser)->parseStatement($text);
		self::fill($statement, $node, $arms);
		$node->replaceWith($statement);
	}


	/**
	 * Puts the subject, the labels, the bodies and the assigned variable of the switch into the statement
	 * built for it, whose every expression is a placeholder.
	 * @param  list<array{list<ExpressionNode>, ExpressionNode, ?ExpressionNode}>  $arms
	 */
	private static function fill(StatementNode $statement, Statement\SwitchNode $switch, array $arms): void
	{
		$match = $statement->find(Expression\MatchNode::class)[0] ?? null;
		assert($match !== null);
		$match->subject->replaceWith($switch->subject->withoutEdgeTrivia());
		foreach ($match->arms->getItems() as $i => $arm) {
			[$labels, $body] = $arms[$i];
			foreach ($arm->values?->getItems() ?? [] as $j => $value) {
				$value->replaceWith($labels[$j]->withoutEdgeTrivia());
			}

			$arm->body->replaceWith($body->withoutEdgeTrivia());
		}

		$assignment = $statement instanceof Statement\ExpressionStatementNode ? $statement->expression : null;
		if ($assignment instanceof Expression\AssignmentNode) {
			$target = $arms[0][2];
			assert($target !== null);
			$assignment->target->replaceWith($target->withoutEdgeTrivia());
		}
	}


	/**
	 * The arms the cases of the switch make: the labels they share, the value they give and the variable
	 * they give it to, null for the return form and null altogether where the switch does anything else.
	 * @return ?list<array{list<ExpressionNode>, ExpressionNode, ?ExpressionNode}>
	 */
	private static function readArms(Statement\SwitchNode $switch): ?array
	{
		$cases = $switch->cases->getItems();
		$last = end($cases);
		if ($cases === [] || !$last instanceof CaseNode || $last->value !== null) {
			return null;
		}

		$arms = [];
		$labels = [];
		$target = null;
		foreach ($cases as $index => $case) {
			$statements = $case->statements->getItems();
			if ($case->value !== null && $statements === []) {
				$labels[] = $case->value; // the label shares the body of the case below it
				continue;
			} elseif ($case->value !== null) {
				$labels[] = $case->value;
			} elseif ($labels !== []) {
				return null; // a label falling through into the default would lose its answer
			}

			$body = self::readBody($statements, $index === count($cases) - 1);
			if ($body === null) {
				return null;
			}

			[$value, $assigned] = $body;
			if ($arms !== [] && ($assigned === null) !== ($target === null)) {
				return null; // the cases must answer in one way, all by assigning or all by returning
			} elseif ($assigned !== null && $target !== null && !$assigned->matches($target)) {
				return null;
			}

			$target ??= $assigned;
			$arms[] = [$labels, $value, $assigned];
			$labels = [];
		}

		return $arms;
	}


	/**
	 * The value a case gives and the variable it gives it to, null for a case that does anything else;
	 * the last case may leave out the break that the others must have.
	 * @param  list<StatementNode>  $statements
	 * @return ?array{ExpressionNode, ?ExpressionNode}
	 */
	private static function readBody(array $statements, bool $isLast): ?array
	{
		$first = $statements[0] ?? null;
		if ($first instanceof Statement\ReturnNode) {
			return count($statements) === 1 && $first->expression !== null ? [$first->expression, null] : null;
		}

		$assignment = $first instanceof Statement\ExpressionStatementNode ? $first->expression : null;
		$break = $statements[1] ?? null;
		$ends = $break instanceof Statement\BreakNode && $break->expression === null
			? count($statements) === 2
			: $isLast && count($statements) === 1;
		$target = $assignment instanceof Expression\AssignmentNode ? $assignment->target : null;
		return $assignment instanceof Expression\AssignmentNode
			&& $target instanceof ExpressionNode
			&& $target->isRepeatableRead()
			&& $ends
			? [$assignment->expression, $target]
			: null;
	}
}
