<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, Decision, Domain, Gap, GapRule, Line, RuleInfo, Space, Stage, Values};
use DressCode\Domains\Shapes;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{
	CaseNode, CatchNode, ClassLikeNode, ClosureUseListNode, ElseifNode, ElseNode,
	FinallyNode, ModifiersNode, ParameterNode, SeparatedNodeList, Statement, UseItemNode,
};
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
#[RuleInfo(Stage::Formatting, decisions: [self::Keyword])]
final class ConstructSpacingRule extends GapRule
{
	private const Keyword = 'spacing.keyword';
	private const OpenBrace = 'spacing.openBrace';
	private const AlternativeSyntax = 'spacing.alternativeSyntax';
	private const Body = 'spacing.body';
	private const Import = 'spacing.import';
	private const ArrowFunction = 'spacing.arrowFunction';
	private const ExpressionStart = 'multiline.expressionStart.';

	/** the slots of the keywords followed by a space */
	private const FollowedBySpace = [
		'returnKeyword', 'printKeyword', 'throwKeyword', 'cloneKeyword', 'newKeyword', 'yieldKeyword', 'yieldFromKeyword',
		'includeKeyword', 'namespaceKeyword', 'constKeyword', 'globalKeyword', 'staticKeyword', 'gotoKeyword',
		'breakKeyword', 'continueKeyword', 'functionKeyword', 'ifKeyword', 'elseifKeyword', 'elseKeyword', 'whileKeyword',
		'forKeyword', 'foreachKeyword', 'switchKeyword', 'matchKeyword', 'catchKeyword', 'finallyKeyword', 'doKeyword',
		'tryKeyword', 'useKeyword', 'asKeyword', 'insteadofKeyword', 'caseKeyword',
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

	/** a keyword followed by an expression => its place in `multiline.expressionStart` */
	private const ExpressionKeywords = [
		Token::Return => 'return',
		Token::Throw => 'throw',
		Token::Yield => 'yield',
		Token::YieldFrom => 'yield',
		Token::Print => 'echo',
		Token::Echo => 'echo',
	];

	private ?Claim $spaceAfterFn;

	/** a single space before the colon of the alternative syntax and of a label, none otherwise */
	private bool $colonSpaced = false;

	/**
	 * @var array<string, array{?Claim, ?Claim, ?Claim}>  decision => a single space, a single space on the same line,
	 * no space; made for it, none for a `keep`
	 */
	private array $claims = [];


	public static function getDecisions(): array
	{
		$decisions = [
			new Decision(
				self::OpenBrace,
				new Shapes(['spaced' => ['if ($a) {', 'a single space']]),
				'The space before the brace or the body a structure opens on the line of its head',
			),
			new Decision(
				self::AlternativeSyntax,
				new Shapes([
					'compact' => ['if ($a):', 'no space before the colon'],
					'spaced' => ['if ($a) :', 'a single space before the colon'],
				]),
				'The space before the colon of the alternative syntax and of a label, and the single space between it and the end keyword of an empty body, `if ($a): endif;`',
			),
			new Decision(
				self::Body,
				new Shapes(['spaced' => ['public $a { get; set; }', 'a single space inside the braces']]),
				'The space inside the braces of abbreviated property hooks, and none inside a pair of braces with nothing between them that is no body of a class or a function',
			),
			new Decision(
				self::Import,
				new Shapes(['compact' => ['use Acme\{Foo, Bar};', 'no space around the braces']]),
				'The space around the braces of a group use, which hug the names',
			),
			new Decision(
				self::ArrowFunction,
				new Shapes([
					'compact' => ['fn($x) => $x', 'no space before the parenthesis'],
					'spaced' => ['fn ($x) => $x', 'a single space'],
				]),
				'The space between `fn` and its parenthesis',
			),
		];
		foreach ([
			'return' => '`return`',
			'throw' => '`throw`',
			'yield' => '`yield` and `yield from`',
			'echo' => '`echo` and `print`',
		] as $place => $what) {
			$decisions[] = new Decision(
				self::ExpressionStart . $place,
				Domain::placement('sameLine'),
				"Where an expression spanning several lines after $what begins",
			);
		}

		return $decisions;
	}


	public function configure(Values $values): void
	{
		$paths = [self::Keyword, ...array_map(fn(Decision $decision) => $decision->path, self::getDecisions())];
		foreach ($paths as $path) {
			$this->claims[$path] = $values->isKept($path) ? [null, null, null] : [
				Claim::singleSpace()->withDecision($path),
				new Claim(Space::Single, line: Line::Same, decision: $path),
				Claim::noSpace()->withDecision($path),
			];
		}

		$this->colonSpaced = $values->find(self::AlternativeSyntax)?->getShape() === 'spaced';
		$this->spaceAfterFn = match ($values->find(self::ArrowFunction)?->getShape()) {
			'spaced' => $this->claims[self::ArrowFunction][0],
			'compact' => $this->claims[self::ArrowFunction][2],
			default => null,
		};
	}


	public function getClaims(): array
	{
		[$keywordSingle, $keywordJoined] = $this->claims[self::Keyword];
		[$openBrace] = $this->claims[self::OpenBrace];
		[$colonSingle, , $colonNone] = $this->claims[self::AlternativeSyntax];
		[$bodySingle, , $bodyNone] = $this->claims[self::Body];
		[, , $importNone] = $this->claims[self::Import];
		$afterKeyword = fn(Gap $gap) => $this->claimAfterKeyword($gap);
		$any = [];
		foreach (self::FollowedBySpace as $slot) {
			$before = match (true) {
				$slot === 'asKeyword' || $slot === 'insteadofKeyword' => $keywordJoined,
				in_array($slot, self::PrecededBySpace, true) => $keywordSingle,
				default => null,
			};
			$any[$slot] = [$before, $afterKeyword];
		}

		// the keyword of an echo is `<?=` as well, and what follows the open tag is the template's
		$any['echoKeyword'] = [
			null,
			fn(Gap $gap) => $gap->token->is(Token::OpenTagWithEcho) ? null : $this->claimAfterKeyword($gap),
		];
		$any['fnKeyword'] = [null, $this->spaceAfterFn];
		// a pair of braces with nothing between them is written {}, unless it is a body, which `braces.emptyBody` writes
		$any['closeBrace'] = [
			fn(Gap $gap) => ($gap->token->getPrevious()?->is('{') ?? false) && !self::isBody($gap->token->parent) ? $bodyNone : null,
			null,
		];
		// and an empty body of the alternative syntax is written `: endif`
		$any['endKeyword'] = [fn(Gap $gap) => $gap->token->getPrevious()?->is(':') ?? false ? $colonSingle : null, null];
		$claims = ['*' => $any];
		// slots named too generally to mean the same on every node
		$claims[CaseNode::class]['keyword'] = [null, $afterKeyword];
		$claims[ModifiersNode::class]['tokens'] = [null, $afterKeyword];
		// the braces, bodies and colons of the structures; those of a dynamic name or a ternary are not theirs
		foreach (self::Braced as $class) {
			$claims[$class]['openBrace'] = [$openBrace, null];
		}

		// the abbreviated hooks of a property: `{ get; set; }`
		foreach ([PropertyNode::class, ParameterNode::class] as $class) {
			$claims[$class]['openBrace'] = [$openBrace, $bodySingle];
			$claims[$class]['closeBrace'] = [$bodySingle, null];
		}

		foreach (self::Bodied as $class) {
			$claims[$class]['body'] = [$openBrace, null];
		}

		// the use of a closure stays on the line of its parameters
		$claims[ClosureUseListNode::class]['useKeyword'] = [$keywordJoined, null];

		$colon = $this->colonSpaced ? $colonSingle : $colonNone;
		foreach (self::Coloned as $class) {
			$claims[$class]['colon'] = [$colon, null];
		}

		// the braces of a group import, which the plain form leaves empty
		$claims[Statement\UseNode::class]['backslash'] = [$importNone, $importNone];
		$claims[Statement\UseNode::class]['openBrace'] = [$importNone, $importNone];
		$claims[Statement\UseNode::class]['closeBrace'] = [$importNone, null];
		// the function or const of an import, in front of the name it qualifies
		foreach ([Statement\UseNode::class, UseItemNode::class] as $class) {
			$claims[$class]['kindKeyword'] = [null, $keywordSingle];
		}

		// the modifier of a trait alias, in front of the alias: `as protected foo`
		$claims[TraitAliasNode::class]['modifier'] = [null, $afterKeyword];
		return $claims;
	}


	/**
	 * A single space after a keyword and the code that follows it on its line; punctuation closing it hugs it:
	 * `return;`, `default:`. What may begin on the next line is left there: a body after `else`, `do` or a brace,
	 * a list of several items (`const`, `global`, `use`), attributes, whatever follows a comment, and by the option
	 * an expression spanning several lines.
	 */
	private function claimAfterKeyword(Gap $gap): ?Claim
	{
		[$single, $joined] = $this->claims[self::Keyword];
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
		}

		$place = self::ExpressionKeywords[$token->id] ?? null;
		if ($operand === null || $place === null || !$operand->isMultiLine()) {
			return $joined;
		}

		// a multi-line expression begins below the keyword only where the project allows it, and the break is
		// what that decision is about
		$sameLine = $this->claims[self::ExpressionStart . $place][1];
		return match (true) {
			$sameLine === null => $single,
			$next->startsLine() => $sameLine,
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


	/** Whether the braces enclose the body of a class or of a function, whose empty form `BracesPositionRule` writes. */
	private static function isBody(?Node $braced): bool
	{
		return $braced instanceof ClassLikeNode
			|| ($braced instanceof Statement\BlockNode && (
				$braced->parent instanceof Statement\FunctionNode
				|| $braced->parent instanceof MethodNode
				|| $braced->parent instanceof ClosureNode
				|| $braced->parent instanceof PropertyHookNode
			));
	}
}
