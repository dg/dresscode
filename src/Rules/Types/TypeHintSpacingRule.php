<?php declare(strict_types=1);

namespace DressCode\Rules\Types;

use DressCode\Claim;
use DressCode\ConfigurableRule;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Nodes\CatchNode;
use PhpSyntax\Nodes\Expression\ArrowFunctionNode;
use PhpSyntax\Nodes\Expression\ClosureNode;
use PhpSyntax\Nodes\Member\ClassConstNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Nodes\ParameterNode;
use PhpSyntax\Nodes\Statement\EnumNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Nodes\Type\IntersectionTypeNode;
use PhpSyntax\Nodes\Type\NullableTypeNode;
use PhpSyntax\Nodes\Type\UnionTypeNode;


/**
 * Spacing of type declarations: `?int` without a gap, `int|string` without spaces around the bar,
 * a single space between a type and the name it describes, `): int` for a return type and `enum Suit: string`
 * for the backing type of an enum. The types of a catch are written `A|B` as PER writes them, or `A | B`
 * as PSR-12 did, by the option.
 */
#[RuleInfo(
	'dresscode/type-hint-spacing',
	Stage::Formatting,
	description: 'Normalizes whitespace in type declarations',
)]
final class TypeHintSpacingRule extends GapRule implements ConfigurableRule
{
	private Claim $catchTypes;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'catchTypes' => Expect::anyOf('none', 'single')->default('none')
				->description('Around the bar between the types of a catch: none for catch (A|B $e) as PER writes it, single for catch (A | B $e) as PSR-12 did'),
		]);
	}


	public function configure(array $options): void
	{
		$this->catchTypes = $options['catchTypes'] === 'single' ? Claim::single() : Claim::none();
	}


	public function getClaims(): array
	{
		$returnType = ['colon' => [Claim::none(), Claim::single()]];
		$typed = ['type' => [null, Claim::single()]];
		return [
			NullableTypeNode::class => ['question' => [null, Claim::none()]],
			UnionTypeNode::class => ['types:separator' => [Claim::none(), Claim::none()]],
			IntersectionTypeNode::class => ['types:separator' => [Claim::none(), Claim::none()]],
			CatchNode::class => ['types:separator' => [$this->catchTypes, $this->catchTypes], 'types' => [null, Claim::single()]],
			ParameterNode::class => $typed,
			PropertyNode::class => $typed,
			ClassConstNode::class => $typed,
			FunctionNode::class => $returnType,
			MethodNode::class => $returnType,
			ClosureNode::class => $returnType,
			ArrowFunctionNode::class => $returnType,
			EnumNode::class => $returnType,
		];
	}
}
