<?php declare(strict_types=1);

namespace DressCode\Rules\Whitespace;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Indentation;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\CaseNode;
use PhpSyntax\Nodes\CatchNode;
use PhpSyntax\Nodes\ElseIfNode;
use PhpSyntax\Nodes\ElseNode;
use PhpSyntax\Nodes\Expression\MatchNode;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\FinallyNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\TriviaKind;
use function count;


/**
 * Every statement, member, case and closing brace indented by the nesting of blocks, one unit of the style
 * per level, counted from the line that opens the block. Comments on their own lines follow the statement
 * below them, a comment before a closing brace the content of the block. Lines continuing a statement move
 * along with its first line; the content of strings, heredocs and inline HTML never changes.
 */
#[RuleInfo(
	'dresscode/statement-indentation',
	Stage::Formatting,
	description: 'Indents statements by the nesting of blocks with the indentation of the style',
	modifiesComments: true,
)]
final class StatementIndentationRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [
			FileNode::class, Statement\NamespaceNode::class, Statement\BlockNode::class,
			Statement\ClassNode::class, Statement\InterfaceNode::class, Statement\TraitNode::class, Statement\EnumNode::class, AnonymousClassNode::class,
			Statement\SwitchNode::class, CaseNode::class, MatchNode::class,
			Statement\IfNode::class, ElseIfNode::class, ElseNode::class, Statement\WhileNode::class, Statement\ForNode::class, Statement\ForeachNode::class, Statement\DeclareNode::class,
			CatchNode::class, FinallyNode::class, Statement\DoWhileNode::class,
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$style = $context->getStyle();
		match (true) {
			$node instanceof FileNode => $this->indentChildren($node->stmts->getItems(), '', $context),
			$node instanceof Statement\NamespaceNode => $node->openBrace
				? $this->indentBlock($node->openBrace, $node->stmts->getItems(), $node->closeBrace, $context)
				: $this->indentChildren($node->stmts->getItems(), '', $context),
			$node instanceof Statement\BlockNode => $this->indentBlock($node->openBrace, $node->stmts->getItems(), $node->closeBrace, $context),
			$node instanceof Statement\ClassNode, $node instanceof Statement\InterfaceNode, $node instanceof Statement\TraitNode, $node instanceof Statement\EnumNode, $node instanceof AnonymousClassNode
				=> $this->indentBlock($node->openBrace, $node->members->getItems(), $node->closeBrace, $context),
			$node instanceof Statement\SwitchNode => $this->indentSwitch($node, $context),
			$node instanceof CaseNode => $this->indentChildren($node->stmts->getItems(), Indentation::normalize($node->caseKeyword->getLineIndentation(), $style) . $style->indent, $context),
			$node instanceof MatchNode => $this->indentBlock($node->openBrace, $node->arms->getItems(), $node->closeBrace, $context),
			$node instanceof Statement\IfNode, $node instanceof ElseIfNode, $node instanceof ElseNode, $node instanceof Statement\WhileNode,
			$node instanceof Statement\ForNode, $node instanceof Statement\ForeachNode, $node instanceof Statement\DeclareNode
				=> $this->indentAlternative($node, $context),
			$node instanceof CatchNode => $this->indentKeyword($node->catchKeyword, $context),
			$node instanceof FinallyNode => $this->indentKeyword($node->finallyKeyword, $context),
			$node instanceof Statement\DoWhileNode => $this->indentKeyword($node->whileKeyword, $context),
			default => null,
		};
	}


	/**
	 * @param list<Node> $children
	 */
	private function indentBlock(?Token $open, array $children, ?Token $close, RuleContext $context): void
	{
		if ($open === null) {
			return;
		}

		$style = $context->getStyle();
		$owner = $open->parent instanceof Statement\BlockNode ? $open->parent->parent : $open->parent;
		$anchor = $owner instanceof NodeList || $owner === null ? $open : $owner->getFirstToken() ?? $open;
		$base = Indentation::normalize($anchor->getLineIndentation(), $style);
		if ($anchor !== $open && $open->startsLine()) {
			$this->reindent($open, $base, $base, null, $context);
		}

		$this->indentChildren($children, $base . $style->indent, $context);
		if ($close !== null) {
			// a comment above a closing brace followed by the next branch introduces that branch,
			// unless it is the only content of the block
			$continues = $children !== []
				&& ($close->getNext()?->is(TokenKind::Else, TokenKind::Elseif, TokenKind::Catch, TokenKind::Finally, TokenKind::While) ?? false);
			$this->reindent($close, $base, $continues ? $base : $base . $style->indent, null, $context);
		}
	}


	/** A comment above a later case belongs to the statements of the previous one and indents with them. */
	private function indentSwitch(Statement\SwitchNode $node, RuleContext $context): void
	{
		$style = $context->getStyle();
		$base = Indentation::normalize($node->switchKeyword->getLineIndentation(), $style);
		$level = $base . $style->indent;
		foreach ($node->cases->getItems() as $i => $case) {
			$first = $case->getFirstToken();
			if ($first !== null) {
				$this->reindent($first, $level, $i === 0 ? $level : $level . $style->indent, $case->getLastToken(), $context);
			}
		}

		$close = $node->closeBrace ?? $node->endKeyword;
		if ($close !== null) {
			$this->reindent($close, $base, $level, null, $context);
		}
	}


	private function indentAlternative(
		Statement\IfNode|ElseIfNode|ElseNode|Statement\WhileNode|Statement\ForNode|Statement\ForeachNode|Statement\DeclareNode $node,
		RuleContext $context,
	): void
	{
		$keyword = $node->getFirstToken();
		if ($node instanceof ElseIfNode || $node instanceof ElseNode) {
			$this->indentKeyword($keyword, $context);
		}

		if ($node->colon === null || $node->stmts === null || $keyword === null) {
			return;
		}

		$style = $context->getStyle();
		$base = Indentation::normalize($keyword->getLineIndentation(), $style);
		$this->indentChildren($node->stmts->getItems(), $base . $style->indent, $context);
		$end = match (true) {
			$node instanceof Statement\IfNode => $node->elseifs->isEmpty() && $node->else === null ? $node->endKeyword : null,
			$node instanceof ElseIfNode, $node instanceof ElseNode => null,
			default => $node->endKeyword,
		};
		if ($end !== null) {
			$this->reindent($end, $base, $base . $style->indent, null, $context);
		}
	}


	/**
	 * A keyword continuing a structure (else, catch, while of do) on its own line sits where the structure starts.
	 */
	private function indentKeyword(?Token $keyword, RuleContext $context): void
	{
		$structure = $keyword?->parent;
		while (
			$structure !== null
			&& !$structure instanceof Statement\IfNode
			&& !$structure instanceof Statement\TryNode
			&& !$structure instanceof Statement\DoWhileNode
		) {
			$structure = $structure->parent;
		}

		$first = $structure?->getFirstToken();
		if ($keyword === null || $first === null || !$keyword->startsLine()) {
			return;
		}

		$style = $context->getStyle();
		$base = Indentation::normalize($first->getLineIndentation(), $style);
		$this->reindent($keyword, $base, $base . $style->indent, null, $context);
	}


	/**
	 * @param list<Node> $children
	 */
	private function indentChildren(array $children, string $indentation, RuleContext $context): void
	{
		foreach ($children as $child) {
			$first = $child->getFirstToken();
			if ($first === null || $child instanceof Statement\InlineHtmlNode || self::followsOpenTag($first)) {
				continue;
			}

			$last = $child instanceof Statement\NamespaceNode && $child->openBrace === null ? null : $child->getLastToken();
			$this->reindent($first, $indentation, $indentation, $last, $context);
		}
	}


	private function reindent(
		Token $token,
		string $indentation,
		string $commentIndentation,
		?Token $last,
		RuleContext $context,
	): void
	{
		if (!$token->startsLine() || Indentation::has($token, $indentation, $commentIndentation)) {
			return;
		}

		$leading = $token->leadingTrivia;
		$at = ($leading[count($leading) - 1] ?? null)?->kind === TriviaKind::Whitespace ? $leading[count($leading) - 1] : ($leading[0] ?? null);
		if ($context->report($token, 'Wrong indentation of a statement', trivia: $at)) {
			Indentation::set($token, $indentation, $commentIndentation, $last, $context->getStyle());
		}
	}


	private static function followsOpenTag(Token $token): bool
	{
		foreach ($token->leadingTrivia as $trivia) {
			if ($trivia->kind === TriviaKind::OpenTag) {
				return !$trivia->isEndOfLine();
			}
		}

		return false;
	}
}
