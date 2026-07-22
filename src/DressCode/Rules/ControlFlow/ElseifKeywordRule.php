<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ElseNode;
use PhpSyntax\Nodes\Statement\IfNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;


/**
 * The `elseif` keyword instead of `else if`; the branches of the inner `if` move to the outer one.
 * A comment between `else` and `if` keeps the pair apart.
 */
#[RuleInfo(
	'dresscode/elseif-keyword',
	Stage::Structure,
	description: 'Replaces else if with elseif',
)]
final class ElseifKeywordRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [ElseNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ElseNode
			|| !($inner = $node->body) instanceof IfNode
			|| $inner->body === null
			|| !($outer = $node->parent) instanceof IfNode
			|| $node->elseKeyword->hasCommentUpTo($inner->ifKeyword)
			|| !$context->report($node, "An 'else if' must be written 'elseif'")
		) {
			return;
		}

		$template = (new Parser)->parseStatement('if (0) {} elseif (0) {}');
		assert($template instanceof IfNode);
		$branch = clone $template->elseifs->getItems()[0];
		$branch->setOpenParen(clone $inner->openParen);
		$branch->setCond(clone $inner->cond);
		$branch->setCloseParen(clone $inner->closeParen);
		$branch->setBody(clone $inner->body);
		$branch->elseifKeyword->setLeadingTrivia($node->elseKeyword->leadingTrivia);
		$branch->elseifKeyword->setTrailingTrivia($inner->ifKeyword->trailingTrivia);

		$tail = array_map(fn($elseif) => clone $elseif, $inner->elseifs->getItems());
		$else = $inner->else ? clone $inner->else : null;
		$outer->setElse($else);
		$outer->elseifs->append($branch);
		foreach ($tail as $elseif) {
			$outer->elseifs->append($elseif);
		}
	}
}
