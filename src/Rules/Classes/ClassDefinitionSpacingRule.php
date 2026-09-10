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
 * beforeParenthesis). The modifiers before it are dresscode/construct-spacing, the backing type
 * of an enum dresscode/type-hint-spacing.
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
		$single = [Claim::single(), Claim::single()];
		$extends = ['extendsKeyword' => $single];
		$implements = ['implementsKeyword' => $single];
		$brace = ['openBrace' => [Claim::single(), null]];
		$anonymous = fn(Gap $gap) => $gap->token->getNext()?->is('(') ?? false
			? ($this->beforeParenthesis === self::Single ? Claim::single() : Claim::none())
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
