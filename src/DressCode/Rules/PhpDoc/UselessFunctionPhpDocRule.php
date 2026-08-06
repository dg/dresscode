<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\ConfigurableRule;
use DressCode\NativeType;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypelessParamTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode as PhpDocTypeNode;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Nodes\Type\NamedTypeNode;
use PhpSyntax\Nodes\Type\NullableTypeNode;
use PhpSyntax\Nodes\TypeNode;
use PhpSyntax\Token;


/**
 * A doc comment of a function that only repeats its signature, `@param int $a` on `int $a` and `@return void`
 * on `: void`, without a description of anything, is removed. An annotation of an array, iterable or traversable
 * parameter that says more than the native type (`int[]`, `array<string, Foo>`) keeps the doc comment.
 */
#[RuleInfo(
	'dresscode/useless-function-phpdoc',
	Stage::Structure,
	description: 'Removes a function doc comment that only repeats the native types',
	modifiesComments: true,
)]
final class UselessFunctionPhpDocRule extends Rule implements ConfigurableRule
{
	/** @var list<string> */
	private array $traversableTypeHints = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'traversableTypeHints' => Expect::listOf('string')->default(['Traversable'])
				->description('Classes whose annotation may say more than the native type, like array and iterable'),
		]);
	}


	public function configure(array $options): void
	{
		$this->traversableTypeHints = array_values(array_map(fn(string $name) => strtolower(ltrim($name, '\\')), $options['traversableTypeHints']));
	}


	public function getVisitedTypes(): array
	{
		return [MethodNode::class, FunctionNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof MethodNode && !$node instanceof FunctionNode)
			|| ($docComment = $node->getDocComment()) === null
			|| $docComment->inInterpolation
		) {
			return;
		}

		$params = [];
		foreach ($node->params->getItems() as $param) {
			$params[$param->var->name instanceof Token ? $param->var->name->text : ''] = $param->type;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$tree = $context->getAnalysis(PhpDoc::class)->parse($docComment);
		foreach ($tree->children as $child) {
			if ($child instanceof PhpDocTextNode) {
				if (trim($child->text) !== '') {
					return;
				}

				continue;
			} elseif (!$child instanceof PhpDocTagNode) {
				return;
			} elseif ($child->value instanceof ReturnTagValueNode) {
				$useless = $child->value->description === '' && $this->repeats($child->value->type, $node->returnType, $resolver);
			} elseif ($child->value instanceof TypelessParamTagValueNode) {
				$useless = $child->value->description === '' && ($params[$child->value->parameterName] ?? null) !== null;
			} elseif ($child->value instanceof ParamTagValueNode) {
				$useless = $child->value->description === ''
					&& $this->repeats($child->value->type, $params[$child->value->parameterName] ?? null, $resolver);
			} else {
				return;
			}

			if (!$useless) {
				return;
			}
		}

		if ($context->report($node, 'Useless doc comment, it only repeats the signature', trivia: $docComment)) {
			$node->removeDocComment();
		}
	}


	/** Whether the annotation says exactly what the native type says. */
	private function repeats(PhpDocTypeNode $annotation, ?TypeNode $native, NameResolver $resolver): bool
	{
		if ($native === null) {
			return false;
		}

		$bare = $native instanceof NullableTypeNode ? $native->type : $native;
		if ($bare instanceof NamedTypeNode) {
			$traversable = NativeType::isTraversable($bare->name->getName(), $this->traversableTypeHints, fn(string $name) => $resolver->resolveClass($bare->name));
			$plainIterable = $annotation instanceof IdentifierTypeNode && in_array(strtolower($annotation->name), ['array', 'iterable'], strict: true);
			if ($traversable && !$plainIterable) {
				return false;
			}
		}

		return NativeType::matches($annotation, trim((string) $native));
	}
}
