<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ClassLikeNode;
use PhpSyntax\Nodes\Member\ClassConstNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Nodes\Type\NamedTypeNode;
use PhpSyntax\Nodes\Type\NullableTypeNode;
use PhpSyntax\Nodes\TypeNode;
use PhpSyntax\Token;


/**
 * A doc comment saying nothing but `{@inheritDoc}` is removed: PHP inherits documentation anyway. It stays on
 * a function whose signature does not say everything: a missing type, or an array or iterable type whose items
 * the parent documents.
 */
#[RuleInfo(
	'dresscode/useless-inheritdoc',
	Stage::Structure,
	description: 'Removes a doc comment consisting of @inheritDoc only',
	modifiesComments: true,
)]
final class UselessInheritDocRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [MethodNode::class, FunctionNode::class, PropertyNode::class, ClassConstNode::class, ClassLikeNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			$node instanceof Token
			|| ($docComment = $node->getDocComment()) === null
			|| $docComment->inInterpolation
			|| !preg_match('~^(?:\{@inheritDoc\}|@inheritDoc)$~i', trim(substr($docComment->text, 3, -2), " \t\r\n*"))
		) {
			return;
		}

		if ($node instanceof MethodNode || $node instanceof FunctionNode) {
			$types = [$node->returnType];
			foreach ($node->params->getItems() as $param) {
				$types[] = $param->type;
			}

			foreach ($types as $type) {
				if ($type === null || self::isIterable($type)) {
					return;
				}
			}
		}

		if ($context->report($node, 'Useless doc comment with @inheritDoc', trivia: $docComment)) {
			$node->removeDocComment();
		}
	}


	private static function isIterable(TypeNode $type): bool
	{
		$type = $type instanceof NullableTypeNode ? $type->type : $type;
		return $type instanceof NamedTypeNode
			&& in_array(strtolower($type->name->getName()), ['array', 'iterable'], strict: true);
	}
}
