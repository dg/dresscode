<?php declare(strict_types=1);

namespace DressCode\Rules\Variables;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\BinaryNode;
use PhpSyntax\Nodes\Expression\IssetNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;


/**
 * `isset($a) && isset($b)` becomes `isset($a, $b)`; the last isset() of a longer `&&` chain merges into
 * the one before it. A comment between the two stops the merge.
 */
#[RuleInfo(
	'dresscode/combined-issets',
	Stage::Structure,
	description: 'Asks about several variables in one isset() instead of a chain joined by &&',
)]
final class CombinedIssetsRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [BinaryNode::class];
	}


	public function leave(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof BinaryNode
			|| !$node->operator->is(TokenKind::BooleanAnd)
			|| !$node->right instanceof IssetNode
			|| !($target = self::findPrecedingIsset($node->left))
			|| $target->closeParen->hasCommentUpTo($node->right->closeParen)
			|| !$context->report($node->right, 'Consecutive isset() calls must be combined into one')
		) {
			return;
		}

		$replacement = clone $node->left;
		$merged = $replacement instanceof IssetNode ? $replacement : self::findPrecedingIsset($replacement);
		assert($merged !== null);
		foreach ($node->right->vars->getItems() as $var) {
			$var = clone $var;
			$var->getFirstToken()?->setLeadingTrivia([]);
			$var->getLastToken()?->removeTrailingWhitespace();
			$merged->vars->append($var);
		}

		$replacement->getFirstToken()?->setLeadingTrivia([]);
		$replacement->getLastToken()?->setTrailingTrivia([]);
		$node->replaceWith($replacement);
	}


	/** The isset() an isset() joined by && would merge into: the left operand itself, or the right end of a left-nested && chain. */
	private static function findPrecedingIsset(Node $left): ?IssetNode
	{
		return match (true) {
			$left instanceof IssetNode => $left,
			$left instanceof BinaryNode && $left->operator->is(TokenKind::BooleanAnd) && $left->right instanceof IssetNode => $left->right,
			default => null,
		};
	}
}
