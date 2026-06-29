<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, Decision, Domain, Gap, GapRule, Line, RuleInfo, Space, Stage, Values};
use DressCode\Domains\Shapes;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{CaseNode, CatchNode, ClosureUseListNode, ElseifNode, ElseNode, FinallyNode, ModifiersNode, ParameterNode, SeparatedNodeList, Statement, UseItemNode};
use PhpSyntax\Nodes\Expression\{ClosureNode, MatchNode};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyHookNode, PropertyNode, TraitAliasNode, TraitUseNode};
use function count;


/**
 * A single space after a language construct and what follows it on its line (`return $a`, `new Foo`,
 * `function ()`, `public static`), before and after the keyword that continues one (`} else {`, `$a as $b`,
 * `} catch (`), before the brace or the body a structure opens on the same line, and inside the braces of
 * abbreviated property hooks (`{ get; set; }`); the colon of the alternative syntax and of a label hugs
 * what is before it, a single space separates it from the end keyword of an empty body (`if ($a): endif;`), and
 * the braces of a group use hug their names. What may begin on the line below
 * a construct is left there: a body, a list of several items, attributes and whatever follows a comment.
 * `as`, `insteadof` and the `use` of a closure stay on the line of what is before them.
 * The space between `fn` and its parenthesis, and whether an expression spanning several lines may begin below
 * `return` and its like, are options.
 */
#[RuleInfo(Stage::Formatting)]
final class ConstructSpacingRule extends GapRule
{
	private const Construct = 'spacing.languageConstruct';
	private const Control = 'spacing.controlKeyword';
	private const Connecting = 'spacing.connectingKeyword';
	private const BelowReturn = 'multiline.expressionBelowReturn';

	/** the slot of a keyword => the decision the space after it belongs to */
	private const FollowedBySpace = [
		'returnKeyword' => self::Construct, 'printKeyword' => self::Construct, 'throwKeyword' => self::Construct,
		'cloneKeyword' => self::Construct, 'newKeyword' => self::Construct, 'yieldKeyword' => self::Construct,
		'yieldFromKeyword' => self::Construct, 'includeKeyword' => self::Construct, 'namespaceKeyword' => self::Construct,
		'constKeyword' => self::Construct, 'globalKeyword' => self::Construct,
		'staticKeyword' => 'spacing.modifier', 'gotoKeyword' => self::Construct, 'breakKeyword' => self::Construct,
		'continueKeyword' => self::Construct, 'functionKeyword' => 'spacing.functionKeyword', 'defaultKeyword' => self::Construct,
		'ifKeyword' => self::Control, 'elseifKeyword' => self::Control, 'elseKeyword' => self::Connecting,
		'whileKeyword' => self::Control, 'forKeyword' => self::Control, 'foreachKeyword' => self::Control,
		'switchKeyword' => self::Control, 'matchKeyword' => self::Control, 'catchKeyword' => self::Control,
		'finallyKeyword' => self::Connecting, 'doKeyword' => self::Construct, 'tryKeyword' => self::Construct,
		'useKeyword' => self::Construct, 'asKeyword' => self::Connecting, 'insteadofKeyword' => self::Connecting,
	];
	private const PrecededBySpace = [
		'elseKeyword', 'elseifKeyword', 'catchKeyword', 'finallyKeyword', 'whileKeyword',
		'useKeyword', 'asKeyword', 'insteadofKeyword',
	];
	private const Braced = [
		Statement\BlockNode::class, Statement\NamespaceNode::class, Statement\SwitchNode::class, MatchNode::class,
		TraitUseNode::class,
	];
	private const Bodied = [
		Statement\IfNode::class, ElseifNode::class, ElseNode::class, Statement\WhileNode::class, Statement\DoWhileNode::class,
		Statement\ForNode::class, Statement\ForeachNode::class, Statement\DeclareNode::class, Statement\TryNode::class,
		CatchNode::class, FinallyNode::class, Statement\FunctionNode::class, MethodNode::class, ClosureNode::class,
		PropertyHookNode::class,
	];
	private const Coloned = [
		Statement\IfNode::class, ElseifNode::class, ElseNode::class, Statement\WhileNode::class, Statement\ForNode::class,
		Statement\ForeachNode::class, Statement\SwitchNode::class, Statement\DeclareNode::class, Statement\LabelNode::class,
	];
	private const ExpressionKeywords = [
		Token::Return,
		Token::Throw,
		Token::Yield,
		Token::YieldFrom,
		Token::Print,
		Token::Echo,
	];

	private ?Claim $spaceAfterFn;

	private bool $allowMultilineExpression = false;

	/** @var array<string, array{?Claim, ?Claim, ?Claim}>  decision => a single space, a single space on the same line, no space; made for it, none for a `keep` */
	private array $claims = [];


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Construct, new Shapes(['spaced' => ['return $x', 'a single space']]), 'The space after `return`, `throw`, `yield`, `print`, `include`, `clone`, `new` and the other keywords followed by code, which also stays on their line, and inside the braces of abbreviated property hooks; the braces of a group use hug their names'),
			new Decision(self::Control, new Shapes(['spaced' => ['if ($a)', 'a single space']]), 'The space after `if`, `elseif`, `while`, `for`, `foreach`, `switch`, `match` and `catch`, and before the brace or the body a structure opens on its line; the colon of the alternative syntax hugs what is before it'),
			new Decision(self::Connecting, new Shapes(['spaced' => ['} else {', 'a single space']]), 'The space around `else`, `elseif`, `catch`, `finally`, the `while` of a `do`, `as`, `insteadof` and the `use` of a closure, which stay on the line of what is before them'),
			new Decision('spacing.functionKeyword', new Shapes(['spaced' => ['function ($x)', 'a single space']]), 'The space after every `function` keyword, of a closure as of a declaration'),
			new Decision('spacing.modifier', new Shapes(['spaced' => ['final public static function', 'a single space']]), 'The space after a modifier, and after the `static` of a static closure, a static arrow function and a static variable'),
			new Decision('spacing.fnKeyword', new Shapes(['compact' => ['fn($x) => $x', 'no space before the parenthesis'], 'spaced' => ['fn ($x) => $x', 'a single space']]), 'The space between `fn` and its parenthesis'),
			new Decision(self::BelowReturn, Domain::state(), 'An expression spanning several lines begins on the line of `return`, `throw`, `yield`, `print` or `echo`'),
		];
	}


	public function configure(Values $values): void
	{
		foreach (self::getDecisions() as $decision) {
			$path = $decision->path;
			$this->claims[$path] = $values->isKept($path) ? [null, null, null] : [
				Claim::singleSpace()->withDecision($path),
				new Claim(Space::Single, line: Line::Same, decision: $path),
				Claim::noSpace()->withDecision($path),
			];
		}

		$this->spaceAfterFn = match ($values->find('spacing.fnKeyword')?->getShape()) {
			'spaced' => $this->claims['spacing.fnKeyword'][0],
			'compact' => $this->claims['spacing.fnKeyword'][2],
			default => null,
		};
		$this->allowMultilineExpression = $values->isKept(self::BelowReturn);
	}


	public function getClaims(): array
	{
		[$constructSingle, , $constructNone] = $this->claims[self::Construct];
		[$controlSingle, , $controlNone] = $this->claims[self::Control];
		[$connectingSingle, $connectingJoined] = $this->claims[self::Connecting];
		$any = [];
		foreach (self::FollowedBySpace as $slot => $decision) {
			$before = match (true) {
				$slot === 'asKeyword' || $slot === 'insteadofKeyword' => $connectingJoined,
				in_array($slot, self::PrecededBySpace, true) => $connectingSingle,
				default => null,
			};
			$any[$slot] = [$before, fn(Gap $gap) => $this->claimAfterKeyword($gap, $decision)];
		}

		// the keyword of an echo is `<?=` as well, and what follows the open tag is the template's
		$any['echoKeyword'] = [null, fn(Gap $gap) => $gap->token->is(Token::OpenTagWithEcho) ? null : $this->claimAfterKeyword($gap, self::Construct)];
		$any['fnKeyword'] = [null, $this->spaceAfterFn];
		// a pair of braces with nothing between them is written {}
		$any['closeBrace'] = [fn(Gap $gap) => $gap->token->getPrevious()?->is('{') ?? false ? $controlNone : null, null];
		// and an empty body of the alternative syntax is written `: endif`
		$any['endKeyword'] = [fn(Gap $gap) => $gap->token->getPrevious()?->is(':') ?? false ? $controlSingle : null, null];
		$claims = ['*' => $any];
		// slots named too generally to mean the same on every node
		$claims[CaseNode::class]['keyword'] = [null, fn(Gap $gap) => $this->claimAfterKeyword($gap, self::Construct)];
		$claims[ModifiersNode::class]['tokens'] = [null, fn(Gap $gap) => $this->claimAfterKeyword($gap, 'spacing.modifier')];
		// the braces, bodies and colons of the structures; those of a dynamic name or a ternary are not theirs
		foreach (self::Braced as $class) {
			$claims[$class]['openBrace'] = [$controlSingle, null];
		}

		// the abbreviated hooks of a property: `{ get; set; }`
		foreach ([PropertyNode::class, ParameterNode::class] as $class) {
			$claims[$class]['openBrace'] = [$controlSingle, $constructSingle];
			$claims[$class]['closeBrace'] = [$constructSingle, null];
		}

		foreach (self::Bodied as $class) {
			$claims[$class]['body'] = [$controlSingle, null];
		}

		// the use of a closure stays on the line of its parameters
		$claims[ClosureUseListNode::class]['useKeyword'] = [$connectingJoined, null];

		foreach (self::Coloned as $class) {
			$claims[$class]['colon'] = [$controlNone, null];
		}

		// the braces of a group import, which the plain form leaves empty
		$claims[Statement\UseNode::class]['backslash'] = [$constructNone, $constructNone];
		$claims[Statement\UseNode::class]['openBrace'] = [$constructNone, $constructNone];
		$claims[Statement\UseNode::class]['closeBrace'] = [$constructNone, null];
		// the function or const of an import, in front of the name it qualifies
		foreach ([Statement\UseNode::class, UseItemNode::class] as $class) {
			$claims[$class]['kindKeyword'] = [null, $constructSingle];
		}

		// the modifier of a trait alias, in front of the alias: `as protected foo`
		$claims[TraitAliasNode::class]['modifier'] = [null, fn(Gap $gap) => $this->claimAfterKeyword($gap, 'spacing.modifier')];
		return $claims;
	}


	/**
	 * A single space after a keyword and the code that follows it on its line; punctuation closing it hugs it:
	 * `return;`, `default:`. What may begin on the next line is left there: a body after `else`, `do` or a brace,
	 * a list of several items (`const`, `global`, `use`), attributes, whatever follows a comment, and by the option
	 * an expression spanning several lines.
	 */
	private function claimAfterKeyword(Gap $gap, string $decision): ?Claim
	{
		[$single, $joined] = $this->claims[$decision];
		if ($single === null) {
			return null;
		}

		$token = $gap->token;
		$next = $token->getNext();
		if ($next === null || $next->is([';', ':', ',', ')', ']', Token::DoubleColon, Token::CloseTag])) {
			return null;
		}

		if (
			$next->is(['{', Token::Attribute])
			|| $token->is([Token::Else, Token::Do])
			|| $token->hasCommentUpTo($next)
		) {
			return $single;
		}

		$operand = self::findOperand($token, $next);
		if ($operand instanceof SeparatedNodeList && count($operand->getItems()) > 1) {
			return $single;
		} elseif ($operand === null || !$token->is(self::ExpressionKeywords) || !$operand->isMultiLine()) {
			return $joined;
		}

		// a multi-line expression begins below the keyword only where the project allows it, and the break is
		// what that decision is about
		return match (true) {
			$this->allowMultilineExpression => $single,
			$next->startsLine() => $this->claims[self::BelowReturn][1],
			default => $joined,
		};
	}


	/** What the keyword introduces: the child of its node that begins with the next token, none when that is a token of the node itself. */
	private static function findOperand(Token $keyword, Token $next): ?Node
	{
		if ($next->parent === $keyword->parent) {
			return null;
		}

		$node = $next->parent;
		while ($node !== null && $node->parent !== $keyword->parent) {
			$node = $node->parent;
		}

		return $node;
	}
}
