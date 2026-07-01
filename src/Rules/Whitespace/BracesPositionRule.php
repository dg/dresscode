<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, ConfigurableRule, Gap, GapRule, Line, RuleInfo, Space, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, Nodes, Token};
use PhpSyntax\Nodes\Expression\{ClosureNode, MatchNode};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyHookNode, PropertyNode};
use PhpSyntax\Nodes\{ParameterNode, Statement};


/**
 * Where the opening brace of a body goes: on its own line for classes and functions, on the line of the
 * declaration for control structures, closures, anonymous classes and property hooks; what follows it starts
 * a new line and the closing brace one of its own, except in a single-line closure, an empty anonymous class
 * or an abbreviated list of hooks (`{ get; set; }`), whose hooks otherwise take a line each. A function with
 * parameters on several lines has an option of its own for the brace. The keyword that continues a structure
 * (`else`, `catch`, the `while` of `do`) meets the closing brace on its line or takes the next one. Where the
 * lines then stand is the matter of dresscode/indentation.
 */
#[RuleInfo(
	'dresscode/bracesPosition',
	Stage::Formatting,
	description: 'Positions the braces of classes, functions and control structures, and the keywords between them',
)]
final class BracesPositionRule extends GapRule implements ConfigurableRule
{
	private const SameLine = 'sameLine';
	private const NextLine = 'nextLine';
	private const OwnLine = 'ownLine';
	private const Keep = 'keep';
	private const Always = 'always';

	private const ClassLikes = [
		Statement\ClassNode::class,
		Statement\InterfaceNode::class,
		Statement\TraitNode::class,
		Statement\EnumNode::class,
	];
	private const Bodied = [
		Statement\FunctionNode::class, MethodNode::class, ClosureNode::class,
		Statement\IfNode::class, Nodes\ElseifNode::class, Nodes\ElseNode::class, Statement\ForNode::class, Statement\ForeachNode::class,
		Statement\WhileNode::class, Statement\DoWhileNode::class, Statement\DeclareNode::class,
		Statement\TryNode::class, Nodes\CatchNode::class, Nodes\FinallyNode::class, PropertyHookNode::class,
	];
	private const Hooked = [PropertyNode::class, ParameterNode::class];

	private string $multilineParameters = self::SameLine;
	private string $class = self::NextLine;
	private string $anonymousClass = self::SameLine;
	private string $anonymousFunction = self::SameLine;
	private string $controlStructure = self::SameLine;
	private string $singlelineAnonymousFunction = self::Keep;
	private string $emptyAnonymousClass = self::SameLine;
	private string $emptyBody = self::OwnLine;
	private string $continuation = self::SameLine;


	public static function getOptionsSchema(): Schema
	{
		$position = Expect::anyOf(self::SameLine, self::NextLine);
		return Expect::structure([
			'multilineParameters' => Expect::anyOf(self::SameLine, self::NextLine, 'nextLineAfterReturnType')->default(self::SameLine)
				->description('Brace of a function whose parameters span several lines; `nextLineAfterReturnType` puts it on the next line only when there is a return type'),
			'class' => (clone $position)->default(self::NextLine)->description('Classes, interfaces, traits and enums'),
			'anonymousClass' => (clone $position)->default(self::SameLine),
			'anonymousFunction' => (clone $position)->default(self::SameLine),
			'controlStructure' => (clone $position)->default(self::SameLine),
			'singlelineAnonymousFunction' => Expect::anyOf(self::Always, self::Keep)->default(self::Keep)
				->description('A closure written whole on one line: `always` gives its braces the position of any other, `keep` leaves it there'),
			'emptyAnonymousClass' => Expect::anyOf(self::SameLine, self::OwnLine)->default(self::SameLine)
				->description('An empty anonymous class as `{}` on the line of `new`, whatever it holds inside'),
			'emptyBody' => Expect::anyOf(self::SameLine, self::OwnLine)->default(self::OwnLine)
				->description('An empty body of a class, function, method or closure as `{}` on the line of its head; a comment inside makes it not empty'),
			'continuation' => (clone $position)->default(self::SameLine)
				->description('The keyword continuing a structure (`else`, `elseif`, `catch`, `finally`, the `while` of `do`) on the line of the closing brace, or on the next one'),
		]);
	}


	public function configure(array $options): void
	{
		$this->multilineParameters = $options['multilineParameters'];
		$this->class = $options['class'];
		$this->anonymousClass = $options['anonymousClass'];
		$this->anonymousFunction = $options['anonymousFunction'];
		$this->controlStructure = $options['controlStructure'];
		$this->singlelineAnonymousFunction = $options['singlelineAnonymousFunction'];
		$this->emptyAnonymousClass = $options['emptyAnonymousClass'];
		$this->emptyBody = $options['emptyBody'];
		$this->continuation = $options['continuation'];
	}


	public function getClaims(): array
	{
		$braces = [
			'openBrace' => [
				fn(Gap $gap) => $this->beforeOpening($gap->token->parent),
				fn(Gap $gap) => $this->afterOpening($gap->token->parent),
			],
			'closeBrace' => [fn(Gap $gap) => $this->beforeClosing($gap->token->parent), null],
		];
		$claims = [
			Nodes\AnonymousClassNode::class => $braces,
			Statement\SwitchNode::class => $braces,
			MatchNode::class => $braces,
			// the braces of a block are claimed through the block, whose owner says whether it is a body
			Statement\BlockNode::class => [
				'openBrace' => [null, fn(Gap $gap) => $this->afterOpening($gap->token->parent?->parent)],
				'closeBrace' => [fn(Gap $gap) => $this->beforeClosing($gap->token->parent?->parent), null],
			],
		];
		foreach (self::ClassLikes as $class) {
			$claims[$class] = $braces;
		}

		foreach (self::Bodied as $class) {
			$claims[$class]['body'] = [fn(Gap $gap) => $this->beforeOpening($gap->value->parent), null];
		}

		// the braces around the hooks of a property, and every hook on a line of its own between them
		foreach (self::Hooked as $class) {
			$claims[$class] = $braces + [
				'hooks:item' => [fn(Gap $gap) => $this->afterOpening($gap->value->parent?->parent), null],
			];
		}

		// the keyword that continues a structure meets the brace that closed the part before it;
		// a body without braces keeps the keyword where it is
		$wanted = $this->continuation === self::SameLine ? new Claim(Space::Single, line: Line::Same) : Claim::nextLine();
		$continues = [fn(Gap $gap) => $gap->token->getPrevious()?->is('}') ?? false ? $wanted : null, null];
		$claims[Nodes\ElseifNode::class]['elseifKeyword'] = $continues;
		$claims[Nodes\ElseNode::class]['elseKeyword'] = $continues;
		$claims[Nodes\CatchNode::class]['catchKeyword'] = $continues;
		$claims[Nodes\FinallyNode::class]['finallyKeyword'] = $continues;
		$claims[Statement\DoWhileNode::class]['whileKeyword'] = $continues;

		return $claims;
	}


	/** The brace goes where the position says, an empty body collapses to {}, a single line stays where allowed. */
	private function beforeOpening(?Node $owner): ?Claim
	{
		$shape = $this->describe($owner);
		return match (true) {
			$shape === null => null,
			$shape['collapsed'] => Claim::sameLine(),
			$shape['singleline'] && self::isSingleline($shape['open'], $shape['close']) => null,
			default => $shape['nextLine'] ? Claim::nextLine() : Claim::sameLine(),
		};
	}


	/** The content of a body starts on a new line. */
	private function afterOpening(?Node $owner): ?Claim
	{
		$shape = $this->describe($owner);
		return $shape === null
			|| $shape['collapsed']
			|| ($shape['singleline'] && self::isSingleline($shape['open'], $shape['close']))
			? null
			: Claim::nextLine();
	}


	/** The closing brace takes a line of its own, or hugs the opening one of a collapsed body. */
	private function beforeClosing(?Node $owner): ?Claim
	{
		$shape = $this->describe($owner);
		return match (true) {
			$shape === null => null,
			$shape['collapsed'] => new Claim(Space::None, line: Line::Same),
			$shape['singleline'] && self::isSingleline($shape['open'], $shape['close']) => null,
			default => Claim::nextLine(),
		};
	}


	/**
	 * The braces the rule governs on the node and what it asks of them: whether the opening one takes the
	 * next line, whether an empty body collapses to {}, whether a body on one line may stay so.
	 * @return ?array{open: Token, close: Token, nextLine: bool, collapsed: bool, singleline: bool}
	 */
	private function describe(?Node $node): ?array
	{
		$none = [null, null, false, false, false, false];
		[$open, $close, $empty, $nextLine, $singleline, $collapsible] = match (true) {
			$node instanceof Statement\FunctionNode, $node instanceof MethodNode => $node->body === null ? $none : [
				$node->body->openBrace,
				$node->body->closeBrace,
				$node->body->statements->isEmpty(),
				!($node->closeParen->startsLine() && $this->isSameLineAfterMultilineParams($node)),
				false,
				true,
			],
			$node instanceof ClosureNode => [
				$node->body->openBrace,
				$node->body->closeBrace,
				$node->body->statements->isEmpty(),
				$this->anonymousFunction === self::NextLine,
				$this->singlelineAnonymousFunction === self::Keep,
				true,
			],
			$node instanceof Statement\ClassNode, $node instanceof Statement\InterfaceNode,
			$node instanceof Statement\TraitNode, $node instanceof Statement\EnumNode => [
				$node->openBrace,
				$node->closeBrace,
				$node->members->isEmpty(),
				$this->class === self::NextLine,
				false,
				true,
			],
			$node instanceof Nodes\AnonymousClassNode => [
				$node->openBrace,
				$node->closeBrace,
				$node->members->isEmpty(),
				self::hasWrappedImplements($node) || $this->anonymousClass === self::NextLine,
				$this->emptyAnonymousClass === self::SameLine && $node->members->isEmpty(),
				true,
			],
			$node instanceof Statement\IfNode, $node instanceof Nodes\ElseifNode, $node instanceof Nodes\ElseNode,
			$node instanceof Statement\ForNode, $node instanceof Statement\ForeachNode, $node instanceof Statement\WhileNode,
			$node instanceof Statement\DoWhileNode, $node instanceof Statement\DeclareNode => $node->body instanceof Statement\BlockNode
				? [
					$node->body->openBrace,
					$node->body->closeBrace,
					false,
					$this->controlStructure === self::NextLine,
					false,
					false,
				]
				: $none,
			$node instanceof Statement\TryNode, $node instanceof Nodes\CatchNode, $node instanceof Nodes\FinallyNode => [
				$node->body->openBrace,
				$node->body->closeBrace,
				false,
				$this->controlStructure === self::NextLine,
				false,
				false,
			],
			$node instanceof Statement\SwitchNode, $node instanceof MatchNode
				=> [$node->openBrace, $node->closeBrace, false, $this->controlStructure === self::NextLine, false, false],
			// hooks stay on the line of their property only in the abbreviated form, without a body among them
			$node instanceof PropertyNode, $node instanceof ParameterNode => [
				$node->openBrace,
				$node->closeBrace,
				false,
				false,
				self::isAbbreviated($node->hooks?->getItems() ?? []),
				false,
			],
			$node instanceof PropertyHookNode => $node->body === null
				? $none
				: [$node->body->openBrace, $node->body->closeBrace, $node->body->statements->isEmpty(), false, false, true],
			default => $none,
		};
		if ($open === null || $close === null) {
			return null;
		}

		// a comment inside makes a body not empty
		$collapsed = $collapsible && $this->emptyBody === self::SameLine && $empty && !$open->hasCommentUpTo($close);
		return [
			'open' => $open,
			'close' => $close,
			'nextLine' => $nextLine,
			'collapsed' => $collapsed,
			'singleline' => $singleline,
		];
	}


	private function isSameLineAfterMultilineParams(Statement\FunctionNode|MethodNode $node): bool
	{
		return match ($this->multilineParameters) {
			self::SameLine => true,
			self::NextLine => false,
			default => $node->returnType === null,
		};
	}


	/**
	 * Whether every hook is written without a body, `get;` or `get => $value;`, which is the only form PER
	 * allows on the line of the property.
	 * @param list<PropertyHookNode> $hooks
	 */
	private static function isAbbreviated(array $hooks): bool
	{
		return array_all($hooks, fn(PropertyHookNode $hook) => $hook->body === null);
	}


	/** An implements list continued on further lines puts the brace of an anonymous class on its own line. */
	private static function hasWrappedImplements(Nodes\AnonymousClassNode $node): bool
	{
		$last = $node->implements?->getLastToken();
		return $last !== null && $node->implementsKeyword?->currentLine !== $last->currentLine;
	}


	private static function isSingleline(Token $open, Token $close): bool
	{
		for ($token = $open; $token !== null && $token !== $close; $token = $token->getNext()) {
			foreach ($token->trailingTrivia as $trivia) {
				if ($trivia->isLineEnding()) {
					return false;
				}
			}

			if (preg_match('~[\r\n]~', $token->text) && $token !== $open) {
				return false;
			}
		}

		return true;
	}
}
