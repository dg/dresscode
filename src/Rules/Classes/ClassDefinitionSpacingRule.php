<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

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
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Nodes\Statement\EnumNode;
use PhpSyntax\Nodes\Statement\InterfaceNode;
use PhpSyntax\Nodes\Statement\TraitNode;
use PhpSyntax\Token;


/**
 * The head of a class declaration on one line with single spaces between its words:
 * `class Foo extends Bar implements Baz {`, `new class ($a) extends Bar` (or `new class($a)` without
 * beforeParenthesis); the brace may take the next line, and so may the interfaces a class implements or an
 * interface extends, and whatever follows a comment. The modifiers before it are dresscode/construct-spacing,
 * the backing type of an enum dresscode/type-hint-spacing.
 */
#[RuleInfo(
	'dresscode/class-definition-spacing',
	Stage::Formatting,
	description: 'Puts single spaces in the head of a class declaration',
)]
final class ClassDefinitionSpacingRule extends GapRule implements ConfigurableRule
{
	private const Single = 'single';
	private const None = 'none';

	private string $beforeParenthesis = self::Single;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'beforeParenthesis' => Expect::anyOf(self::Single, self::None)->default(self::Single)
				->description('Between class and the arguments of an anonymous class: single writes new class ($a), none hugs them'),
		]);
	}


	public function configure(array $options): void
	{
		$this->beforeParenthesis = $options['beforeParenthesis'];
	}


	public function getClaims(): array
	{
		$joined = new Claim(Space::Single, line: Line::Same);
		$hugged = new Claim(Space::None, line: Line::Same);
		$before = fn(Gap $gap) => self::releaseLineAtComment($joined, $gap->token->getPrevious(), $gap->token);
		$after = fn(Gap $gap) => self::releaseLineAtComment($joined, $gap->token, $gap->token->getNext());
		$extends = ['extendsKeyword' => [$before, $after]];
		// a list of interfaces may begin on the line below its keyword
		$implements = ['implementsKeyword' => [$before, Claim::single()]];
		$brace = ['openBrace' => [Claim::single(), null]];
		$anonymous = fn(Gap $gap) => self::releaseLineAtComment(match (true) {
			$gap->token->getNext()?->is('(') ?? false => $this->beforeParenthesis === self::Single ? $joined : $hugged,
			$gap->token->getNext()?->is('{') ?? false => Claim::single(),
			default => $joined,
		}, $gap->token, $gap->token->getNext());
		return [
			ClassNode::class => ['classKeyword' => [null, $after]] + $extends + $implements + $brace,
			InterfaceNode::class => ['interfaceKeyword' => [null, $after], 'extendsKeyword' => [$before, Claim::single()]] + $brace,
			TraitNode::class => ['traitKeyword' => [null, $after]] + $brace,
			EnumNode::class => ['enumKeyword' => [null, $after]] + $implements + $brace,
			AnonymousClassNode::class => ['classKeyword' => [null, $anonymous]] + $extends + $implements + $brace,
		];
	}


	/** The claim without its line where a comment stands in the gap, whose line break is not the rule's to take out. */
	private static function releaseLineAtComment(Claim $claim, ?Token $first, ?Token $second): Claim
	{
		return $claim->line !== null && $first !== null && $second !== null && $first->hasCommentUpTo($second)
			? new Claim($claim->space)
			: $claim;
	}
}
