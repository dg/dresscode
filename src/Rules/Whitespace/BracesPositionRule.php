<?php declare(strict_types=1);

namespace DressCode\Rules\Whitespace;

use DressCode\Claim;
use DressCode\ConfigurableRule;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\Line;
use DressCode\RuleInfo;
use DressCode\Space;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes;
use PhpSyntax\Nodes\Expression\ClosureNode;
use PhpSyntax\Nodes\Expression\MatchNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Member\PropertyHookNode;
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Nodes\ParameterNode;
use PhpSyntax\Nodes\Statement;
use PhpSyntax\Token;


/**
 * Where the opening brace of a body goes: on its own line for classes and functions, on the line of the
 * declaration for control structures, closures, anonymous classes and property hooks; what follows it starts
 * a new line and the closing brace one of its own, except in a single-line closure, an empty anonymous class
 * or an abbreviated list of hooks (`{ get; set; }`), whose hooks otherwise take a line each. For functions
 * with parameters on several lines, the brace follows the closing parenthesis (PER), takes its own line
 * (multiLineParameters: nextLine), or does so only after a return type (nextLineAfterReturnType). The keyword
 * that continues a structure (`else`, `catch`, the `while` of `do`) meets the closing brace on its line or
 * takes the next one. Where the lines then stand is the matter of dresscode/indentation.
 */
#[RuleInfo(
	'dresscode/braces-position',
	Stage::Formatting,
	description: 'Positions the braces of classes, functions and control structures, and the keywords between them',
)]
final class BracesPositionRule extends GapRule implements ConfigurableRule
{
	private const SameLine = 'sameLine';
	private const NextLine = 'nextLine';
	private const OwnLine = 'ownLine';

	private const ClassLikes = [Statement\ClassNode::class, Statement\InterfaceNode::class, Statement\TraitNode::class, Statement\EnumNode::class];
	private const Bodied = [
		Statement\FunctionNode::class, MethodNode::class, ClosureNode::class,
		Statement\IfNode::class, Nodes\ElseIfNode::class, Nodes\ElseNode::class, Statement\ForNode::class, Statement\ForeachNode::class,
		Statement\WhileNode::class, Statement\DoWhileNode::class, Statement\DeclareNode::class,
		Statement\TryNode::class, Nodes\CatchNode::class, Nodes\FinallyNode::class, PropertyHookNode::class,
	];
	private const Hooked = [PropertyNode::class, ParameterNode::class];

	private string $multiLineParameters = self::SameLine;
	private string $classes = self::NextLine;
	private string $anonymousClasses = self::SameLine;
	private string $anonymousFunctions = self::SameLine;
	private string $controlStructures = self::SameLine;
	private bool $allowSingleLineAnonymousFunctions = true;
	private string $emptyAnonymousClasses = self::SameLine;
	private string $emptyBodies = self::OwnLine;
	private string $continuation = self::SameLine;


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
			'continuation' => (clone $position)->default(self::SameLine)
				->description('The keyword continuing a structure (else, elseif, catch, finally, the while of do) on the line of the closing brace, or on the next one'),
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
		$this->continuation = $options['continuation'];
	}


	public function getClaims(): array
	{
		$braces = [
			'openBrace' => [fn(Gap $gap) => $this->beforeOpening($gap->token->parent), fn(Gap $gap) => $this->afterOpening($gap->token->parent)],
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
		$claims[Nodes\ElseIfNode::class]['elseifKeyword'] = $continues;
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
			$shape['singleLine'] && self::isSingleLine($shape['open'], $shape['close']) => null,
			default => $shape['nextLine'] ? Claim::nextLine() : Claim::sameLine(),
		};
	}


	/** The content of a body starts on a new line. */
	private function afterOpening(?Node $owner): ?Claim
	{
		$shape = $this->describe($owner);
		return $shape === null
			|| $shape['collapsed']
			|| ($shape['singleLine'] && self::isSingleLine($shape['open'], $shape['close']))
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
			$shape['singleLine'] && self::isSingleLine($shape['open'], $shape['close']) => null,
			default => Claim::nextLine(),
		};
	}


	/**
	 * The braces the rule governs on the node and what it asks of them: whether the opening one takes the
	 * next line, whether an empty body collapses to {}, whether a body on one line may stay so.
	 * @return ?array{open: Token, close: Token, nextLine: bool, collapsed: bool, singleLine: bool}
	 */
	private function describe(?Node $node): ?array
	{
		$none = [null, null, false, false, false, false];
		[$open, $close, $empty, $nextLine, $singleLine, $collapsible] = match (true) {
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
				$this->anonymousFunctions === self::NextLine,
				$this->allowSingleLineAnonymousFunctions,
				true,
			],
			$node instanceof Statement\ClassNode, $node instanceof Statement\InterfaceNode,
			$node instanceof Statement\TraitNode, $node instanceof Statement\EnumNode => [
				$node->openBrace,
				$node->closeBrace,
				$node->members->isEmpty(),
				$this->classes === self::NextLine,
				false,
				true,
			],
			$node instanceof Nodes\AnonymousClassNode => [
				$node->openBrace,
				$node->closeBrace,
				$node->members->isEmpty(),
				self::hasWrappedImplements($node) || $this->anonymousClasses === self::NextLine,
				$this->emptyAnonymousClasses === self::SameLine && $node->members->isEmpty(),
				true,
			],
			$node instanceof Statement\IfNode, $node instanceof Nodes\ElseIfNode, $node instanceof Nodes\ElseNode,
			$node instanceof Statement\ForNode, $node instanceof Statement\ForeachNode, $node instanceof Statement\WhileNode,
			$node instanceof Statement\DoWhileNode, $node instanceof Statement\DeclareNode => $node->body instanceof Statement\BlockNode
				? [$node->body->openBrace, $node->body->closeBrace, false, $this->controlStructures === self::NextLine, false, false]
				: $none,
			$node instanceof Statement\TryNode, $node instanceof Nodes\CatchNode, $node instanceof Nodes\FinallyNode
				=> [$node->body->openBrace, $node->body->closeBrace, false, $this->controlStructures === self::NextLine, false, false],
			$node instanceof Statement\SwitchNode, $node instanceof MatchNode
				=> [$node->openBrace, $node->closeBrace, false, $this->controlStructures === self::NextLine, false, false],
			// hooks stay on the line of their property only in the abbreviated form, without a body among them
			$node instanceof PropertyNode, $node instanceof ParameterNode => $node->openBrace === null || $node->closeBrace === null
				? $none
				: [$node->openBrace, $node->closeBrace, false, false, self::isAbbreviated($node->hooks?->getItems() ?? []), false],
			$node instanceof PropertyHookNode => $node->body === null
				? $none
				: [$node->body->openBrace, $node->body->closeBrace, $node->body->statements->isEmpty(), false, false, true],
			default => $none,
		};
		if ($open === null || $close === null) {
			return null;
		}

		// a comment inside makes a body not empty
		$collapsed = $collapsible && $this->emptyBodies === self::SameLine && $empty && !$open->hasCommentUpTo($close);
		return [
			'open' => $open,
			'close' => $close,
			'nextLine' => $nextLine,
			'collapsed' => $collapsed,
			'singleLine' => $singleLine || $collapsed,
		];
	}


	private function isSameLineAfterMultilineParams(Statement\FunctionNode|MethodNode $node): bool
	{
		return match ($this->multiLineParameters) {
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
		foreach ($hooks as $hook) {
			if ($hook->body !== null) {
				return false;
			}
		}

		return true;
	}


	/** An implements list continued on further lines puts the brace of an anonymous class on its own line. */
	private static function hasWrappedImplements(Nodes\AnonymousClassNode $node): bool
	{
		$last = $node->implements?->getLastToken();
		return $last !== null && $node->implementsKeyword?->getLine() !== $last->getLine();
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
}
