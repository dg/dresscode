<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ElseIfNode;
use PhpSyntax\Nodes\ElseNode;
use PhpSyntax\Nodes\Statement\BlockNode;
use PhpSyntax\Nodes\Statement\DoWhileNode;
use PhpSyntax\Nodes\Statement\EmptyStatementNode;
use PhpSyntax\Nodes\Statement\ForeachNode;
use PhpSyntax\Nodes\Statement\ForNode;
use PhpSyntax\Nodes\Statement\IfNode;
use PhpSyntax\Nodes\Statement\WhileNode;
use PhpSyntax\Nodes\StatementNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function count;


/**
 * The body of a control structure is always a block: `if ($a) x();` becomes `if ($a) {` … `}`.
 * An `else if` is left to the elseif rule, an empty statement body stays.
 */
#[RuleInfo(
	'dresscode/control-structure-braces',
	Stage::Structure,
	description: 'Encloses the body of every control structure in braces',
)]
final class ControlStructureBracesRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [IfNode::class, ElseIfNode::class, ElseNode::class, WhileNode::class, ForNode::class, ForeachNode::class, DoWhileNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$body = match (true) {
			$node instanceof IfNode,
			$node instanceof ElseIfNode,
			$node instanceof ElseNode,
			$node instanceof WhileNode,
			$node instanceof ForNode,
			$node instanceof ForeachNode,
			$node instanceof DoWhileNode => $node->body,
			default => null,
		};
		if (
			$body === null
			|| $body instanceof BlockNode
			|| $body instanceof EmptyStatementNode
			|| ($node instanceof ElseNode && $body instanceof IfNode)
			|| !$context->report($body, 'The body of a control structure must be enclosed in braces')
		) {
			return;
		}

		$this->wrap($body, $context);
	}


	private function wrap(StatementNode $body, RuleContext $context): void
	{
		$style = $context->getStyle();
		$first = $body->getFirstToken();
		$last = $body->getLastToken();
		$indentation = $first ? ($first->getPrevious() ?? $first)->getLineIndentation() : '';
		$ownLine = $first?->startsLine() ?? false;

		$block = (new Parser)->parseStatement('{}');
		if (!$block instanceof BlockNode) {
			return;
		}

		$trailing = $last ? $last->trailingTrivia : [];
		$body->replaceWith($block);
		$block->stmts->append($body);

		$before = $block->openBrace->getPrevious();
		$hasComment = false;
		foreach ($before ? $before->trailingTrivia : [] as $trivia) {
			$hasComment = $hasComment || $trivia->isComment();
		}

		if ($before && !$hasComment) {
			$before->setTrailingTrivia([new Trivia(TriviaKind::Whitespace, ' ')]);
			$block->openBrace->setLeadingTrivia([]);
		} else {
			$block->openBrace->setLeadingTrivia([new Trivia(TriviaKind::Whitespace, $indentation)]);
		}

		$block->openBrace->setTrailingTrivia([new Trivia(TriviaKind::EndOfLine, $style->eol)]);
		$block->closeBrace->setLeadingTrivia([new Trivia(TriviaKind::Whitespace, $indentation)]);
		$block->closeBrace->setTrailingTrivia([new Trivia(TriviaKind::EndOfLine, $style->eol)]);

		if ($first && $last) {
			$first->setLeadingTrivia([new Trivia(TriviaKind::Whitespace, $indentation . $style->indent)]);
			if (!$ownLine) {
				for ($token = $first->getNext(); $token && $token !== $last->getNext(); $token = $token->getNext()) {
					if ($token->startsLine()) {
						$token->setIndentation($style->indent . $token->getIndentation());
					}
				}
			}

			$eol = new Trivia(TriviaKind::EndOfLine, $style->eol);
			if ($trailing && $trailing[count($trailing) - 1]->isEndOfLine()) {
				$last->setTrailingTrivia($trailing);
				$last->removeTrailingWhitespace();
			} else { // something follows on the line: it follows the closing brace now
				$last->setTrailingTrivia([$eol]);
				$block->closeBrace->setTrailingTrivia($trailing);
			}
		}
	}
}
