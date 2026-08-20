<?php declare(strict_types=1);

namespace DressCode\Rules\Whitespace;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\CatchNode;
use PhpSyntax\Nodes\ElseIfNode;
use PhpSyntax\Nodes\ElseNode;
use PhpSyntax\Nodes\Expression\ClosureNode;
use PhpSyntax\Nodes\Expression\MatchNode;
use PhpSyntax\Nodes\FinallyNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\BlockNode;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Nodes\Statement\DeclareNode;
use PhpSyntax\Nodes\Statement\DoWhileNode;
use PhpSyntax\Nodes\Statement\EnumNode;
use PhpSyntax\Nodes\Statement\ForeachNode;
use PhpSyntax\Nodes\Statement\ForNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Nodes\Statement\IfNode;
use PhpSyntax\Nodes\Statement\InterfaceNode;
use PhpSyntax\Nodes\Statement\SwitchNode;
use PhpSyntax\Nodes\Statement\TraitNode;
use PhpSyntax\Nodes\Statement\TryNode;
use PhpSyntax\Nodes\Statement\WhileNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * Where the opening brace of a body goes: on its own line for classes and functions, on the line of the
 * declaration for control structures, closures and anonymous classes; what follows it starts a new line and
 * the closing brace one of its own, except in a single-line closure or an empty anonymous class. For
 * functions with parameters on several lines, the brace follows the closing parenthesis (PER), takes its own
 * line (multiLineParameters: nextLine), or does so only after a return type (nextLineAfterReturnType).
 */
#[RuleInfo(
	'dresscode/braces-position',
	Stage::Formatting,
	description: 'Positions the braces of classes, functions and control structures',
)]
final class BracesPositionRule extends Rule implements ConfigurableRule
{
	private const SameLine = 'sameLine';
	private const NextLine = 'nextLine';
	private const OwnLine = 'ownLine';

	private string $multiLineParameters = self::SameLine;
	private string $classes = self::NextLine;
	private string $anonymousClasses = self::SameLine;
	private string $anonymousFunctions = self::SameLine;
	private string $controlStructures = self::SameLine;
	private bool $allowSingleLineAnonymousFunctions = true;
	private string $emptyAnonymousClasses = self::SameLine;
	private string $emptyBodies = self::OwnLine;


	public static function getOptionsSchema(): Schema
	{
		$position = Expect::anyOf(self::SameLine, self::NextLine);
		return Expect::structure([
			'multiLineParameters' => Expect::anyOf(self::SameLine, self::NextLine, 'nextLineAfterReturnType')->default(self::SameLine)
				->description('Brace of a function whose parameters span several lines; nextLineAfterReturnType puts it on the next line only when there is a return type'),
			'classes' => (clone $position)->default(self::NextLine)->description('Classes, interfaces, traits and enums'),
			'anonymousClasses' => (clone $position)->default(self::SameLine),
			'anonymousFunctions' => (clone $position)->default(self::SameLine),
			'controlStructures' => (clone $position)->default(self::SameLine),
			'allowSingleLineAnonymousFunctions' => Expect::bool(true),
			'emptyAnonymousClasses' => Expect::anyOf(self::SameLine, self::OwnLine)->default(self::SameLine)
				->description('An empty anonymous class as {} on the line of new, whatever it holds inside'),
			'emptyBodies' => Expect::anyOf(self::SameLine, self::OwnLine)->default(self::OwnLine)
				->description('An empty body of a class, function, method or closure as {} on the line of its head; a comment inside makes it not empty'),
		]);
	}


	public function configure(array $options): void
	{
		$this->multiLineParameters = $options['multiLineParameters'];
		$this->classes = $options['classes'];
		$this->anonymousClasses = $options['anonymousClasses'];
		$this->anonymousFunctions = $options['anonymousFunctions'];
		$this->controlStructures = $options['controlStructures'];
		$this->allowSingleLineAnonymousFunctions = $options['allowSingleLineAnonymousFunctions'];
		$this->emptyAnonymousClasses = $options['emptyAnonymousClasses'];
		$this->emptyBodies = $options['emptyBodies'];
	}


	public function getVisitedTypes(): array
	{
		return [
			FunctionNode::class, MethodNode::class, ClosureNode::class,
			ClassNode::class, InterfaceNode::class, TraitNode::class, EnumNode::class, AnonymousClassNode::class,
			IfNode::class, ElseIfNode::class, ElseNode::class, ForNode::class, ForeachNode::class, WhileNode::class, DoWhileNode::class,
			SwitchNode::class, TryNode::class, CatchNode::class, FinallyNode::class, DeclareNode::class, MatchNode::class,
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		[$brace, $close] = $this->describeEmptyBody($node);
		$previous = $brace?->getPrevious();
		if (
			$brace === null
			|| $close === null
			|| $previous === null
			|| (
				$previous->getTrailingSpace() === ' '
				&& $brace->leadingTrivia === []
				&& $brace->getTrailingSpace() === ''
				&& $close->leadingTrivia === []
			)
			|| !$context->report($brace, "An empty body must be written '{}' on the line of the declaration")
		) {
			return;
		}

		$previous->setTrailingTrivia([new Trivia(TriviaKind::Whitespace, ' ')]);
		$brace->setLeadingTrivia([]);
		$brace->setTrailingTrivia([]);
		$close->setLeadingTrivia([]);
	}


	public function leave(Node|Token $node, RuleContext $context): void
	{
		[$brace, $close, $position, $allowSingleLine] = $this->describe($node);
		if ($brace === null || $close === null) {
			return;
		}

		$first = $node instanceof Node ? $node->getFirstToken() : null;
		if ($first === null) {
			return;
		}

		if ($allowSingleLine && self::isSingleLine($brace, $close)) {
			return;
		}

		if ($position === self::NextLine) {
			$this->moveToOwnLine($brace, $first->getLineIndentation(), $context);
		} else {
			$this->keepOnLine($brace, $context);
		}

		$this->breakAfter($brace, $first->getLineIndentation(), $context);
		$this->breakBefore($close, $first->getLineIndentation(), $context);
	}


	/**
	 * The braces of a body that emptyBodies wants collapsed to {}; a comment inside makes it not empty.
	 * @return array{?Token, ?Token}
	 */
	private function describeEmptyBody(Node|Token $node): array
	{
		[$open, $close, $empty] = match (true) {
			$node instanceof ClassNode, $node instanceof InterfaceNode, $node instanceof TraitNode, $node instanceof EnumNode
				=> [$node->openBrace, $node->closeBrace, $node->members->isEmpty()],
			$node instanceof AnonymousClassNode
				=> [$node->openBrace, $node->closeBrace, $node->members->isEmpty()],
			($node instanceof FunctionNode || $node instanceof MethodNode) && $node->body !== null
				=> [$node->body->openBrace, $node->body->closeBrace, $node->body->stmts->isEmpty()],
			$node instanceof ClosureNode
				=> [$node->body->openBrace, $node->body->closeBrace, $node->body->stmts->isEmpty()],
			default => [null, null, false],
		};
		return $this->emptyBodies === self::SameLine && $empty && $open !== null && $close !== null && !$open->hasCommentUpTo($close)
			? [$open, $close]
			: [null, null];
	}


	/**
	 * @return array{?Token, ?Token, string, bool}  opening brace, closing brace, position, single line allowed
	 */
	private function describe(Node|Token $node): array
	{
		return match (true) {
			$node instanceof FunctionNode, $node instanceof MethodNode => [
				$node->body?->openBrace,
				$node->body?->closeBrace,
				$node->closeParen->startsLine() && $this->isSameLineAfterMultilineParams($node) ? self::SameLine : self::NextLine,
				$node->body !== null && $this->isEmptyBody($node->body->openBrace, $node->body->stmts->isEmpty(), $node->body->closeBrace),
			],
			$node instanceof ClosureNode => [
				$node->body->openBrace,
				$node->body->closeBrace,
				$this->anonymousFunctions,
				$this->allowSingleLineAnonymousFunctions
					|| $this->isEmptyBody($node->body->openBrace, $node->body->stmts->isEmpty(), $node->body->closeBrace),
			],
			$node instanceof ClassNode, $node instanceof InterfaceNode, $node instanceof TraitNode, $node instanceof EnumNode
				=> [$node->openBrace, $node->closeBrace, $this->classes, $this->isEmptyBody($node->openBrace, $node->members->isEmpty(), $node->closeBrace)],
			$node instanceof AnonymousClassNode => [
				$node->openBrace,
				$node->closeBrace,
				self::hasWrappedImplements($node) ? self::NextLine : $this->anonymousClasses,
				$this->emptyAnonymousClasses === self::SameLine && $node->members->isEmpty(),
			],
			$node instanceof IfNode, $node instanceof ElseIfNode, $node instanceof ElseNode, $node instanceof ForNode,
			$node instanceof ForeachNode, $node instanceof WhileNode, $node instanceof DoWhileNode, $node instanceof DeclareNode
				=> $node->body instanceof BlockNode
					? [$node->body->openBrace, $node->body->closeBrace, $this->controlStructures, false]
					: [null, null, '', false],
			$node instanceof TryNode, $node instanceof CatchNode, $node instanceof FinallyNode
				=> [$node->body->openBrace, $node->body->closeBrace, $this->controlStructures, false],
			$node instanceof SwitchNode, $node instanceof MatchNode => [$node->openBrace, $node->closeBrace, $this->controlStructures, false],
			default => [null, null, '', false],
		};
	}


	private function isSameLineAfterMultilineParams(FunctionNode|MethodNode $node): bool
	{
		return match ($this->multiLineParameters) {
			self::SameLine => true,
			self::NextLine => false,
			default => $node->returnType === null,
		};
	}


	/** An implements list continued on further lines puts the brace of an anonymous class on its own line. */
	private static function hasWrappedImplements(AnonymousClassNode $node): bool
	{
		$last = $node->implements?->getLastToken();
		return $last !== null && $node->implementsKeyword?->getLine() !== $last->getLine();
	}


	/** Whether the body may stay {} on one line: the option allows it, nothing is inside, not even a comment. */
	private function isEmptyBody(Token $open, bool $noContent, Token $close): bool
	{
		return $this->emptyBodies === self::SameLine && $noContent && !$open->hasCommentUpTo($close);
	}


	private static function isSingleLine(Token $open, Token $close): bool
	{
		for ($token = $open; $token !== null && $token !== $close; $token = $token->getNext()) {
			foreach ($token->trailingTrivia as $trivia) {
				if ($trivia->isEndOfLine()) {
					return false;
				}
			}

			if (preg_match('~[\r\n]~', $token->text) && $token !== $open) {
				return false;
			}
		}

		return true;
	}


	private function moveToOwnLine(Token $brace, string $indentation, RuleContext $context): void
	{
		if ($brace->startsLine() && $brace->getIndentation() === $indentation) {
			return;
		}

		if ($context->report($brace, 'The opening brace must be on its own line')) {
			$brace->ensureLeadingNewline($context->getStyle()->eol);
			$brace->setIndentation($indentation);
		}
	}


	private function keepOnLine(Token $brace, RuleContext $context): void
	{
		$previous = $brace->getPrevious();
		if (
			!$brace->startsLine()
			|| $previous === null
			|| self::hasComment([...$previous->trailingTrivia, ...$brace->leadingTrivia])
		) {
			return;
		}

		if ($context->report($brace, 'The opening brace must be on the line of the declaration')) {
			$previous->setTrailingTrivia([]);
			$brace->setLeadingTrivia([new Trivia(TriviaKind::Whitespace, ' ')]);
		}
	}


	private function breakAfter(Token $brace, string $indentation, RuleContext $context): void
	{
		$next = $brace->getNext();
		if (
			$next === null
			|| $next->startsLine()
			|| $next->is(TokenKind::CloseTag)
			|| self::hasComment($brace->trailingTrivia)
			|| !$context->report($next, 'The content of a block must start on a new line')
		) {
			return;
		}

		$style = $context->getStyle();
		$brace->setTrailingTrivia([new Trivia(TriviaKind::EndOfLine, $style->eol)]);
		$next->setIndentation($next->is('}') ? $indentation : $indentation . $style->indent);
	}


	private function breakBefore(Token $brace, string $indentation, RuleContext $context): void
	{
		$previous = $brace->getPrevious();
		if (
			$brace->startsLine()
			|| $previous === null
			// what precedes is content, not code, and a line break would end up in the output
			|| $previous->is(TokenKind::InlineHtml, TokenKind::CloseTag)
			|| !$context->report($brace, 'The closing brace must be on its own line')
		) {
			return;
		}

		$brace->ensureLeadingNewline($context->getStyle()->eol);
		$brace->setIndentation($indentation);
	}


	/** @param list<Trivia> $trivia */
	private static function hasComment(array $trivia): bool
	{
		foreach ($trivia as $item) {
			if ($item->isComment()) {
				return true;
			}
		}

		return false;
	}
}
