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
use PhpSyntax\Nodes\CatchNode;
use PhpSyntax\Nodes\ClosureUsesNode;
use PhpSyntax\Nodes\ElseIfNode;
use PhpSyntax\Nodes\ElseNode;
use PhpSyntax\Nodes\Expression\ClosureNode;
use PhpSyntax\Nodes\Expression\MatchNode;
use PhpSyntax\Nodes\FinallyNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Member\PropertyHookNode;
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Nodes\Member\TraitAliasNode;
use PhpSyntax\Nodes\Member\TraitUseNode;
use PhpSyntax\Nodes\ParameterNode;
use PhpSyntax\Nodes\SeparatedNodeList;
use PhpSyntax\Nodes\Statement;
use PhpSyntax\Nodes\UseItemNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use function count, in_array;


/**
 * A single space after a language construct and what follows it on its line (`return $a`, `new Foo`,
 * `function ()`, `public static`), before and after the keyword that continues one (`} else {`, `$a as $b`,
 * `} catch (`), before the brace or the body a structure opens on the same line, and inside the braces of
 * abbreviated property hooks (`{ get; set; }`); the colon of the alternative syntax and of a label hugs
 * what is before it, and the braces of a group use hug their names. What may begin on the line below
 * a construct is left there: a body, a list of several items, attributes and whatever follows a comment.
 * `as`, `insteadof` and the `use` of a closure stay on the line of what is before them.
 * `fn` hugs its parenthesis, or takes a space before it by an option, and another option lets an expression
 * spanning several lines begin below `return`, `throw`, `yield`, `print` or `echo`.
 */
#[RuleInfo(
	'dresscode/construct-spacing',
	Stage::Formatting,
	description: 'Puts a single space around language constructs',
)]
final class ConstructSpacingRule extends GapRule implements ConfigurableRule
{
	private const FollowedBySpace = [
		'returnKeyword', 'printKeyword', 'throwKeyword', 'cloneKeyword', 'newKeyword', 'yieldKeyword',
		'yieldFromKeyword', 'includeKeyword', 'namespaceKeyword', 'constKeyword', 'caseKeyword', 'globalKeyword',
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
		Statement\IfNode::class, ElseIfNode::class, ElseNode::class, Statement\WhileNode::class, Statement\DoWhileNode::class,
		Statement\ForNode::class, Statement\ForeachNode::class, Statement\DeclareNode::class, Statement\TryNode::class,
		CatchNode::class, FinallyNode::class, Statement\FunctionNode::class, MethodNode::class, ClosureNode::class,
		PropertyHookNode::class,
	];
	private const Coloned = [
		Statement\IfNode::class, ElseIfNode::class, ElseNode::class, Statement\WhileNode::class, Statement\ForNode::class,
		Statement\ForeachNode::class, Statement\SwitchNode::class, Statement\DeclareNode::class, Statement\LabelNode::class,
	];
	private const ExpressionKeywords = [
		TokenKind::Return,
		TokenKind::Throw,
		TokenKind::Yield,
		TokenKind::YieldFrom,
		TokenKind::Print,
		TokenKind::Echo,
	];

	private Claim $arrowFunction;

	private bool $allowMultiLineExpression = false;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'arrowFunction' => Expect::anyOf('none', 'single')->default('none')
				->description('Between fn and its parenthesis: none for fn(), as PER writes it, single for fn ()'),
			'allowMultiLineExpression' => Expect::bool(false)
				->description('An expression spanning several lines may begin on the line below return, throw, yield, print or echo'),
		]);
	}


	public function configure(array $options): void
	{
		$this->arrowFunction = $options['arrowFunction'] === 'single' ? Claim::single() : Claim::none();
		$this->allowMultiLineExpression = $options['allowMultiLineExpression'];
	}


	public function getClaims(): array
	{
		$joined = new Claim(Space::Single, line: Line::Same);
		$any = [];
		foreach (self::FollowedBySpace as $slot) {
			$before = match (true) {
				$slot === 'asKeyword' || $slot === 'insteadofKeyword' => $joined,
				in_array($slot, self::PrecededBySpace, true) => Claim::single(),
				default => null,
			};
			$any[$slot] = [$before, $this->followedByCode(...)];
		}

		// the keyword of an echo is `<?=` as well, and what follows the open tag is the template's
		$any['echoKeyword'] = [null, fn(Gap $gap) => $gap->token->is(TokenKind::OpenTagWithEcho) ? null : $this->followedByCode($gap)];
		$any['fnKeyword'] = [null, $this->arrowFunction];
		// a pair of braces with nothing between them is written {}
		$any['closeBrace'] = [fn(Gap $gap) => $gap->token->getPrevious()?->is('{') ?? false ? Claim::none() : null, null];
		$claims = ['*' => $any];
		// the braces, bodies and colons of the structures; those of a dynamic name or a ternary are not theirs
		foreach (self::Braced as $class) {
			$claims[$class]['openBrace'] = [Claim::single(), null];
		}

		// the abbreviated hooks of a property: `{ get; set; }`
		foreach ([PropertyNode::class, ParameterNode::class] as $class) {
			$claims[$class]['openBrace'] = [Claim::single(), Claim::single()];
			$claims[$class]['closeBrace'] = [Claim::single(), null];
		}

		foreach (self::Bodied as $class) {
			$claims[$class]['body'] = [Claim::single(), null];
		}

		// the use of a closure stays on the line of its parameters
		$claims[ClosureUsesNode::class]['useKeyword'] = [$joined, null];

		foreach (self::Coloned as $class) {
			$claims[$class]['colon'] = [Claim::none(), null];
		}

		// the braces of a group import, which the plain form leaves empty
		$claims[Statement\UseNode::class]['namespaceSeparator'] = [Claim::none(), Claim::none()];
		$claims[Statement\UseNode::class]['openBrace'] = [Claim::none(), Claim::none()];
		$claims[Statement\UseNode::class]['closeBrace'] = [Claim::none(), null];
		// the function or const of an import, in front of the name it qualifies
		foreach ([Statement\UseNode::class, UseItemNode::class] as $class) {
			$claims[$class]['type'] = [null, Claim::single()];
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
		if ($next === null || $next->is(';', ':', ',', ')', ']', TokenKind::DoubleColon, TokenKind::CloseTag)) {
			return null;
		}

		if (
			$next->is('{', TokenKind::Attribute)
			|| $token->is(TokenKind::Else, TokenKind::Do)
			|| $token->hasCommentUpTo($next)
		) {
			return Claim::single();
		}

		$operand = self::findOperand($token, $next);
		return ($operand instanceof SeparatedNodeList && count($operand->getItems()) > 1)
			|| ($this->allowMultiLineExpression && $operand !== null && $token->is(...self::ExpressionKeywords) && self::isMultiLine($operand))
			? Claim::single()
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


	private static function isMultiLine(Node $node): bool
	{
		$last = $node->getLastToken();
		for ($token = $node->getFirstToken(); $token !== null && $token !== $last; $token = $token->getNext()) {
			foreach ($token->trailingTrivia as $trivia) {
				if ($trivia->isEndOfLine()) {
					return true;
				}
			}
		}

		return false;
	}
}
