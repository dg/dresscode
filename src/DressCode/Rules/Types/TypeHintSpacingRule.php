<?php declare(strict_types=1);

namespace DressCode\Rules\Types;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
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
use PhpSyntax\Token;


/**
 * Spacing of type declarations: `?int` without a gap, `int|string` without spaces around the bar,
 * a single space between a type and the name it describes, `): int` for a return type and `enum Suit: string`
 * for the backing type of an enum.
 */
#[RuleInfo(
	'dresscode/type-hint-spacing',
	Stage::Formatting,
	description: 'Normalizes whitespace in type declarations',
)]
final class TypeHintSpacingRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [
			NullableTypeNode::class, UnionTypeNode::class, IntersectionTypeNode::class,
			ParameterNode::class, PropertyNode::class, ClassConstNode::class,
			FunctionNode::class, MethodNode::class, ClosureNode::class, ArrowFunctionNode::class, EnumNode::class,
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$expected = [];
		if ($node instanceof NullableTypeNode) {
			$expected[] = [$node->question, ''];
		} elseif ($node instanceof UnionTypeNode || $node instanceof IntersectionTypeNode) {
			foreach ($node->types->getSeparators() as $separator) {
				$expected[] = [$separator->getPrevious(), ''];
				$expected[] = [$separator, ''];
			}
		} elseif ($node instanceof ParameterNode || $node instanceof PropertyNode || $node instanceof ClassConstNode) {
			if ($node->type !== null) {
				$expected[] = [$node->type->getLastToken(), ' '];
			}
		} elseif (
			$node instanceof FunctionNode
			|| $node instanceof MethodNode
			|| $node instanceof ClosureNode
			|| $node instanceof ArrowFunctionNode
			|| $node instanceof EnumNode
		) {
			if ($node->colon !== null) {
				$expected[] = [$node->colon->getPrevious(), ''];
				$expected[] = [$node->colon, ' '];
			}
		}

		foreach ($expected as [$token, $space]) {
			$current = $token?->getTrailingSpace();
			if (
				$token !== null
				&& $current !== null
				&& $current !== $space
				&& $context->report($token, 'Wrong whitespace in a type declaration')
			) {
				$token->setTrailingSpace($space);
			}
		}
	}
}
