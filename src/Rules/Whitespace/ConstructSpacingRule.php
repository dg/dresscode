<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, ConfigurableRule, Gap, GapRule, Line, RuleInfo, Space, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{CatchNode, ClosureUseListNode, ElseifNode, ElseNode, FinallyNode, ParameterNode, SeparatedNodeList, Statement, UseItemNode};
use PhpSyntax\Nodes\Expression\{ClosureNode, MatchNode};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyHookNode, PropertyNode, TraitAliasNode, TraitUseNode};
use function count, in_array;


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
#[RuleInfo(
	'dresscode/constructSpacing',
	Stage::Formatting,
	description: 'Puts a single space around language constructs',
)]
final class ConstructSpacingRule extends GapRule implements ConfigurableRule
{
	private const FollowedBySpace = [
		'returnKeyword', 'printKeyword', 'throwKeyword', 'cloneKeyword', 'newKeyword', 'yieldKeyword',
		'yieldFromKeyword', 'includeKeyword', 'namespaceKeyword', 'constKeyword', 'keyword', 'globalKeyword',
		'staticKeyword', 'gotoKeyword', 'breakKeyword', 'continueKeyword', 'functionKeyword', 'defaultKeyword',
		'ifKeyword', 'elseifKeyword', 'elseKeyword', 'whileKeyword', 'forKeyword', 'foreachKeyword', 'switchKeyword',
		'matchKeyword', 'catchKeyword', 'finallyKeyword', 'doKeyword', 'tryKeyword', 'useKeyword', 'asKeyword',
		'insteadofKeyword', 'tokens',
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

	private Claim $arrowFunction;

	private bool $allowMultilineExpression = false;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'arrowFunction' => Expect::anyOf('none', 'single')->default('none')
				->description('Between `fn` and its parenthesis: `none` for `fn()`, as PER writes it, `single` for `fn ()`'),
			'allowMultilineExpression' => Expect::bool(false)
				->description('An expression spanning several lines may begin on the line below `return`, `throw`, `yield`, `print` or `echo`'),
		]);
	}


	public function configure(array $options): void
	{
		$this->arrowFunction = $options['arrowFunction'] === 'single' ? Claim::singleSpace() : Claim::noSpace();
		$this->allowMultilineExpression = $options['allowMultilineExpression'];
	}


	public function getClaims(): array
	{
		$joined = new Claim(Space::Single, line: Line::Same);
		$any = [];
		foreach (self::FollowedBySpace as $slot) {
			$before = match (true) {
				$slot === 'asKeyword' || $slot === 'insteadofKeyword' => $joined,
				in_array($slot, self::PrecededBySpace, true) => Claim::singleSpace(),
				default => null,
			};
			$any[$slot] = [$before, $this->followedByCode(...)];
		}

		// the keyword of an echo is `<?=` as well, and what follows the open tag is the template's
		$any['echoKeyword'] = [null, fn(Gap $gap) => $gap->token->is(Token::OpenTagWithEcho) ? null : $this->followedByCode($gap)];
		$any['fnKeyword'] = [null, $this->arrowFunction];
		// a pair of braces with nothing between them is written {}
		$any['closeBrace'] = [fn(Gap $gap) => $gap->token->getPrevious()?->is('{') ?? false ? Claim::noSpace() : null, null];
		// and an empty body of the alternative syntax is written `: endif`
		$any['endKeyword'] = [fn(Gap $gap) => $gap->token->getPrevious()?->is(':') ?? false ? Claim::singleSpace() : null, null];
		$claims = ['*' => $any];
		// the braces, bodies and colons of the structures; those of a dynamic name or a ternary are not theirs
		foreach (self::Braced as $class) {
			$claims[$class]['openBrace'] = [Claim::singleSpace(), null];
		}

		// the abbreviated hooks of a property: `{ get; set; }`
		foreach ([PropertyNode::class, ParameterNode::class] as $class) {
			$claims[$class]['openBrace'] = [Claim::singleSpace(), Claim::singleSpace()];
			$claims[$class]['closeBrace'] = [Claim::singleSpace(), null];
		}

		foreach (self::Bodied as $class) {
			$claims[$class]['body'] = [Claim::singleSpace(), null];
		}

		// the use of a closure stays on the line of its parameters
		$claims[ClosureUseListNode::class]['useKeyword'] = [$joined, null];

		foreach (self::Coloned as $class) {
			$claims[$class]['colon'] = [Claim::noSpace(), null];
		}

		// the braces of a group import, which the plain form leaves empty
		$claims[Statement\UseNode::class]['backslash'] = [Claim::noSpace(), Claim::noSpace()];
		$claims[Statement\UseNode::class]['openBrace'] = [Claim::noSpace(), Claim::noSpace()];
		$claims[Statement\UseNode::class]['closeBrace'] = [Claim::noSpace(), null];
		// the function or const of an import, in front of the name it qualifies
		foreach ([Statement\UseNode::class, UseItemNode::class] as $class) {
			$claims[$class]['kindKeyword'] = [null, Claim::singleSpace()];
		}

		// the modifier of a trait alias, in front of the alias: `as protected foo`
		$claims[TraitAliasNode::class]['modifier'] = [null, $this->followedByCode(...)];
		return $claims;
	}


	/**
	 * A single space after a keyword and the code that follows it on its line; punctuation closing it hugs it:
	 * `return;`, `default:`. What may begin on the next line is left there: a body after `else`, `do` or a brace,
	 * a list of several items (`const`, `global`, `use`), attributes, whatever follows a comment, and by the option
	 * an expression spanning several lines.
	 */
	private function followedByCode(Gap $gap): ?Claim
	{
		static $joined = new Claim(Space::Single, line: Line::Same);
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
			return Claim::singleSpace();
		}

		$operand = self::findOperand($token, $next);
		return ($operand instanceof SeparatedNodeList && count($operand->getItems()) > 1)
			|| ($this->allowMultilineExpression && $operand !== null && $token->is(self::ExpressionKeywords) && $operand->isMultiLine())
			? Claim::singleSpace()
			: $joined;
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
