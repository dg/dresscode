<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\Claim;
use DressCode\ConfigurableRule;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Nodes\Statement\EnumNode;
use PhpSyntax\Nodes\Statement\InterfaceNode;
use PhpSyntax\Nodes\Statement\TraitNode;


/**
 * The head of a class declaration with single spaces between its words, up to the opening brace:
 * `class Foo extends Bar implements Baz {`, `new class ($a) extends Bar` (or `new class($a)` without
 * spaceBeforeParenthesis). The modifiers before it are dresscode/construct-spacing, the backing type
 * of an enum dresscode/type-hint-spacing.
 */
#[RuleInfo(
	'dresscode/class-definition-spacing',
	Stage::Formatting,
	description: 'Puts single spaces in the head of a class declaration',
)]
final class ClassDefinitionSpacingRule extends GapRule implements ConfigurableRule
{
	private bool $spaceBeforeParenthesis = true;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'spaceBeforeParenthesis' => Expect::bool(true)->description('Between class and the arguments of an anonymous class: new class ($a), false hugs them'),
		]);
	}


	public function configure(array $options): void
	{
		$this->spaceBeforeParenthesis = $options['spaceBeforeParenthesis'];
	}


	public function getClaims(): array
	{
		$single = [Claim::single(), Claim::single()];
		$extends = ['extendsKeyword' => $single];
		$implements = ['implementsKeyword' => $single];
		$brace = ['openBrace' => [Claim::single(), null]];
		$anonymous = fn(Gap $gap) => $gap->token->getNext()?->is('(') ?? false
			? ($this->spaceBeforeParenthesis ? Claim::single() : Claim::none())
			: Claim::single();
		return [
			ClassNode::class => ['classKeyword' => [null, Claim::single()]] + $extends + $implements + $brace,
			InterfaceNode::class => ['interfaceKeyword' => [null, Claim::single()]] + $extends + $brace,
			TraitNode::class => ['traitKeyword' => [null, Claim::single()]] + $brace,
			EnumNode::class => ['enumKeyword' => [null, Claim::single()]] + $implements + $brace,
			AnonymousClassNode::class => ['classKeyword' => [null, $anonymous]] + $extends + $implements + $brace,
		];
	}
}
