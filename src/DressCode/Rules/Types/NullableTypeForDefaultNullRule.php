<?php declare(strict_types=1);

namespace DressCode\Rules\Types;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ConstantFetchNode;
use PhpSyntax\Nodes\ParameterNode;
use PhpSyntax\Nodes\Type\NamedTypeNode;
use PhpSyntax\Nodes\Type\NullableTypeNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;


/**
 * An explicit `?` on the type of a parameter whose default is `null`, instead of the deprecated
 * implicit nullability; a union or intersection type is left alone.
 */
#[RuleInfo(
	'dresscode/nullable-type-for-default-null',
	Stage::Structure,
	description: 'Marks the type of a parameter defaulting to null as nullable',
)]
final class NullableTypeForDefaultNullRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [ParameterNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ParameterNode
			|| !($type = $node->type) instanceof NamedTypeNode
			|| strcasecmp($type->name->token->text, 'mixed') === 0
			|| !$node->default instanceof ConstantFetchNode
			|| strcasecmp($node->default->name->token->text, 'null') !== 0
			|| !$context->report($type, 'The type of a parameter defaulting to null must be nullable')
		) {
			return;
		}

		$nullable = (new Parser)->parseType('?int');
		assert($nullable instanceof NullableTypeNode);
		$inner = clone $type;
		$nullable->question->setLeadingTrivia($inner->name->token->leadingTrivia);
		$inner->name->token->setLeadingTrivia([]);
		$nullable->setType($inner);
		$node->setType($nullable);
	}
}
