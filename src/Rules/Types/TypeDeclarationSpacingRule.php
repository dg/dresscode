<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Types;

use DressCode\{Claim, Decision, GapRule, Line, RuleInfo, Space, Stage, Values};
use DressCode\Domains\Shapes;
use PhpSyntax\Nodes\{CatchNode, ParameterNode};
use PhpSyntax\Nodes\Expression\{ArrowFunctionNode, ClosureNode};
use PhpSyntax\Nodes\Member\{ClassConstNode, MethodNode, PropertyNode};
use PhpSyntax\Nodes\Statement\{EnumNode, FunctionNode};
use PhpSyntax\Nodes\Type\{IntersectionTypeNode, NullableTypeNode, UnionTypeNode};


/**
 * Spacing of type declarations, which stay on one line with what they describe: `?int` without a gap,
 * `int|string` without spaces around the bar, a single space between a type and the name it describes,
 * `): int` for a return type and `enum Suit: string` for the backing type of an enum. The types of a catch
 * are written `A|B` as PER writes them, or `A | B` as PSR-12 writes them, as `spacing.catchType` says.
 */
#[RuleInfo(Stage::Formatting)]
final class TypeDeclarationSpacingRule extends GapRule
{
	private const Type = 'spacing.typeDeclaration';
	private const Catch = 'spacing.catchType';

	private bool $type = true;

	private ?Claim $catch = null;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Type, new Shapes(['compact' => ['?int $x', 'none inside a type, a single space before the name']]), 'The whitespace of a type declaration, which stays on one line with what it describes: none inside a type, a single space between a type and the name it declares, `): int` for a return type and `enum Suit: string` for a backing type'),
			new Decision(self::Catch, new Shapes([
				'spaced' => ['catch (A | B $e)', 'a single space around the bar'],
				'compact' => ['catch (A|B $e)', 'no space around the bar'],
			]), 'The space around the bar between the types of a `catch`'),
		];
	}


	public function configure(Values $values): void
	{
		$this->type = !$values->isKept(self::Type);
		$this->catch = match ($values->find(self::Catch)?->getShape()) {
			'spaced' => new Claim(Space::Single, line: Line::Same, decision: self::Catch),
			'compact' => new Claim(Space::None, line: Line::Same, decision: self::Catch),
			default => null,
		};
	}


	public function getClaims(): array
	{
		$catch = ['types:separator' => [$this->catch, $this->catch]];
		if (!$this->type) {
			return [CatchNode::class => $catch];
		}

		$hug = new Claim(Space::None, line: Line::Same, decision: self::Type);
		$joined = new Claim(Space::Single, line: Line::Same, decision: self::Type);
		$returnType = ['colon' => [$hug, $joined]];
		$typed = ['type' => [null, $joined]];
		return [
			NullableTypeNode::class => ['question' => [null, $hug]],
			UnionTypeNode::class => ['types:separator' => [$hug, $hug]],
			IntersectionTypeNode::class => ['types:separator' => [$hug, $hug]],
			CatchNode::class => $catch + ['variable' => [$joined, null]],
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
