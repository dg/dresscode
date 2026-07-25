<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ElseIfNode;
use PhpSyntax\Nodes\ElseNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\BlockNode;
use PhpSyntax\Nodes\Statement\DeclareNode;
use PhpSyntax\Nodes\Statement\EmptyStatementNode;
use PhpSyntax\Nodes\Statement\ForeachNode;
use PhpSyntax\Nodes\Statement\ForNode;
use PhpSyntax\Nodes\Statement\IfNode;
use PhpSyntax\Nodes\Statement\SwitchNode;
use PhpSyntax\Nodes\Statement\WhileNode;
use PhpSyntax\Nodes\StatementNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * Braces instead of the alternative syntax: `if (...) {` … `}`, not `if (...):` … `endif;`.
 */
#[RuleInfo(
	'dresscode/no-alternative-syntax',
	Stage::Structure,
	description: 'Replaces the alternative syntax with braces',
)]
final class NoAlternativeSyntaxRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [IfNode::class, WhileNode::class, ForNode::class, ForeachNode::class, SwitchNode::class, DeclareNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$colon = match (true) {
			$node instanceof IfNode,
			$node instanceof WhileNode,
			$node instanceof ForNode,
			$node instanceof ForeachNode,
			$node instanceof SwitchNode,
			$node instanceof DeclareNode => $node->colon,
			default => null,
		};
		$semicolon = match (true) {
			$node instanceof IfNode,
			$node instanceof WhileNode,
			$node instanceof ForNode,
			$node instanceof ForeachNode,
			$node instanceof SwitchNode,
			$node instanceof DeclareNode => $node->semicolon,
			default => null,
		};
		// a close tag closes the statement instead of a semicolon and the braces cannot swallow it
		$closeTag = $semicolon?->is(TokenKind::CloseTag) ? $semicolon : null;
		if (
			$colon === null
			|| ($closeTag !== null && !$node->parent instanceof NodeList)
			|| !$context->report($colon, 'The alternative syntax must be written with braces')
		) {
			return;
		}

		if ($node instanceof SwitchNode) {
			$this->rewriteSwitch($node, $closeTag);
		} elseif ($node instanceof IfNode) {
			$this->rewriteIf($node, $closeTag);
		} elseif (
			$node instanceof WhileNode
			|| $node instanceof ForNode
			|| $node instanceof ForeachNode
			|| $node instanceof DeclareNode
		) {
			$end = $node->endKeyword;
			if ($end === null || $node->colon === null || $node->stmts === null) {
				return;
			}

			$block = $this->buildBlock($node->colon, $node->stmts, $end->leadingTrivia, ($closeTag === null ? $semicolon ?? $end : $end)->trailingTrivia);
			$node->setColon(null);
			$node->setStmts(null);
			$node->setEndKeyword(null);
			$node->setSemicolon(null);
			$node->setBody($block);
			self::keepCloseTag($node, $closeTag);
		}
	}


	private function rewriteIf(IfNode $node, ?Token $closeTag): void
	{
		$end = $node->endKeyword;
		if ($end === null) {
			return;
		}

		$last = $closeTag === null ? $node->semicolon ?? $end : $end;

		$branches = [$node, ...$node->elseifs->getItems(), ...($node->else !== null ? [$node->else] : [])];
		foreach ($branches as $i => $branch) {
			if ($branch->colon === null || $branch->stmts === null) {
				continue;
			}

			$next = $branches[$i + 1] ?? null;
			$nextKeyword = match (true) {
				$next instanceof ElseIfNode => $next->elseifKeyword,
				$next instanceof ElseNode => $next->elseKeyword,
				default => null,
			};
			[$closeLeading, $closeTrailing] = $nextKeyword !== null
				? [$nextKeyword->leadingTrivia, [new Trivia(TriviaKind::Whitespace, ' ')]]
				: [$end->leadingTrivia, $last->trailingTrivia];
			$block = $this->buildBlock($branch->colon, $branch->stmts, $closeLeading, $closeTrailing);
			$nextKeyword?->setLeadingTrivia([]);
			$branch->setColon(null);
			$branch->setStmts(null);
			$branch->setBody($block);
		}

		$node->setEndKeyword(null);
		$node->setSemicolon(null);
		self::keepCloseTag($node, $closeTag);
	}


	private function rewriteSwitch(SwitchNode $node, ?Token $closeTag): void
	{
		$end = $node->endKeyword;
		$colon = $node->colon;
		if ($end === null || $colon === null) {
			return;
		}

		$last = $closeTag === null ? $node->semicolon ?? $end : $end;
		$openBrace = new Token(ord('{'), '{');
		$openBrace->setLeadingTrivia(self::braceLeading($colon));
		$openBrace->setTrailingTrivia($colon->trailingTrivia);
		$closeBrace = new Token(ord('}'), '}');
		$closeBrace->setLeadingTrivia($end->leadingTrivia);
		$closeBrace->setTrailingTrivia($last->trailingTrivia);
		$node->setColon(null);
		$node->setEndKeyword(null);
		$node->setSemicolon(null);
		$node->setOpenBrace($openBrace);
		$node->setCloseBrace($closeBrace);
		self::keepCloseTag($node, $closeTag);
	}


	/**
	 * The close tag that ended the alternative syntax lives on as a statement of its own,
	 * the way the parser reads it after a block.
	 */
	private static function keepCloseTag(StatementNode $node, ?Token $closeTag): void
	{
		if ($closeTag === null || !($list = $node->parent) instanceof NodeList) {
			return;
		}

		$statement = (new Parser)->parseStatement('?' . '>');
		assert($statement instanceof EmptyStatementNode);
		$statement->semicolon->setText($closeTag->text);
		$statement->semicolon->setLeadingTrivia($closeTag->leadingTrivia);
		$statement->semicolon->setTrailingTrivia($closeTag->trailingTrivia);
		$list->insert($list->indexOf($node) + 1, $statement);
	}


	/**
	 * @param NodeList<StatementNode> $stmts
	 * @param list<Trivia> $closeLeading
	 * @param list<Trivia> $closeTrailing
	 */
	private function buildBlock(Token $colon, NodeList $stmts, array $closeLeading, array $closeTrailing): BlockNode
	{
		$block = (new Parser)->parseStatement('{}');
		assert($block instanceof BlockNode);
		$block->openBrace->setLeadingTrivia(self::braceLeading($colon));
		$block->openBrace->setTrailingTrivia($colon->trailingTrivia);
		$block->closeBrace->setLeadingTrivia($closeLeading);
		$block->closeBrace->setTrailingTrivia($closeTrailing);
		foreach ($stmts->getItems() as $stmt) {
			$stmts->removeItem($stmt);
			$block->stmts->append($stmt);
		}

		return $block;
	}


	/**
	 * The colon sits right after the closing parenthesis; the brace replacing it wants a space before.
	 * @return list<Trivia>
	 */
	private static function braceLeading(Token $colon): array
	{
		return $colon->getPrevious()?->getTrailingSpace() === '' && $colon->leadingTrivia === []
			? [new Trivia(TriviaKind::Whitespace, ' ')]
			: $colon->leadingTrivia;
	}
}
