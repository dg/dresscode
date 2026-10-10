<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Types;

use DressCode\Analyses\{Parameter, PhpDoc, Types};
use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage, Values};
use DressCode\Rules\NativeType;
use PHPStan\PhpDocParser\Ast\PhpDoc\{ParamTagValueNode, PhpDocNode, PhpDocTagNode, ReturnTagValueNode, TypelessParamTagValueNode, VarTagValueNode};
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, NameForm, Node, Token, Trivia};
use PhpSyntax\Nodes\{AnonymousClassNode, ClassLikeNode, Expression, ParameterNode, Scalar, TypeNode};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyNode, TraitUseNode};
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode, FunctionNode, InterfaceNode, ReturnNode};
use function count;


/**
 * Parameters, properties and return values have native types: a missing one is added from the `@param`,
 * `@var` or `@return` annotation when PHP can express it, nullable where the declaration defaults to null, and the
 * annotation goes away when it then says nothing more. A function that returns no value is declared void, and
 * a native void the annotation says is never becomes never. Any other declaration with neither is reported, as
 * is an array, iterable or traversable one whose annotation does not say what the items are. A function
 * inheriting its documentation (`{@inheritDoc}`, `#[\Override]`) is left alone. Each place can be turned off on its
 * own; a class constant is `ConstantTypeRequiredRule`'s.
 *
 * A native type written is risky: PHP enforces it, and an annotation that was wrong becomes a TypeError. None is
 * written where PHP would refuse it: against a default of another type, on a property redeclaring one of its parent or
 * of a trait it uses, and on a parameter its parent declares without that type, which only the types of the run show;
 * without them nothing is written on a property or a parameter of a method in a class with a parent, an interface or
 * a trait.
 */
#[RuleInfo(
	Stage::Structure,
	modifiesComments: true,
	decisions: ['types.traversableClasses'],
	analyses: [PhpDoc::class, Types::class, NameResolver::class],
)]
final class NativeTypeRequiredRule extends NodeRule
{
	private const Annotations = ['types.declaration.parameter' => '@param', 'types.declaration.return' => '@return', 'types.declaration.property' => '@var'];

	private bool $parameter = true;
	private bool $property = true;
	private bool $return = true;

	/** @var list<string> */
	private array $traversableClasses = [];


	public static function getDecisions(): array
	{
		return [
			new Decision('types.declaration.parameter', Domain::state('required'), 'A parameter declares its type, taken from `@param` where it has one and nullable where it defaults to null, and the annotation goes where it then says nothing more', ['A parameter with neither a type nor an annotation is reported']),
			new Decision('types.declaration.property', Domain::state('required'), 'A property declares its type, taken from `@var` where it has one and nullable where it defaults to null'),
			new Decision('types.declaration.return', Domain::state('required'), 'A function declares its return type, taken from `@return`, `void` where it returns no value and `never` where the annotation says so'),
		];
	}


	public function configure(Values $values): void
	{
		[$this->parameter, $this->property, $this->return] = array_map(
			fn(string $place) => !$values->isKept("types.declaration.$place"),
			['parameter', 'property', 'return'],
		);
		$this->traversableClasses = array_map(
			fn(string $name) => strtolower(ltrim($name, '\\')),
			$values->get('types.traversableClasses')->getNames(),
		);
	}


	public function getVisitedNodes(): array
	{
		return [
			...($this->parameter || $this->return ? [FunctionNode::class, MethodNode::class, Expression\ClosureNode::class] : []),
			...($this->property ? [PropertyNode::class] : []),
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof PropertyNode) {
			if ($this->property) {
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
		if (self::isInherited($node, $docComment, $context)) {
			return;
		}

		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$tree = $docComment ? $phpDoc->parse($docComment) : null;

		// both halves read one tree and hand back the tags to drop, so it is rewritten once
		$removed = [];
		if ($this->parameter && !$node instanceof Expression\ClosureNode) {
			$removed = $this->checkParameters($node, $tree, $context);
		}

		if ($this->return && ($tag = $this->checkReturn($node, $tree, $context))) {
			$removed[] = $tag;
		}

		if ($removed !== [] && $tree !== null) {
			self::removeTags($node, $tree, $removed, $docComment, $phpDoc);
		}
	}


	/**
	 * @return list<PhpDocTagNode>  the annotations that say nothing the native type does not
	 */
	private function checkParameters(FunctionNode|MethodNode $node, ?PhpDocNode $tree, RuleContext $context): array
	{
		[$tags, $prefixed] = self::findTags($tree, '@param');
		$types = $context->findAnalysis(Types::class);
		$params = $node->parameters->getItems();
		$overridden = $types !== null
			&& $node instanceof MethodNode
			&& !$node->isConstructor()
			&& array_any($params, fn(ParameterNode $param) => $param->type === null)
				? $types->findOverriddenSignature($node)
				: null;
		$variadic = $overridden === null ? null : array_find($overridden->parameters, fn(Parameter $parameter) => $parameter->variadic);
		$unseen = $types === null && $node instanceof MethodNode && !$node->isConstructor() && self::hasAncestors($node);
		$removed = [];
		foreach ($params as $i => $param) {
			$name = $param->variable->name;
			if (!$name instanceof Token) {
				continue;
			}

			$inherited = $overridden->parameters[$i] ?? $variadic;
			$removed[] = $this->checkDeclaration(
				'types.declaration.parameter',
				"Parameter `$name->text`",
				$param->variable,
				$param->type?->text,
				$tags[$name->text] ?? null,
				isset($prefixed[$name->text]),
				$node,
				fn(TypeNode $type) => $param->setType($type),
				$context,
				place: $param->promoted ? NativeType::Property : NativeType::Parameter,
				default: $param->default,
				isRefused: $param->promoted
					? fn() => $types === null
						? self::hasAncestors($param)
						: $types->findOverridden($param) !== null || $types->findTraitProperty($param) !== null
					: fn(string $native, \Closure $resolve) => $unseen || ($inherited !== null
						&& ($inherited->type === null || !NativeType::isDescribedAs($native, $inherited->type, $resolve))),
			);
		}

		return array_values(array_filter($removed));
	}


	private function checkReturn(
		FunctionNode|MethodNode|Expression\ClosureNode $node,
		?PhpDocNode $tree,
		RuleContext $context,
	): ?PhpDocTagNode
	{
		if (
			$node instanceof MethodNode
			&& ($node->isConstructor() || $node->isDestructor())
		) {
			return null;
		}

		[$tags, $prefixed] = self::findTags($tree, '@return');
		$tag = $tags[''] ?? null;
		$annotation = $tag?->value instanceof ReturnTagValueNode ? $tag->value->type : null;
		$native = $node->returnType?->text;
		$overridable = $node instanceof MethodNode && $node->isOverridable();

		if ($native === null && $annotation === null && $node->body !== null && !self::returnsValue($node->body)) {
			if (!$context->report(
				$node->closeParen,
				self::describeFunction($node) . ' must have the `void` return type.',
				risk: $overridable ? Risk::BehaviorChanges : null,
				because: $overridable ? 'a child returning a value then fails' : null,
				decision: 'types.declaration.return',
			)) {
				return null;
			}

			$node->setReturnType((new Builder)->type('void'));
			$native = 'void';

		} elseif ($native === null && $annotation === null && $node instanceof Expression\ClosureNode) {
			return null; // a closure has no doc comment to ask for

		} elseif (
			strtolower((string) $native) === 'void'
			&& $annotation instanceof IdentifierTypeNode
			&& strtolower($annotation->name) === 'never'
			&& version_compare($context->phpVersion, '8.1', '>=')
			&& $context->report($node->closeParen, 'The return type of ' . lcfirst(self::describeFunction($node)) . ' must be `never` instead of `void`, as the `@return` annotation says.', risk: Risk::BehaviorChanges, because: 'PHP then throws a `TypeError` where the function returns after all', decision: 'types.declaration.return')
		) {
			$node->setReturnType((new Builder)->type('never'));
			$native = 'never';
		}

		return $this->checkDeclaration(
			'types.declaration.return',
			self::describeFunction($node),
			$node->closeParen,
			$native,
			$tag,
			isset($prefixed['']),
			$node,
			fn(TypeNode $type) => $node->setReturnType($type),
			$context,
			place: NativeType::Return,
		);
	}


	/**
	 * Whether the class the declaration stands in has a parent, an interface or a trait, which may declare it without a
	 * type; only the types of the run tell whether one does.
	 */
	private static function hasAncestors(Node $node): bool
	{
		$class = $node->findAncestor(ClassLikeNode::class);
		return match (true) {
			$class instanceof ClassNode, $class instanceof AnonymousClassNode => $class->extends !== null || $class->implements !== null,
			$class instanceof InterfaceNode => $class->extends !== null,
			$class instanceof EnumNode => $class->implements !== null,
			default => false,
		} || ($class !== null && array_any($class->members->getItems(), fn(Node $member) => $member instanceof TraitUseNode));
	}


	/** `The method `run()``, `The function `run()`` or `The closure`, as a message names it. */
	private static function describeFunction(FunctionNode|MethodNode|Expression\ClosureNode $node): string
	{
		return match (true) {
			$node instanceof MethodNode => "The method `{$node->name->text}()`",
			$node instanceof FunctionNode => "The function `{$node->name->text}()`",
			default => 'The closure',
		};
	}


	private function checkProperty(PropertyNode $node, RuleContext $context): void
	{
		if (count($node->items) !== 1) {
			return;
		}

		$docComment = $node->getDocComment();
		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$tree = $docComment ? $phpDoc->parse($docComment) : null;
		[$tags, $prefixed] = self::findTags($tree, '@var');
		$item = $node->items->getItems()[0];
		$tag = $this->checkDeclaration(
			'types.declaration.property',
			"Property `{$item->name->text}`",
			$item,
			$node->type?->text,
			$tags[''] ?? null,
			isset($prefixed['']),
			$node,
			fn(TypeNode $type) => $node->setType($type),
			$context,
			place: NativeType::Property,
			default: $item->default,
			isRefused: fn() => ($types = $context->findAnalysis(Types::class)) === null
				? self::hasAncestors($node)
				: $types->findOverridden($node) !== null || $types->findTraitProperty($node) !== null,
		);
		if ($tag !== null && $tree !== null) {
			self::removeTags($node, $tree, [$tag], $docComment, $phpDoc);
		}
	}


	/**
	 * Checks one declaration against its annotation: one with neither is reported, a missing native type is written
	 * from the annotation, a traversable type asks for an annotation saying what its items are, and an annotation
	 * saying nothing more than the native type is returned to be dropped.
	 * @param  \Closure(TypeNode): mixed  $setType
	 * @param  ?\Closure(string, \Closure(string): string): bool  $isRefused  whether PHP refuses the native type, given with
	 *   the resolver of its classes, because of a declaration this one redeclares
	 */
	private function checkDeclaration(
		string $decision,
		string $subject,
		Node|Token $at,
		?string $native,
		?PhpDocTagNode $tag,
		bool $prefixed,
		Node $owner,
		\Closure $setType,
		RuleContext $context,
		string $place,
		?Node $default = null,
		?\Closure $isRefused = null,
	): ?PhpDocTagNode
	{
		$return = $decision === 'types.declaration.return';
		$typeWord = $return ? 'return type' : 'type';
		$tagName = self::Annotations[$decision];
		$value = $tag?->value;
		$annotation = $value instanceof ParamTagValueNode || $value instanceof ReturnTagValueNode || $value instanceof VarTagValueNode
			? $value->type
			: null;
		$resolver = $context->getAnalysis(NameResolver::class);
		$resolve = fn(string $class) => $resolver->resolveClass((new Builder)->name($class), $owner);

		if ($native === null) {
			if ($annotation === null) {
				if (!$prefixed) {
					$context->report($at, "$subject must have a native $typeWord or a `$tagName` annotation.", fixable: false, decision: $decision);
				}

				return null;
			}

			$localTypes = $context->getAnalysis(PhpDoc::class)->findLocalTypes($owner);
			$native = NativeType::fromAnnotation($annotation, $place, $context->phpVersion, $this->traversableClasses, $localTypes, $resolve);
			if (
				$native !== null
				&& $default instanceof Scalar\NullNode
				&& !str_starts_with($native, '?')
				&& !preg_match('~(^|\|)null$~i', $native)
				&& strtolower($native) !== 'mixed'
			) {
				$native = match (true) {
					!str_contains($native, '&') => str_contains($native, '|') ? "$native|null" : "?$native",
					version_compare($context->phpVersion, '8.2', '>=') => "($native)|null",
					default => null,
				};
			}

			if (
				$native === null
				|| ($default !== null && !self::canDefaultTo($native, $default))
				|| ($isRefused !== null && $isRefused($native, $resolve))
				|| !$context->report($at, "$subject must have the native $typeWord `$native` from its `$tagName` annotation.", risk: Risk::BehaviorChanges, because: 'a value the annotation did not foresee then throws a `TypeError`', decision: $decision)
			) {
				return null;
			}

			$setType((new Builder)->type($native));
		}

		$traversable = NativeType::isTraversable(ltrim($native, '?'), $this->traversableClasses, $resolve);
		if ($tag === null) {
			if ($traversable && !$prefixed) {
				$described = $return ? "returning `$native`" : "of the type `$native`";
				$context->report($at, "$subject $described must have a `$tagName` annotation saying what its items are.", fixable: false, decision: $decision);
			}

			return null;
		}

		$useless = match (true) {
			$value instanceof TypelessParamTagValueNode => $value->description === '',
			$value instanceof ParamTagValueNode, $value instanceof ReturnTagValueNode, $value instanceof VarTagValueNode => $value->description === ''
				&& NativeType::matches($value->type, $native)
				&& (!$traversable || NativeType::isPlainIterable($value->type)),
			default => false,
		};
		$described = $return ? '' : ' for ' . lcfirst($subject);
		return $useless && $context->report($at, "Useless `$tagName` annotation$described, because the native $typeWord says the same.", trivia: $owner->getDocComment(), decision: $decision)
			? $tag
			: null;
	}


	/** Whether PHP accepts the default for the native type; true for a value it learns only at run time, such as a constant. */
	private static function canDefaultTo(string $native, Node $default): bool
	{
		$accepting = match (true) {
			$default instanceof Scalar\BooleanNode => ['bool', $default->toValue() ? 'true' : 'false'],
			default => match (NativeType::fromValue($default)) {
				'string' => ['string'],
				'int' => ['int', 'float'],
				'float' => ['float'],
				'bool' => ['bool', 'true', 'false'],
				'array' => ['array', 'iterable'],
				default => null,
			},
		};
		return $accepting === null
			|| array_intersect(explode('|', strtolower(ltrim($native, '?'))), ['mixed', ...$accepting]) !== [];
	}


	/**
	 * The `@param`, `@return` or `@var` tags of the doc comment by the parameter they describe, `''` for the other
	 * two, and the parameters a prefixed tag such as `@phpstan-param` describes instead.
	 * @return array{array<string, PhpDocTagNode>, array<string, true>}
	 */
	private static function findTags(?PhpDocNode $tree, string $name): array
	{
		$tags = $prefixed = [];
		foreach ($tree->children ?? [] as $child) {
			$value = $child instanceof PhpDocTagNode ? $child->value : null;
			$kind = match (true) {
				$value instanceof ParamTagValueNode, $value instanceof TypelessParamTagValueNode => '@param',
				$value instanceof ReturnTagValueNode => '@return',
				$value instanceof VarTagValueNode => '@var',
				default => null,
			};
			if ($kind === $name) {
				$key = $value instanceof ParamTagValueNode || $value instanceof TypelessParamTagValueNode ? $value->parameterName : '';
				if (strtolower($child->name) === $name) {
					$tags[$key] = $child;
				} else {
					$prefixed[$key] = true;
				}
			}
		}

		return [$tags, $prefixed];
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
				($child instanceof ReturnNode && $child->expression !== null)
				|| $child instanceof Expression\YieldNode
				|| $child instanceof Expression\YieldFromNode
				|| self::returnsValue($child)
			) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Whether the declaration documents itself by `{@inheritDoc}` or the `#[\Override]` attribute, a bare `Override`
	 * in a namespace that declares no such class being the one of PHP left without an import.
	 */
	private static function isInherited(
		FunctionNode|MethodNode|Expression\ClosureNode $node,
		?Trivia $docComment,
		RuleContext $context,
	): bool
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		if (
			($docComment !== null && preg_match('~@inheritDoc~i', $docComment->text))
			|| $resolver->hasAttribute($node, \Override::class)
		) {
			return true;
		}

		$types = $context->findAnalysis(Types::class);
		foreach ($node->attributes->getItems() as $group) {
			foreach ($group->items->getItems() as $attribute) {
				if (
					$attribute->name->form === NameForm::Unqualified
					&& strcasecmp($attribute->name->text, 'Override') === 0
					&& $types?->findClassName($resolver->resolveClass($attribute->name)) === null
				) {
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
		$tree->children = array_values(array_filter($tree->children, fn($child) => !in_array($child, $tags, true)));
		$phpDoc->writeBack($tree, $docComment, $node);
	}
}
