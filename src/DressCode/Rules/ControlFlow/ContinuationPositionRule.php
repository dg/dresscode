<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\CatchNode;
use PhpSyntax\Nodes\ElseIfNode;
use PhpSyntax\Nodes\ElseNode;
use PhpSyntax\Nodes\FinallyNode;
use PhpSyntax\Nodes\Statement\DoWhileNode;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * The keyword continuing a control structure (`else`, `elseif`, `catch`, `finally`, the `while` of `do`)
 * on the line of the closing brace before it.
 */
#[RuleInfo(
	'dresscode/continuation-position',
	Stage::Formatting,
	description: 'Puts else, elseif, catch, finally and the while of do on the line of the closing brace',
)]
final class ContinuationPositionRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [ElseIfNode::class, ElseNode::class, CatchNode::class, FinallyNode::class, DoWhileNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$keyword = match (true) {
			$node instanceof ElseIfNode => $node->elseifKeyword,
			$node instanceof ElseNode => $node->elseKeyword,
			$node instanceof CatchNode => $node->catchKeyword,
			$node instanceof FinallyNode => $node->finallyKeyword,
			$node instanceof DoWhileNode => $node->whileKeyword,
			default => null,
		};
		$brace = $keyword?->getPrevious();
		if ($keyword === null || $brace === null || !$brace->is('}') || !$keyword->startsLine()) {
			return;
		}

		foreach ([...$brace->trailingTrivia, ...$keyword->leadingTrivia] as $trivia) {
			if ($trivia->isComment()) {
				return;
			}
		}

		if ($context->report($keyword, "The $keyword->text keyword must be on the line of the closing brace")) {
			$brace->setTrailingTrivia([]);
			$keyword->setLeadingTrivia([new Trivia(TriviaKind::Whitespace, ' ')]);
		}
	}
}
