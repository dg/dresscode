<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\BinaryNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;


/**
 * Strict comparison everywhere: `===` for `==`, `!==` for `!=` and `<>`. Risky: a loose comparison that
 * relied on type juggling changes its result.
 */
#[RuleInfo(
	'dresscode/strict-comparison',
	Stage::Structure,
	description: 'Replaces loose comparisons with strict ones',
)]
final class StrictComparisonRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [BinaryNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof BinaryNode) {
			return;
		}

		[$kind, $text] = match ($node->operator->kind) {
			TokenKind::IsEqual => [TokenKind::IsIdentical, '==='],
			TokenKind::IsNotEqual => [TokenKind::IsNotIdentical, '!=='],
			default => [null, null],
		};
		if (
		    $kind === null
		    || $text === null
		    || !$context->report($node->operator, "The {$node->operator->text} comparison must be written '$text'")
		) {
			return;
		}

		$operator = new Token($kind, $text);
		$operator->setLeadingTrivia($node->operator->leadingTrivia);
		$operator->setTrailingTrivia($node->operator->trailingTrivia);
		$node->setOperator($operator);
	}
}
