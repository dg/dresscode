<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Types;

use DressCode\Analyses\{Parameter, PhpDoc, Types};
use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage, Values};
use DressCode\Rules\NativeType;
use PHPStan\PhpDocParser\Ast\PhpDoc\{
	ParamTagValueNode, PhpDocNode, PhpDocTagNode, ReturnTagValueNode, TypelessParamTagValueNode, VarTagValueNode,
};
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, NameForm, Node, Token, Trivia};
use PhpSyntax\Nodes\{AnonymousClassNode, ClassLikeNode, Expression, ParameterNode, Scalar, TypeNode};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyNode, TraitUseNode};
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode, FunctionNode, InterfaceNode, ReturnNode};
use function count;


/**
 * Parameters, properties and return values have native types: a missing one is added from the `@param`,
 * `@var` or `@return` annotation when PHP can express it, nullable where the declaration defaults to null; an
 * annotation then saying nothing more is `UselessTypeAnnotationsRule`'s. A property without a default, null until
 * written, gets `= null` where its type takes null. A variadic parameter whose annotation names an array and
 * a property with hooks get no native type from their annotation, being reported instead. A function or a closure that
 * returns no value is declared void, and a native void the annotation says is never becomes never. Any other declaration
 * with neither is reported, and with `phpdoc.annotation.itemTypes` an array, iterable or traversable one whose
 * annotation does not say what the items are. A function inheriting its documentation (`{@inheritDoc}`,
 * `#[\Override]`) is left alone. Each place can be turned off on its own; a class constant is
 * `ConstantTypeRequiredRule`'s.
 *
 * A native type written is risky: PHP enforces it, and an annotation that was wrong becomes a TypeError, or a property
 * left without `= null` is uninitialized until written. None is
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
	private const Annotations = [
		'types.declaration.parameter' => '@param',
		'types.declaration.return' => '@return',
		'types.declaration.property' => '@var',
	];
	private const ItemTypes = 'phpdoc.annotation.itemTypes';

	private bool $parameter = true;
	private bool $property = true;
	private bool $return = true;
	private bool $anonymousFunction = true;
	private bool $itemTypes = true;

	/** @var list<string> */
	private array $traversableClasses = [];


	public static function getDecisions(): array
	{
		return [
			new Decision(
				'types.declaration.parameter',
				Domain::state('required'),
				'A parameter of a function or a method declares its type, taken from `@param` where it has one and nullable where it defaults to null; one with neither, and one whose annotation PHP cannot write, is reported',
				['Not a parameter of a closure, whose types belong to the call it is passed to.'],
			),
			new Decision(
				'types.declaration.property',
				Domain::state('required'),
				'A property declares its type, a promoted one included, taken from `@var`, or the `@param` of a promoted one, where it has one and nullable where it defaults to null, `= null` written where an untyped one had no default',
			),
			new Decision(
				'types.declaration.return',
				Domain::state('required'),
				'A function or a method declares its return type, taken from `@return`, `void` where it returns no value and `never` where the annotation says so',
			),
			new Decision(
				'types.declaration.anonymousFunction',
				Domain::state('required'),
				'A closure declares its return type `void` where it returns no value',
				['Not an arrow function, whose value is its expression.'],
			),
			new Decision(
				self::ItemTypes,
				Domain::state('required'),
				'An array, iterable or traversable declaration has an annotation saying what its items are, reported',
			),
		];
	}


	public function configure(Values $values): void
	{
		[$this->parameter, $this->property, $this->return, $this->anonymousFunction] = array_map(
			fn(string $place) => !$values->isKept("types.declaration.$place"),
			['parameter', 'property', 'return', 'anonymousFunction'],
		);
		$this->itemTypes = !$values->isKept(self::ItemTypes);
		$this->traversableClasses = array_map(
			fn(string $name) => strtolower(ltrim($name, '\\')),
			$values->get('types.traversableClasses')->getNames(),
		);
	}


	public function getVisitedNodes(): array
	{
		$functions = $this->parameter || $this->return || $this->itemTypes;
		return [
			...($functions ? [FunctionNode::class] : []),
			...($this->anonymousFunction ? [Expression\ClosureNode::class] : []),
			...($functions || $this->property ? [MethodNode::class] : []),
			...($this->property || $this->itemTypes ? [PropertyNode::class] : []),
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof PropertyNode) {
			$this->checkProperty($node, $context);
			return;

		} elseif ($node instanceof Expression\ClosureNode) {
			$this->declareReturnType($node, null, $context);
			return;

		} elseif (!$node instanceof FunctionNode && !$node instanceof MethodNode) {
			return;
		}

		$docComment = $node->getDocComment();
		if (self::isInherited($node, $docComment, $context)) {
			return;
		}

		$tree = $docComment ? $context->getAnalysis(PhpDoc::class)->parse($docComment) : null;
		$this->declareParameterTypes($node, $tree, $context);
		$this->declareReturnType($node, $tree, $context);
	}


	private function declareParameterTypes(FunctionNode|MethodNode $node, ?PhpDocNode $tree, RuleContext $context): void
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
		$phpDoc = $context->getAnalysis(PhpDoc::class);
		foreach ($params as $i => $param) {
			$name = $param->variable->name;
			if (!$name instanceof Token) {
				continue;
			}

			// a promoted property may carry its `@var` itself
			$own = $param->promoted ? $param->getDocComment() : null;
			$ownTree = $own === null ? null : $phpDoc->parse($own);
			[$ownTags, $ownPrefixed] = self::findTags($ownTree, '@var');
			$fromOwn = !isset($tags[$name->text]) && isset($ownTags['']);
			$inherited = $overridden->parameters[$i] ?? $variadic;
			$this->declareType(
				$param->promoted ? 'types.declaration.property' : 'types.declaration.parameter',
				"The parameter `$name->text`",
				$param->variable,
				$param->type?->text,
				$fromOwn ? $ownTags[''] : $tags[$name->text] ?? null,
				isset($prefixed[$name->text]) || isset($ownPrefixed['']),
				$node,
				fn(TypeNode $type) => $param->setType($type),
				$context,
				place: $param->promoted ? NativeType::Property : NativeType::Parameter,
				default: $param->default,
				isRefused: $param->promoted
					? fn() => self::mayRedeclare($param, $types)
					: fn(string $native, \Closure $resolve) => $unseen || ($inherited !== null
						&& ($inherited->type === null || !NativeType::isDescribedAs($native, $inherited->type, $resolve))),
				variadic: $param->ellipsis !== null,
				hooked: $param->hooks !== null,
			);
		}
	}


	private function declareReturnType(FunctionNode|MethodNode|Expression\ClosureNode $node, ?PhpDocNode $tree, RuleContext $context): void
	{
		if (
			$node instanceof MethodNode
			&& ($node->isConstructor() || $node->isDestructor())
		) {
			return;
		}

		$decision = $node instanceof Expression\ClosureNode ? 'types.declaration.anonymousFunction' : 'types.declaration.return';
		$declares = $node instanceof Expression\ClosureNode ? $this->anonymousFunction : $this->return;

		[$tags, $prefixed] = self::findTags($tree, '@return');
		$tag = $tags[''] ?? null;
		$annotation = $tag?->value instanceof ReturnTagValueNode ? $tag->value->type : null;
		$native = $node->returnType?->text;
		$overridable = $node instanceof MethodNode && $node->isOverridable();

		if ($declares && $native === null && $annotation === null && $node->body !== null && !self::returnsValue($node->body)) {
			if (!$context->report(
				$node->closeParen,
				self::describeFunction($node) . ' must have the `void` return type.',
				risk: $overridable ? Risk::BehaviorChanges : null,
				because: $overridable ? 'a child returning a value then fails' : null,
				decision: $decision,
			)) {
				return;
			}

			$node->setReturnType((new Builder)->type('void'));
			$native = 'void';

		} elseif ($node instanceof Expression\ClosureNode) {
			return; // a closure has no doc comment to ask for or to read

		} elseif (
			$declares
			&& strtolower((string) $native) === 'void'
			&& $annotation instanceof IdentifierTypeNode
			&& strtolower($annotation->name) === 'never'
			&& version_compare($context->phpVersion, '8.1', '>=')
			&& $context->report(
				$node->closeParen,
				'The return type of '
				. lcfirst(self::describeFunction($node))
				. ' must be `never` instead of `void`, as the `@return` annotation says.',
				risk: Risk::BehaviorChanges,
				because: 'PHP then throws a `TypeError` where the function returns after all',
				decision: 'types.declaration.return',
			)
		) {
			$node->setReturnType((new Builder)->type('never'));
			$native = 'never';
		}

		$this->declareType(
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


	/**
	 * Whether the property may redeclare one of a parent, an interface or a trait, which PHP holds its type to; without the
	 * types, any ancestor may.
	 */
	private static function mayRedeclare(ParameterNode|PropertyNode $node, ?Types $types): bool
	{
		return $types === null
			? self::hasAncestors($node)
			: $types->findOverridden($node) !== null || $types->findTraitProperty($node) !== null;
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
		$tree = $docComment ? $context->getAnalysis(PhpDoc::class)->parse($docComment) : null;
		[$tags, $prefixed] = self::findTags($tree, '@var');
		$item = $node->items->getItems()[0];
		$this->declareType(
			'types.declaration.property',
			"The property `{$item->name->text}`",
			$item,
			$node->type?->text,
			$tags[''] ?? null,
			isset($prefixed['']),
			$node,
			fn(TypeNode $type) => $node->setType($type),
			$context,
			place: NativeType::Property,
			default: $item->default,
			isRefused: fn() => self::mayRedeclare($node, $context->findAnalysis(Types::class)),
			implicitNull: $item->default === null,
			writeNull: function () use ($item): void {
				$written = (new Builder)->fragment(PropertyNode::class, 'public $x = null;')->items->getItems()[0];
				$item->name->setTrailingSpace(' ');
				$item->equals = $written->equals;
				$item->default = $written->default;
			},
			hooked: $node->hooks !== null,
		);
	}


	/**
	 * Declares the native type of one declaration from its annotation, where its decision asks for it: one with neither
	 * is reported, a missing native type is written from the annotation, and a traversable type asks for an annotation
	 * saying what its items are where `phpdoc.annotation.itemTypes` does.
	 * @param  \Closure(TypeNode): mixed  $setType
	 * @param  ?\Closure(string, \Closure(string): string): bool  $isRefused  whether PHP refuses the native type, given with
	 *   the resolver of its classes, because of a declaration this one redeclares
	 * @param  bool  $implicitNull  the declaration is null until written, which a native type leaves uninitialized
	 * @param  ?\Closure(): void  $writeNull  writes `= null` to keep it null under a type that takes it
	 */
	private function declareType(
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
		bool $implicitNull = false,
		?\Closure $writeNull = null,
		bool $variadic = false,
		bool $hooked = false,
	): void
	{
		$declares = match ($decision) {
			'types.declaration.parameter' => $this->parameter,
			'types.declaration.property' => $this->property,
			default => $this->return,
		};
		$return = $decision === 'types.declaration.return';
		$typeWord = $return ? 'return type' : 'type';
		$tagName = $tag === null ? self::Annotations[$decision] : strtolower($tag->name);
		$value = $tag?->value;
		$annotation = $value instanceof ParamTagValueNode || $value instanceof ReturnTagValueNode || $value instanceof VarTagValueNode
			? $value->type
			: null;
		$resolver = $context->getAnalysis(NameResolver::class);
		$resolve = fn(string $class) => $resolver->resolveClass((new Builder)->name($class), $owner);

		if ($native === null) {
			if (!$declares) {
				return;
			} elseif ($annotation === null) {
				if (!$prefixed) {
					$context->report(
						$at,
						"$subject must have a native $typeWord or a `$tagName` annotation.",
						fixable: false,
						decision: $decision,
					);
				}

				return;
			}

			$localTypes = $context->getAnalysis(PhpDoc::class)->findLocalTypes($owner);
			$native = NativeType::fromAnnotation(
				$annotation,
				$place,
				$context->phpVersion,
				$this->traversableClasses,
				$localTypes,
				$resolve,
			);
			if ($native !== null && $default instanceof Scalar\NullNode && !self::takesNull($native)) {
				$native = match (true) {
					!str_contains($native, '&') => str_contains($native, '|') ? "$native|null" : "?$native",
					version_compare($context->phpVersion, '8.2', '>=') => "($native)|null",
					default => null,
				};
			}

			// the array an annotation of a variadic parameter names is read as that of all the arguments and of each one,
			// and a property with hooks may have no value to be null, so neither is written but reported
			$unwritable = match (true) {
				$native === null => null,
				$hooked => 'a property with hooks',
				$variadic && NativeType::isTraversable(ltrim($native, '?'), $this->traversableClasses, $resolve) => 'a variadic parameter',
				default => null,
			};
			if ($unwritable !== null) {
				$context->report(
					$at,
					"$subject must have a native $typeWord, which is not written from the `$tagName` annotation of $unwritable.",
					fixable: false,
					decision: $decision,
				);
				return;
			}

			$keptNull = $implicitNull && $writeNull !== null && $native !== null && self::takesNull($native);
			if (
				$native === null
				|| ($default !== null && !self::canDefaultTo($native, $default))
				|| ($isRefused !== null && $isRefused($native, $resolve))
				|| !$context->report(
					$at,
					"$subject must have the native $typeWord `$native` from its `$tagName` annotation.",
					risk: Risk::BehaviorChanges,
					because: $implicitNull && !$keptNull
						? 'the property is then uninitialized until written, where it was null'
						: 'a value the annotation did not foresee then throws a `TypeError`',
					decision: $decision,
				)
			) {
				return;
			}

			$setType((new Builder)->type($native));
			if ($keptNull) {
				$writeNull();
			}
		}

		if (
			$this->itemTypes
			&& !$prefixed
			&& ($annotation === null || NativeType::isPlainIterable($annotation))
			&& NativeType::isTraversable(ltrim($native, '?'), $this->traversableClasses, $resolve)
		) {
			$described = $return ? "returning `$native`" : "of the type `$native`";
			$context->report(
				$at,
				"$subject $described must have a `$tagName` annotation saying what its items are.",
				fixable: false,
				decision: self::ItemTypes,
			);
		}
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


	private static function takesNull(string $native): bool
	{
		return str_starts_with($native, '?') || array_intersect(explode('|', strtolower($native)), ['null', 'mixed']) !== [];
	}


	/**
	 * The `@param`, `@return` or `@var` tags of the doc comment by the parameter they describe, `''` for the other
	 * two, and the parameters a prefixed tag such as `@phpstan-param` describes instead or two tags do, giving no type.
	 * @return array{array<string, PhpDocTagNode>, array<string, true>}
	 */
	private static function findTags(?PhpDocNode $tree, string $name): array
	{
		$tags = $prefixed = $doubled = [];
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
				if (strtolower($child->name) !== $name) {
					$prefixed[$key] = true;
				} elseif (isset($tags[$key]) || isset($doubled[$key])) {
					unset($tags[$key]);
					$doubled[$key] = $prefixed[$key] = true;
				} else {
					$tags[$key] = $child;
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
}
