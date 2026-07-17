<?php declare(strict_types=1);

namespace DressCode\Rules\Whitespace;

use DressCode\Claim;
use DressCode\ConfigurableRule;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Nodes\CatchNode;
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
use PhpSyntax\Nodes\Statement;
use PhpSyntax\Nodes\UseItemNode;
use PhpSyntax\TokenKind;
use function in_array;


/**
 * A single space after a language construct followed by more on its line (`return $a`, `new Foo`,
 * `function ()`, `public static`), before and after the keyword that continues one (`} else {`, `$a as $b`,
 * `} catch (`), before the brace or the body a structure opens on the same line, and inside the braces of
 * abbreviated property hooks (`{ get; set; }`); the colon of the alternative syntax and of a label hugs
 * what is before it, and the braces of a group use hug their names. `fn` hugs its parenthesis, or takes
 * a space before it by the option.
 */
#[RuleInfo(
	'dresscode/construct-spacing',
	Stage::Formatting,
	description: 'Puts a single space around language constructs',
)]
final class ConstructSpacingRule extends GapRule implements ConfigurableRule
{
	private const FollowedBySpace = [
		'returnKeyword', 'echoKeyword', 'printKeyword', 'throwKeyword', 'cloneKeyword', 'newKeyword', 'yieldKeyword',
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
		TraitUseNode::class, PropertyNode::class, ParameterNode::class,
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

	private Claim $arrowFunction;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'arrowFunction' => Expect::anyOf('none', 'single')->default('none')
				->description('Between fn and its parenthesis: none for fn(), as PER writes it, single for fn ()'),
		]);
	}


	public function configure(array $options): void
	{
		$this->arrowFunction = $options['arrowFunction'] === 'single' ? Claim::single() : Claim::none();
	}


	public function getClaims(): array
	{
		$any = [];
		foreach (self::FollowedBySpace as $slot) {
			$any[$slot] = [in_array($slot, self::PrecededBySpace, strict: true) ? Claim::single() : null, self::followedByCode(...)];
		}

		// the keyword of an echo is `<?=` as well, and what follows the open tag is the template's
		$any['echoKeyword'] = [null, fn(Gap $gap) => $gap->token->is(TokenKind::OpenTagWithEcho) ? null : self::followedByCode($gap)];
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
		$claims[TraitAliasNode::class]['modifier'] = [null, self::followedByCode(...)];
		return $claims;
	}


	/** A single space after a keyword that code follows on the line; punctuation closing it hugs it: `return;`, `default:`. */
	private static function followedByCode(Gap $gap): ?Claim
	{
		$next = $gap->token->getNext();
		return $next === null || $next->is(';', ':', ',', ')', ']', TokenKind::DoubleColon, TokenKind::CloseTag) ? null : Claim::single();
	}
}
