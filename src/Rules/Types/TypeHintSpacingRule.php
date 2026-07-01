<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Types;

use DressCode\{Claim, ConfigurableRule, GapRule, Line, RuleInfo, Space, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\Nodes\{CatchNode, ParameterNode};
use PhpSyntax\Nodes\Expression\{ArrowFunctionNode, ClosureNode};
use PhpSyntax\Nodes\Member\{ClassConstNode, MethodNode, PropertyNode};
use PhpSyntax\Nodes\Statement\{EnumNode, FunctionNode};
use PhpSyntax\Nodes\Type\{IntersectionTypeNode, NullableTypeNode, UnionTypeNode};


/**
 * Spacing of type declarations, which stay on one line with what they describe: `?int` without a gap,
 * `int|string` without spaces around the bar, a single space between a type and the name it describes,
 * `): int` for a return type and `enum Suit: string` for the backing type of an enum. The types of a catch
 * are written `A|B` as PER writes them, or `A | B` as PSR-12 writes them, by the option.
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
		$this->catchTypes = new Claim($options['catchTypes'] === 'single' ? Space::Single : Space::None, line: Line::Same);
	}


	public function getClaims(): array
	{
		$hug = new Claim(Space::None, line: Line::Same);
		$joined = new Claim(Space::Single, line: Line::Same);
		$returnType = ['colon' => [$hug, $joined]];
		$typed = ['type' => [null, $joined]];
		return [
			NullableTypeNode::class => ['question' => [null, $hug]],
			UnionTypeNode::class => ['types:separator' => [$hug, $hug]],
			IntersectionTypeNode::class => ['types:separator' => [$hug, $hug]],
			CatchNode::class => ['types:separator' => [$this->catchTypes, $this->catchTypes], 'types' => [null, $joined]],
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
