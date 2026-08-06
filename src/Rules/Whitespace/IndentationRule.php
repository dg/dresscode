<?php declare(strict_types=1);

namespace DressCode\Rules\Whitespace;

use DressCode\ConfigurableRule;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Indentation;
use PhpSyntax\LayoutRole;
use PhpSyntax\Node;
use PhpSyntax\Nodes;
use PhpSyntax\Nodes\Member\PropertyHookNode;
use PhpSyntax\Nodes\Statement\BlockNode;
use PhpSyntax\Nodes\Statement\NamespaceNode;
use PhpSyntax\Style;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use function count;


/**
 * Every line indented by the construct it continues: what a construct holds stands one level below the line
 * the construct begins on, what closes or continues the construct stands at that line, and the level is
 * counted from the level the construct itself was given, never read from the text around it. Which part of
 * a construct a line is comes from the layout role of the slot it opens (PhpSyntax\LayoutData); an operator,
 * a ternary branch, a link of a chain and the cases of a switch step in as the options say. A comment on
 * a line of its own stands with the line below it, above a closing bracket with the content it closes. The
 * content of strings, heredocs and inline HTML is text and never changes.
 */
#[RuleInfo(
	'dresscode/indentation',
	Stage::Cleanup,
	description: 'Indents every line by the construct it continues, one level per nesting',
	modifiesComments: true,
)]
final class IndentationRule extends NodeRule implements ConfigurableRule
{
	private ?int $binary = 0;
	private ?int $ternary = 1;
	private int $switchCases = 1;
	private ?string $chain = 'single';

	/** @var array<int, string>  line → the indentation it is given, or has where nothing governs it */
	private array $lines = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'binary' => Expect::int(0)->min(0)->max(1)->nullable()
				->description('Levels a binary operator opening a line steps in by when its expression has a line of its own; null leaves such lines alone'),
			'ternary' => Expect::int(1)->min(0)->max(1)->nullable()
				->description('Levels the ? and : of a ternary opening a line step in by; null leaves them alone'),
			'switchCases' => Expect::int(1)->min(0)->max(1)
				->description('Levels the cases of a switch step in by'),
			'chain' => Expect::anyOf('single', 'nesting')->default('single')->nullable()
				->description('single puts every link of a chain one level below its start, nesting lets a link stand one level deeper or shallower than the link before it; null leaves chains alone'),
		]);
	}


	public function configure(array $options): void
	{
		$this->binary = $options['binary'];
		$this->ternary = $options['ternary'];
		$this->switchCases = $options['switchCases'];
		$this->chain = $options['chain'];
	}


	public function getVisitedTypes(): array
	{
		return [Nodes\FileNode::class];
	}


	/**
	 * One walk over the tokens in their order: the line a construct begins on is placed before any line
	 * continuing it, so the level of the latter counts from what the former was given.
	 */
	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Nodes\FileNode) {
			return;
		}

		$this->lines = [];
		$style = $context->getStyle();
		$previous = null;
		foreach ($node->getIndex()->getTokens() as $token) {
			// what Indentation::opensLine() asks, with the previous token at hand: most tokens carry no trivia at all
			$trailing = $previous === null ? [] : $previous->trailingTrivia;
			$last = $trailing[count($trailing) - 1] ?? null;
			$opens = $last !== null && $last->isEndOfLine() && !$last->inInterpolation;
			foreach ($opens ? [] : $token->leadingTrivia as $trivia) {
				if ($trivia->isEndOfLine() && !$trivia->inInterpolation) {
					$opens = true;
					break;
				}
			}

			if ($opens) {
				$this->place($token, $style, $context);
			} elseif ($previous === null || preg_match('~[\r\n]$~', $previous->text) === 1) {
				// a line the text opens (inline HTML, a heredoc, a close tag) is a fact the lines below count from
				$this->lines[$token->getLine() ?? 0] = Indentation::normalize($token->getIndentation(), $style);
			}

			$previous = $token;
		}
	}


	/** Places a token that opens a line by the construct it continues. */
	private function place(Token $token, Style $style, RuleContext $context): void
	{
		$line = $token->getLine() ?? 0;
		[$owner, $child] = Indentation::findOwner($token);
		if ($owner === null) { // the first token of the file
			$this->lines[$line] = '';
			$this->indent($token, '', '', 'a statement', $context);
			return;
		}

		[$role, $item] = Indentation::findRole($owner, $child, $token);
		// the content of a body counts from the line its structure begins on, wherever the brace stands
		$structure = $owner instanceof BlockNode && !$owner->parent instanceof Nodes\NodeList ? $owner->parent : $owner;
		$structure = $role === LayoutRole::Operator ? self::chainStart($structure) : $structure;
		$first = $structure?->getFirstToken() ?? $token;
		$base = $this->lines[$first->getLine() ?? 0] ?? Indentation::normalize($first->getLineIndentation(), $style);
		$level = match ($role) {
			LayoutRole::Content => $owner instanceof NamespaceNode && $owner->openBrace === null ? 0 : 1,
			LayoutRole::Anchor, LayoutRole::Closes => 0,
			LayoutRole::Body => $child instanceof BlockNode ? 0 : 1,
			// an expression sharing the line with what holds it has no line of its own to line up with
			LayoutRole::Operator => $this->binary === null ? null : max($this->binary, $first->startsLine() ? 0 : 1),
			LayoutRole::Branch => $this->ternary === null ? null : max($this->ternary, $first->startsLine() ? 0 : 1),
			LayoutRole::Link => match ($this->chain) {
				null => null,
				'nesting' => $this->nestingLevel($owner, $token, $base, $style),
				default => 1,
			},
			LayoutRole::Case => $this->switchCases,
		};
		if ($level === null) { // left alone by the options: what the line has is what the lines below count from
			$this->lines[$line] = Indentation::normalize($token->getIndentation(), $style);
			return;
		}

		$indentation = $base . $style->indent($level);
		$this->lines[$line] = $indentation;
		// a comment above a closing bracket belongs to the content before it, unless the bracket is followed
		// by the next branch of a non-empty structure, which the comment then introduces; a comment above
		// a later case belongs to the statements of the previous one
		$commentIndentation = match (true) {
			$role === LayoutRole::Closes && !(self::continuesStructure($token) && !$token->getPrevious()?->is('{')),
			$role === LayoutRole::Case && !self::isFirstItem($child, $token)
				=> $indentation . $style->indent,
			default => $indentation,
		};
		$this->indent($token, $indentation, $commentIndentation, self::describe($role, $item, $token), $context);
	}


	private function indent(
		Token $token,
		string $indentation,
		string $commentIndentation,
		string $subject,
		RuleContext $context,
	): void
	{
		if (Indentation::has($token, $indentation, $commentIndentation)) {
			return;
		}

		$subject = $token->getIndentation() === $indentation ? 'a comment' : $subject;
		if ($context->report($token, "Wrong indentation of $subject", trivia: Indentation::findTrivia($token))) {
			Indentation::set($token, $indentation, $commentIndentation);
		}
	}


	/**
	 * The outermost expression of a chain of one right-associative operator. `$a ?? $b ?? $c` is two nested
	 * nodes, the second operator belonging to the inner one, but the reader sees one chain and every operator
	 * of it counts from the line the first operand stands on; a chain the source parenthesizes has a node
	 * between the two and is left alone.
	 */
	private static function chainStart(?Node $node): ?Node
	{
		while (
			$node instanceof Nodes\Expression\BinaryOpNode
			&& $node->parent instanceof Nodes\Expression\BinaryOpNode
			&& $node->parent->right === $node
			&& $node->parent->operator->text === $node->operator->text
		) {
			$node = $node->parent;
		}

		return $node;
	}


	/** Whether the next token continues the structure the token closes: else, elseif, catch, finally, the while of a do. */
	private static function continuesStructure(Token $token): bool
	{
		return $token->getNext()?->is(TokenKind::Else, TokenKind::Elseif, TokenKind::Catch, TokenKind::Finally, TokenKind::While) ?? false;
	}


	private static function isFirstItem(Node|Token $list, Token $token): bool
	{
		$first = $list instanceof Nodes\NodeList ? $list->getChildren()[0] ?? null : null;
		return $first instanceof Node && $first->getFirstToken() === $token;
	}


	/**
	 * A link of a chain may stand one level deeper than the link before it, or one level shallower; the first
	 * link and a step of more than one level are pulled into place.
	 */
	private function nestingLevel(Node $owner, Token $operator, string $base, Style $style): int
	{
		$unit = Indentation::width($style->indent, $style);
		$deeper = Indentation::width($operator->getIndentation(), $style) - Indentation::width($base, $style);
		$actual = $deeper > 0 ? intdiv($deeper, $unit) : 0;
		$previous = 0;
		for ($link = $owner instanceof Nodes\ExpressionNode ? self::innerLink($owner) : null; $link !== null; $link = self::innerLink($link)) {
			$before = $link instanceof Nodes\Expression\MethodCallNode || $link instanceof Nodes\Expression\PropertyFetchNode ? $link->operator : null;
			if ($before?->startsLine()) {
				$given = $this->lines[$before->getLine() ?? 0] ?? $base;
				$previous = intdiv(Indentation::width($given, $style) - Indentation::width($base, $style), $unit);
				break;
			}
		}

		return min(max($actual, $previous - 1, 1), $previous + 1);
	}


	/** The expression the node is applied to when it is a link of a chain, null when the node begins one. */
	private static function innerLink(Nodes\ExpressionNode $node): ?Nodes\ExpressionNode
	{
		return match (true) {
			$node instanceof Nodes\Expression\MethodCallNode, $node instanceof Nodes\Expression\PropertyFetchNode => $node->object,
			$node instanceof Nodes\Expression\ArrayAccessNode => $node->expression,
			$node instanceof Nodes\Expression\FunctionCallNode => $node->name instanceof Nodes\ExpressionNode ? $node->name : null,
			$node instanceof Nodes\Expression\StaticMethodCallNode,
			$node instanceof Nodes\Expression\StaticPropertyFetchNode,
			$node instanceof Nodes\Expression\ClassConstantFetchNode
				=> $node->class instanceof Nodes\ExpressionNode ? $node->class : null,
			default => null,
		};
	}


	private static function describe(LayoutRole $role, Node|Token $item, Token $token): string
	{
		return match (true) {
			$role === LayoutRole::Closes => ctype_alpha($token->text) ? "the $token->text keyword" : 'a closing ' . match ($token->text) {
				'}' => 'brace',
				')' => 'parenthesis',
				default => 'bracket',
			},
			$role === LayoutRole::Link => 'a chained call',
			$role === LayoutRole::Operator, $role === LayoutRole::Branch => 'a continued expression',
			$item instanceof BlockNode, $token->is('{') => 'an opening brace',
			$item instanceof Nodes\CaseNode => 'a case',
			$item instanceof Nodes\StatementNode => 'a statement',
			$item instanceof Nodes\MemberNode => 'a member',
			$item instanceof Nodes\ParameterNode => 'a parameter',
			$item instanceof Nodes\ArgumentNode, $item instanceof Nodes\VariadicPlaceholderNode => 'an argument',
			$item instanceof Nodes\ArrayItemNode, $item instanceof Nodes\EmptyArrayItemNode => 'an array item',
			$item instanceof Nodes\MatchArmNode => 'a match arm',
			$item instanceof Nodes\AttributeGroupNode, $item instanceof Nodes\AttributeNode => 'an attribute',
			$item instanceof PropertyHookNode => 'a property hook',
			$token->is(TokenKind::CloseTag) => 'a close tag',
			$item instanceof Token => ctype_alpha($token->text) ? "the $token->text keyword" : "the $token->text operator",
			$item instanceof Nodes\ExpressionNode => 'a continued expression',
			$item->parent instanceof Nodes\NodeList, $item->parent instanceof Nodes\SeparatedNodeList => 'a list item',
			default => 'a continuation line',
		};
	}
}
