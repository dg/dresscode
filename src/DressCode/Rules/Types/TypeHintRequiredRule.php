<?php declare(strict_types=1);

namespace DressCode\Rules\Types;

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
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypelessParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AttributeGroupNode;
use PhpSyntax\Nodes\ClassLikeNode;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Nodes\Statement\ReturnNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function count, in_array, ord;


/**
 * Parameters, properties and return values have native types: a missing one is added from the `@param`,
 * `@var` or `@return` annotation when PHP can express it, and the annotation goes away when it then says
 * nothing more. A declaration with neither is reported, as is an array, iterable or traversable one whose
 * annotation does not say what the items are. A function inheriting its documentation (`{@inheritDoc}`,
 * `#[\Override]`) is left alone. Each of the three places can be turned off on its own.
 */
#[RuleInfo(
	'dresscode/type-hint-required',
	Stage::Structure,
	description: 'Adds native types from annotations and reports declarations without any type',
	modifiesComments: true,
)]
final class TypeHintRequiredRule extends Rule implements ConfigurableRule
{
	private bool $parameters = true;
	private bool $properties = true;
	private bool $returns = true;

	/** @var list<string> */
	private array $traversableTypeHints = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'parameters' => Expect::bool(true),
			'properties' => Expect::bool(true),
			'returns' => Expect::bool(true),
			'traversableTypeHints' => Expect::listOf('string')->default(['Traversable'])
				->description('Classes treated like array and iterable: their annotation must say what the items are'),
		]);
	}


	public function configure(array $options): void
	{
		$this->parameters = $options['parameters'];
		$this->properties = $options['properties'];
		$this->returns = $options['returns'];
		$this->traversableTypeHints = array_values(array_map(fn(string $name) => strtolower(ltrim($name, '\\')), $options['traversableTypeHints']));
	}


	public function getVisitedTypes(): array
	{
		return [FunctionNode::class, MethodNode::class, Expression\ClosureNode::class, PropertyNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof PropertyNode) {
			if ($this->properties) {
				$this->checkProperty($node, $context);
			}

			return;
		} elseif (
			!$node instanceof FunctionNode
			&& !$node instanceof MethodNode
			&& !$node instanceof Expression\ClosureNode
		) {
			return;
		}

		$docComment = $node instanceof Expression\ClosureNode ? null : $node->getDocComment();
		if ($docComment?->inInterpolation || self::isInherited($node, $docComment)) {
			return;
		}

		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$tree = $docComment ? $phpDoc->parse($docComment) : null;

		// both halves read one tree and hand back the tags to drop, so it is rewritten once
		$removed = [];
		if ($this->parameters && !$node instanceof Expression\ClosureNode) {
			$removed = $this->checkParameters($node, $tree, $docComment, $context);
		}

		if ($this->returns) {
			$removed = [...$removed, ...$this->checkReturn($node, $tree, $docComment, $context)];
		}

		if ($removed !== [] && $tree !== null) {
			self::removeTags($node, $tree, $removed, $docComment, $phpDoc);
		}
	}


	/**
	 * @return list<PhpDocTagNode>  the annotations that say nothing the native type does not
	 */
	private function checkParameters(
		FunctionNode|MethodNode $node,
		?PhpDocNode $tree,
		?Trivia $docComment,
		RuleContext $context,
	): array
	{
		$tags = $prefixed = [];
		foreach ($tree->children ?? [] as $child) {
			$value = $child instanceof PhpDocTagNode ? $child->value : null;
			if ($value instanceof ParamTagValueNode || $value instanceof TypelessParamTagValueNode) {
				if (strtolower($child->name) === '@param') {
					$tags[$value->parameterName] = $child;
				} else {
					$prefixed[$value->parameterName] = true;
				}
			}
		}

		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$resolver = $context->getAnalysis(NameResolver::class);
		$templates = $phpDoc->findTemplates($node);
		$removed = [];
		foreach ($node->params->getItems() as $param) {
			$name = $param->var->name instanceof Token ? $param->var->name->text : null;
			$tag = $name === null ? null : ($tags[$name] ?? null);
			$value = $tag?->value;
			$annotation = $value instanceof ParamTagValueNode ? $value->type : null;
			$native = $param->type === null ? null : trim((string) $param->type);
			if ($native === null && $annotation === null) {
				if ($name !== null && !isset($prefixed[$name])) {
					$context->report($param->var, "Parameter $name has no type hint nor @param annotation");
				}

				continue;
			}

			if ($native === null) {
				$native = NativeType::fromAnnotation($annotation, NativeType::Parameter, $context->getPhpVersion(), $this->traversableTypeHints, $templates, fn(string $class) => $resolver->resolveClass((new Parser)->parseName($class)));
				if (
					$native === null
					|| (!$param->modifiers->isEmpty() && strtolower($native) === 'callable')
					|| !$context->report($param->var, "Parameter $name must have the native type '$native' from its @param annotation")
				) {
					continue;
				}

				$type = (new Parser)->parseType($native);
				$type->getLastToken()?->setTrailingTrivia([new Trivia(TriviaKind::Whitespace, ' ')]);
				$param->setType($type);
			}

			$bare = ltrim($native, '?');
			$traversable = NativeType::isTraversable($bare, $this->traversableTypeHints, fn(string $class) => $resolver->resolveClass((new Parser)->parseName($class)));
			if ($tag === null) {
				if ($traversable && ($name === null || !isset($prefixed[$name]))) {
					$context->report($param->var, "Traversable parameter $name has no @param annotation saying what the items are");
				}

				continue;
			}

			$uselessTypeless = $value instanceof TypelessParamTagValueNode && $value->description === '';
			$uselessTyped = $annotation !== null && $value->description === ''
				&& NativeType::matches($annotation, $native)
				&& (!$traversable || NativeType::isPlainIterable($annotation));
			if (
				($uselessTypeless || $uselessTyped)
				&& $docComment !== null
				&& $context->report($param->var, "Useless @param annotation for parameter $name", trivia: $docComment)
			) {
				$removed[] = $tag;
			}
		}

		return $removed;
	}


	/**
	 * @return list<PhpDocTagNode>
	 */
	private function checkReturn(
		FunctionNode|MethodNode|Expression\ClosureNode $node,
		?PhpDocNode $tree,
		?Trivia $docComment,
		RuleContext $context,
	): array
	{
		if (
			$node instanceof MethodNode
			&& in_array(strtolower($node->name->token->text), ['__construct', '__destruct'], strict: true)
		) {
			return [];
		}

		$tag = null;
		$prefixed = false;
		foreach ($tree->children ?? [] as $child) {
			if ($child instanceof PhpDocTagNode && $child->value instanceof ReturnTagValueNode) {
				strtolower($child->name) === '@return' ? $tag = $child : $prefixed = true;
			}
		}

		$value = $tag?->value;
		$annotation = $value instanceof ReturnTagValueNode ? $value->type : null;
		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$resolver = $context->getAnalysis(NameResolver::class);
		$resolve = fn(string $class) => $resolver->resolveClass((new Parser)->parseName($class));
		$php = $context->getPhpVersion();
		$native = $node->returnType === null ? null : trim((string) $node->returnType);
		$body = $node->body;
		$returnsValue = $body !== null && self::returnsValue($body);

		if ($native === null) {
			if ($annotation !== null) {
				$native = NativeType::fromAnnotation($annotation, NativeType::Return, $php, $this->traversableTypeHints, $phpDoc->findTemplates($node), $resolve);
				if (
					$native === null
					|| !$context->report($node->closeParen, "The function must have the native return type '$native' from its @return annotation")
				) {
					return [];
				}
			} elseif ($body === null || $returnsValue) {
				if (!$prefixed && !$node instanceof Expression\ClosureNode) {
					$context->report($node->closeParen, 'The function has no return type hint nor @return annotation');
				}

				return [];
			} elseif (!$context->report($node->closeParen, 'The function must have the void return type')) {
				return [];
			} else {
				$native = 'void';
			}

			$this->addReturnType($node, $native);
		} elseif (
			strtolower($native) === 'void'
			&& $annotation instanceof IdentifierTypeNode
			&& strtolower($annotation->name) === 'never'
			&& $php->isAtLeast('8.1')
			&& $context->report($node->closeParen, 'The return type must be never instead of void, as the @return annotation says')
		) {
			$node->returnType->replaceWith((new Parser)->parseType('never'));
			$native = 'never';
		}

		$traversable = NativeType::isTraversable(ltrim($native, '?'), $this->traversableTypeHints, $resolve);
		if ($tag === null) {
			if ($traversable && !$prefixed) {
				$context->report($node->closeParen, 'Traversable return type has no @return annotation saying what the items are');
			}

			return [];
		}

		assert($value instanceof ReturnTagValueNode && $annotation !== null);
		return $value->description === ''
			&& NativeType::matches($annotation, $native)
			&& (!$traversable || NativeType::isPlainIterable($annotation))
			&& $context->report($node->closeParen, 'Useless @return annotation', trivia: $docComment)
				? [$tag]
				: [];
	}


	private function checkProperty(PropertyNode $node, RuleContext $context): void
	{
		if (count($node->items) !== 1) {
			return;
		}

		$docComment = $node->getDocComment();
		if ($docComment?->inInterpolation) {
			return;
		}

		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$tree = $docComment ? $phpDoc->parse($docComment) : null;
		$tag = null;
		$prefixed = false;
		foreach ($tree->children ?? [] as $child) {
			if ($child instanceof PhpDocTagNode && $child->value instanceof VarTagValueNode) {
				strtolower($child->name) === '@var' ? $tag = $child : $prefixed = true;
			}
		}

		$value = $tag?->value;
		$annotation = $value instanceof VarTagValueNode ? $value->type : null;
		$resolver = $context->getAnalysis(NameResolver::class);
		$resolve = fn(string $class) => $resolver->resolveClass((new Parser)->parseName($class));
		$item = $node->items->getItems()[0];
		$name = $item->name->text;
		$native = $node->type === null ? null : trim((string) $node->type);
		if ($native === null && $annotation === null) {
			if (!$prefixed) {
				$context->report($item, "Property $name has no type hint nor @var annotation");
			}

			return;
		}

		if ($native === null) {
			$native = NativeType::fromAnnotation($annotation, NativeType::Property, $context->getPhpVersion(), $this->traversableTypeHints, $phpDoc->findTemplates($node), $resolve);
			if ($native === null) {
				return;
			}

			$defaultsToNull = $item->default instanceof Expression\ConstantFetchNode && strtolower($item->default->name->getName()) === 'null';
			if (
				$defaultsToNull
				&& !str_starts_with($native, '?')
				&& !preg_match('~(^|\|)null$~i', $native)
				&& strtolower($native) !== 'mixed'
			) {
				$native = str_contains($native, '|') ? "$native|null" : "?$native";
			}

			if (!$context->report($item, "Property $name must have the native type '$native' from its @var annotation")) {
				return;
			}

			$type = (new Parser)->parseType($native);
			$type->getLastToken()?->setTrailingTrivia([new Trivia(TriviaKind::Whitespace, ' ')]);
			$node->setType($type);
		}

		$traversable = NativeType::isTraversable(ltrim($native, '?'), $this->traversableTypeHints, $resolve);
		if ($tag === null) {
			if ($traversable && !$prefixed) {
				$context->report($item, "Traversable property $name has no @var annotation saying what the items are");
			}

			return;
		}

		assert($value instanceof VarTagValueNode && $annotation !== null && $tree !== null);
		if (
			$value->description === ''
			&& NativeType::matches($annotation, $native)
			&& (!$traversable || NativeType::isPlainIterable($annotation))
			&& $context->report($item, "Useless @var annotation for property $name", trivia: $docComment)
		) {
			self::removeTags($node, $tree, [$tag], $docComment, $phpDoc);
		}
	}


	private function addReturnType(FunctionNode|MethodNode|Expression\ClosureNode $node, string $native): void
	{
		$type = (new Parser)->parseType($native);
		$colon = new Token(ord(':'), ':');
		$colon->setTrailingTrivia([new Trivia(TriviaKind::Whitespace, ' ')]);
		$anchor = $node instanceof Expression\ClosureNode && $node->uses !== null ? $node->uses->closeParen : $node->closeParen;
		$type->getLastToken()?->setTrailingTrivia($anchor->trailingTrivia);
		$anchor->setTrailingTrivia([]);
		$node->setColon($colon);
		$node->setReturnType($type);
	}


	/** Whether the body returns a value or yields, looking past nested functions and classes. */
	private static function returnsValue(Node $node): bool
	{
		foreach ($node->getChildren() as $child) {
			if (
				$child instanceof Expression\ClosureNode
				|| $child instanceof Expression\ArrowFunctionNode
				|| $child instanceof FunctionNode
				|| $child instanceof ClassLikeNode
				|| $child instanceof Token
			) {
				continue;
			}

			if (
				($child instanceof ReturnNode && $child->expr !== null)
				|| $child instanceof Expression\YieldNode
				|| $child instanceof Expression\YieldFromNode
				|| self::returnsValue($child)
			) {
				return true;
			}
		}

		return false;
	}


	/** Whether the declaration documents itself by `{@inheritDoc}` or the `#[\Override]` attribute. */
	private static function isInherited(Node $node, ?Trivia $docComment): bool
	{
		if ($docComment !== null && preg_match('~@inheritDoc~i', $docComment->text)) {
			return true;
		}

		$attributes = $node->attributes ?? null;
		foreach ($attributes instanceof NodeList ? $attributes->getItems() : [] as $group) {
			foreach ($group instanceof AttributeGroupNode ? $group->attributes->getItems() : [] as $attribute) {
				if (strcasecmp(ltrim($attribute->name->getName(), '\\'), 'Override') === 0) {
					return true;
				}
			}
		}

		return false;
	}


	/**
	 * Removes the tags from the doc comment of the node, and the doc comment itself when nothing meaningful remains.
	 * @param  list<PhpDocTagNode>  $tags
	 */
	private static function removeTags(
		Node $node,
		PhpDocNode $tree,
		array $tags,
		Trivia $docComment,
		PhpDoc $phpDoc,
	): void
	{
		$tree->children = array_values(array_filter($tree->children, fn($child) => !in_array($child, $tags, strict: true)));
		if (PhpDoc::isEmpty($tree)) {
			$node->removeDocComment();
		} else {
			$node->replaceDocComment($phpDoc->print($tree, $docComment));
		}
	}
}
