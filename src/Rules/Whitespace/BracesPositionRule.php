<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, Decision, Domain, Gap, GapRule, Line, RuleInfo, Space, Stage, Values};
use DressCode\Domains\Words;
use PhpSyntax\{Node, Nodes, Token};
use PhpSyntax\Nodes\Expression\{ClosureNode, MatchNode};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyHookNode, PropertyNode};
use PhpSyntax\Nodes\{ParameterNode, Statement};


/**
 * Where the opening brace of a body goes: on its own line for classes and functions, on the line of the
 * declaration for control structures, closures, anonymous classes and property hooks; what follows it starts
 * a new line and the closing brace one of its own, except in a single-line closure, an empty anonymous class
 * or an abbreviated list of hooks (`{ get; set; }`), whose hooks otherwise take a line each. A function with
 * parameters on several lines has a decision of its own for the brace. The keyword that continues a structure
 * (`else`, `catch`, the `while` of `do`) meets the closing brace on its line or takes the next one. Where the
 * lines then stand is the matter of `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting)]
final class BracesPositionRule extends GapRule
{
	private const SameLine = 'sameLine';
	private const NextLine = 'nextLine';
	private const OwnLines = 'ownLines';
	private const AfterReturnType = 'nextLineAfterReturnType';

	private const ClassLike = 'braces.position.class';
	private const FunctionBody = 'braces.position.function';
	private const MultilineSignature = 'braces.position.multilineSignature';
	private const Closure = 'braces.position.closure';
	private const AnonymousClass = 'braces.position.anonymousClass';
	private const ControlStructure = 'braces.position.controlStructure';
	private const ContinuingKeyword = 'braces.position.continuingKeyword';
	private const EmptyBody = 'braces.empty.body';
	private const EmptyAnonymousClass = 'braces.empty.anonymousClass';
	private const SinglelineClosure = 'braces.singlelineClosure';

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

	/** the words of the decisions, null where one is kept */
	private ?string $class = self::NextLine;
	private ?string $function = self::NextLine;
	private ?string $multilineSignature = self::SameLine;
	private ?string $closure = self::SameLine;
	private ?string $anonymousClass = self::SameLine;
	private ?string $controlStructure = self::SameLine;
	private ?string $continuingKeyword = self::SameLine;
	private ?string $emptyBody = self::OwnLines;
	private ?string $emptyAnonymousClass = null;

	/** a closure written whole on one line may stay so */
	private bool $singlelineClosureKept = true;

	/** @var array<string, Claim>  the claims made for the decisions */
	private array $claims = [];


	public static function getDecisions(): array
	{
		$empty = new Words([
			self::OwnLines => '`{` and `}` placed as those of any other body',
			self::SameLine => '`{}` on the line of the head',
		]);
		return [
			new Decision(self::ClassLike, Domain::placement(), 'Where the `{` of a class, an interface, a trait and an enum stands, the members starting a line below it and the `}` taking a line of its own'),
			new Decision(self::FunctionBody, Domain::placement(self::NextLine), 'Where the `{` of a function and of a method whose parameters stand on one line stands, the `{` of a property hook and of the list of hooks staying on the line of its head; the body starts a line below it and the `}` takes a line of its own'),
			new Decision(self::MultilineSignature, new Words([
				self::AfterReturnType => 'below `): Foo` where a return type ends the signature, on the line of the `)` otherwise',
				self::SameLine => 'on the line of the `)`',
				self::NextLine => 'on the line below the `)`',
			]), 'Where the `{` of a function and of a method whose parameters are spread over lines stands'),
			new Decision(self::Closure, Domain::placement(), 'Where the `{` of a closure stands, the body starting a line below it and the `}` taking a line of its own'),
			new Decision(self::AnonymousClass, Domain::placement(), 'Where the `{` of an anonymous class stands, on the next line always where its interfaces are spread over lines, the members starting a line below it and the `}` taking a line of its own'),
			new Decision(self::ControlStructure, Domain::placement(), 'Where the `{` of `if`, `else`, a loop, `declare`, `try`, `catch`, `finally`, `switch` and `match` stands, the body starting a line below it and the `}` taking a line of its own'),
			new Decision(self::ContinuingKeyword, Domain::placement(), 'Where the keyword continuing a structure after its `}` stands: `else`, `elseif`, `catch`, `finally` and the `while` of `do`'),
			new Decision(self::EmptyBody, $empty, 'How an empty body of a class, a function, a closure and a property hook is written; a comment inside makes it not empty'),
			new Decision(self::EmptyAnonymousClass, new Words([
				self::OwnLines => '`{` and `}` placed as those of any other anonymous class',
				self::SameLine => '`{}` on the line of the head, one holding a comment staying on its line as written',
			]), 'How an empty anonymous class is written, whatever `braces.empty.body` says; one written on one line stays where it is kept'),
			new Decision(self::SinglelineClosure, Domain::state('forbidden'), 'A closure written whole on one line'),
		];
	}


	public function configure(Values $values): void
	{
		$this->class = $values->find(self::ClassLike)?->getWord();
		$this->function = $values->find(self::FunctionBody)?->getWord();
		$this->multilineSignature = $values->find(self::MultilineSignature)?->getWord();
		$this->closure = $values->find(self::Closure)?->getWord();
		$this->anonymousClass = $values->find(self::AnonymousClass)?->getWord();
		$this->controlStructure = $values->find(self::ControlStructure)?->getWord();
		$this->continuingKeyword = $values->find(self::ContinuingKeyword)?->getWord();
		$this->emptyBody = $values->find(self::EmptyBody)?->getWord();
		$this->emptyAnonymousClass = $values->find(self::EmptyAnonymousClass)?->getWord();
		$this->singlelineClosureKept = $values->isKept(self::SinglelineClosure);
	}


	public function getClaims(): array
	{
		$braces = [
			'openBrace' => [
				fn(Gap $gap) => $this->claimBeforeOpening($gap->token->parent, $gap),
				fn(Gap $gap) => $this->claimAfterOpening($gap->token->parent, $gap),
			],
			'closeBrace' => [fn(Gap $gap) => $this->claimBeforeClosing($gap->token->parent, $gap), null],
		];
		$claims = [
			Nodes\AnonymousClassNode::class => $braces,
			Statement\SwitchNode::class => $braces,
			MatchNode::class => $braces,
			// the braces of a block are claimed through the block, whose owner says whether it is a body
			Statement\BlockNode::class => [
				'openBrace' => [null, fn(Gap $gap) => $this->claimAfterOpening($gap->token->parent?->parent, $gap)],
				'closeBrace' => [fn(Gap $gap) => $this->claimBeforeClosing($gap->token->parent?->parent, $gap), null],
			],
		];
		foreach (self::ClassLikes as $class) {
			$claims[$class] = $braces;
		}

		foreach (self::Bodied as $class) {
			$claims[$class]['body'] = [fn(Gap $gap) => $this->claimBeforeOpening($gap->value->parent, $gap), null];
		}

		// the braces around the hooks of a property, and every hook on a line of its own between them
		foreach (self::Hooked as $class) {
			$claims[$class] = $braces + [
				'hooks:item' => [fn(Gap $gap) => $this->claimAfterOpening($gap->value->parent?->parent, $gap), null],
			];
		}

		if ($this->continuingKeyword === null) {
			return $claims;
		}

		// the keyword that continues a structure meets the brace that closed the part before it;
		// a body without braces keeps the keyword where it is
		$wanted = $this->claim(self::ContinuingKeyword, $this->continuingKeyword === self::SameLine ? Line::Same : Line::Next);
		$continues = [fn(Gap $gap) => $gap->token->getPrevious()?->is('}') ?? false ? $wanted : null, null];
		$claims[Nodes\ElseifNode::class]['elseifKeyword'] = $continues;
		$claims[Nodes\ElseNode::class]['elseKeyword'] = $continues;
		$claims[Nodes\CatchNode::class]['catchKeyword'] = $continues;
		$claims[Nodes\FinallyNode::class]['finallyKeyword'] = $continues;
		$claims[Statement\DoWhileNode::class]['whileKeyword'] = $continues;

		return $claims;
	}


	/** The brace goes where the position says, an empty body collapses to {}, a single line stays where allowed. */
	private function claimBeforeOpening(?Node $owner, Gap $gap): ?Claim
	{
		$shape = $this->readShape($owner, $gap);
		return match (true) {
			$shape === null => null,
			$shape['collapsed'] => $this->claim($shape['emptyDecision'], Line::Same),
			$shape['singleline'] => null,
			$shape['nextLine'] === null => null,
			default => $this->claim($shape['decision'], $shape['nextLine'] ? Line::Next : Line::Same),
		};
	}


	/** The content of a body starts on a new line. */
	private function claimAfterOpening(?Node $owner, Gap $gap): ?Claim
	{
		$shape = $this->readShape($owner, $gap);
		return $shape === null
			|| $shape['collapsed']
			|| $shape['inside'] === null
			|| $shape['singleline']
				? null
				: $this->claim($shape['inside'], Line::Next);
	}


	/** The closing brace takes a line of its own, or hugs the opening one of a collapsed body. */
	private function claimBeforeClosing(?Node $owner, Gap $gap): ?Claim
	{
		$shape = $this->readShape($owner, $gap);
		return match (true) {
			$shape === null => null,
			$shape['collapsed'] => $this->claim($shape['emptyDecision'], Line::Same, Space::None),
			$shape['inside'] === null => null,
			$shape['singleline'] => null,
			default => $this->claim($shape['inside'], Line::Next),
		};
	}


	/**
	 * The braces the rule governs on the node and what it asks of them: whether the opening one takes the
	 * next line and for which decision, null where that decision is kept; the decision the lines inside the
	 * braces are made for, null where it is kept; whether an empty body collapses to {} and for which decision;
	 * whether the body stands on one line where it may stay so, as the first of its gaps found it.
	 * @return ?array{open: Token, close: Token, nextLine: ?bool, decision: string, inside: ?string, collapsed: bool, emptyDecision: string, singleline: bool}
	 */
	private function readShape(?Node $node, Gap $gap): ?array
	{
		$shape = match (true) {
			$node instanceof Statement\FunctionNode, $node instanceof MethodNode => $node->body === null ? null : [
				$node->body->openBrace,
				$node->body->closeBrace,
				$node->body->statements->isEmpty(),
				...($node->closeParen->startsLine()
					? [$this->isNextLineAfterMultilineSignature($node), self::MultilineSignature]
					: [$this->function === null ? null : true, self::FunctionBody]),
				$this->function === null ? null : self::FunctionBody,
				false,
				self::EmptyBody,
			],
			$node instanceof ClosureNode => [
				$node->body->openBrace,
				$node->body->closeBrace,
				$node->body->statements->isEmpty(),
				$this->closure === null ? null : $this->closure === self::NextLine,
				self::Closure,
				match (true) {
					$this->closure !== null => self::Closure,
					$this->singlelineClosureKept => null,
					default => self::SinglelineClosure,
				},
				$this->singlelineClosureKept,
				self::EmptyBody,
			],
			$node instanceof Statement\ClassNode, $node instanceof Statement\InterfaceNode,
			$node instanceof Statement\TraitNode, $node instanceof Statement\EnumNode => [
				$node->openBrace,
				$node->closeBrace,
				$node->members->isEmpty(),
				$this->class === null ? null : $this->class === self::NextLine,
				self::ClassLike,
				$this->class === null ? null : self::ClassLike,
				false,
				self::EmptyBody,
			],
			$node instanceof Nodes\AnonymousClassNode => [
				$node->openBrace,
				$node->closeBrace,
				$node->members->isEmpty(),
				$this->anonymousClass === null ? null : self::hasMultilineImplements($node, $gap) || $this->anonymousClass === self::NextLine,
				self::AnonymousClass,
				$this->anonymousClass === null ? null : self::AnonymousClass,
				// one holding only a comment is no body to collapse, and stays on its line
				$this->emptyAnonymousClass === self::SameLine && $node->members->isEmpty(),
				self::EmptyAnonymousClass,
			],
			$node instanceof Statement\IfNode, $node instanceof Nodes\ElseifNode, $node instanceof Nodes\ElseNode,
			$node instanceof Statement\ForNode, $node instanceof Statement\ForeachNode, $node instanceof Statement\WhileNode,
			$node instanceof Statement\DoWhileNode, $node instanceof Statement\DeclareNode => $node->body instanceof Statement\BlockNode
				? [$node->body->openBrace, $node->body->closeBrace, false, ...$this->readControlStructureShape()]
				: null,
			$node instanceof Statement\TryNode, $node instanceof Nodes\CatchNode, $node instanceof Nodes\FinallyNode
				=> [$node->body->openBrace, $node->body->closeBrace, false, ...$this->readControlStructureShape()],
			$node instanceof Statement\SwitchNode, $node instanceof MatchNode
				=> [$node->openBrace, $node->closeBrace, false, ...$this->readControlStructureShape()],
			// hooks stay on the line of their property only in the abbreviated form, without a body among them
			$node instanceof PropertyNode, $node instanceof ParameterNode => [
				$node->openBrace,
				$node->closeBrace,
				false,
				$this->function === null ? null : false,
				self::FunctionBody,
				$this->function === null ? null : self::FunctionBody,
				self::isAbbreviated($node->hooks?->getItems() ?? []),
				null,
			],
			$node instanceof PropertyHookNode => $node->body === null ? null : [
				$node->body->openBrace,
				$node->body->closeBrace,
				$node->body->statements->isEmpty(),
				$this->function === null ? null : false,
				self::FunctionBody,
				$this->function === null ? null : self::FunctionBody,
				false,
				self::EmptyBody,
			],
			default => null,
		};
		if ($node === null || $shape === null) {
			return null;
		}

		[$open, $close, $empty, $nextLine, $decision, $inside, $singleline, $emptyDecision] = $shape;
		if ($open === null || $close === null) {
			return null;
		}

		// a comment inside makes a body not empty; an empty body kept stays on one line where it is written so
		$emptyWord = match ($emptyDecision) {
			self::EmptyBody => $this->emptyBody,
			self::EmptyAnonymousClass => $this->emptyAnonymousClass,
			default => self::OwnLines,
		};
		$collapsed = $empty && $emptyWord === self::SameLine && !$open->hasCommentUpTo($close);
		$kept = $empty && $emptyWord === null;
		// a closure is on one line when written whole so, an empty body kept when its braces are
		$from = $node instanceof ClosureNode && !$kept ? $node->functionKeyword : $open;
		return [
			'open' => $open,
			'close' => $close,
			'nextLine' => $nextLine,
			'decision' => $decision,
			'inside' => $inside,
			'collapsed' => $collapsed,
			'emptyDecision' => $emptyDecision ?? self::EmptyBody,
			// a body collapsing to {} is not asked about, its gaps being no construct decided once
			'singleline' => !$collapsed
				&& ($singleline || $kept)
				&& $gap->once($node, fn() => self::isSingleline($from, $close)),
		];
	}


	/** @return array{?bool, string, ?string, bool, null} */
	private function readControlStructureShape(): array
	{
		return $this->controlStructure === null
			? [null, self::ControlStructure, null, false, null]
			: [$this->controlStructure === self::NextLine, self::ControlStructure, self::ControlStructure, false, null];
	}


	/** Whether the brace of a function whose parameters are spread over lines takes the next line, null where it is kept. */
	private function isNextLineAfterMultilineSignature(Statement\FunctionNode|MethodNode $node): ?bool
	{
		return match ($this->multilineSignature) {
			null => null,
			self::SameLine => false,
			self::NextLine => true,
			default => $node->returnType !== null,
		};
	}


	/** The claim on a line, and on the whitespace of it where given, made for the decision. */
	private function claim(string $decision, Line $line, ?Space $space = null): Claim
	{
		return $this->claims["$decision $line->name {$space?->name}"] ??= new Claim($space, $line, decision: $decision);
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


	/**
	 * An implements list continued on further lines puts the brace of an anonymous class on its own line, as the list
	 * is written when the first gap of the class is reached.
	 */
	private static function hasMultilineImplements(Nodes\AnonymousClassNode $node, Gap $gap): bool
	{
		$implements = $node->implements;
		return $implements !== null
			&& $gap->once($implements, fn() => $node->implementsKeyword?->getCurrentLine() !== $implements->getLastToken()?->getCurrentLine());
	}


	private static function isSingleline(Token $from, Token $close): bool
	{
		for ($token = $from; $token !== null && $token !== $close; $token = $token->getNext()) {
			foreach ($token->trailingTrivia as $trivia) {
				if ($trivia->isLineEnding()) {
					return false;
				}
			}

			if (preg_match('~[\r\n]~', $token->text) && $token !== $from) {
				return false;
			}
		}

		return true;
	}
}
