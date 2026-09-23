<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;

use DressCode\Rules\QualifiedNames;
use DressCode\Tristate;
use PhpParser\Node as ParserNode;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Node\{InstantiationCallableNode, MethodCallableNode, StaticMethodCallableNode};
use PHPStan\Php\PhpVersion;
use PHPStan\Reflection\{ClassConstantReflection, ClassMemberReflection, ClassReflection, ExtendedMethodReflection, ExtendedParameterReflection, ExtendedPropertyReflection};
use PHPStan\Reflection\Php\{PhpMethodReflection, PhpPropertyReflection};
use PHPStan\TrinaryLogic;
use PHPStan\Type\Constant\{ConstantIntegerType, ConstantStringType};
use PHPStan\Type\{MixedType, Type, TypeCombinator, VerbosityLevel};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Printer, Token, Visibility};
use PhpSyntax\Nodes\{ClassLikeNode, ExpressionNode, FileNode, IdentifierNode, MemberNode, NameNode, ParameterNode};
use PhpSyntax\Nodes\Expression\{ClassConstantFetchNode, MethodCallNode, NewNode, ParenthesizedNode, PropertyFetchNode, StaticMethodCallNode, StaticPropertyFetchNode};
use PhpSyntax\Nodes\Member\{ClassConstNode, EnumCaseNode, MethodNode, PropertyNode};
use function count, is_bool, strlen;


/**
 * The types of the code, from the PHPStan of the project: what an expression is, which member a call or an
 * access reaches, and what the declaration of that member says about it. The answers are those of the text of the
 * pass at its first question; a node inserted after it has none, and a rule asking for one asks with `?->`.
 * A rule asks questions; the answers in the words of PHPStan stay inside.
 */
final class Types implements PassAnalysis
{
	/** @var \SplObjectStorage<ExpressionNode, array{Expr, Scope}> */
	private \SplObjectStorage $expressions;

	/** @var \SplObjectStorage<MethodNode, array{ParserNode\Stmt\ClassMethod, Scope}> */
	private \SplObjectStorage $declarations;

	/** @var ?\Closure(): array<ParserNode\Stmt>  the text parsed by PHPStan once asked, until the scopes are resolved from it */
	private ?\Closure $parse;

	/** @var array<string, list<ExpressionNode|MethodNode>>  "start:end" => the nodes the text stood at when the analysis was made, the innermost first */
	private array $spans = [];

	/** @var \SplObjectStorage<MemberNode, null>  the member declarations the analysis was made with */
	private \SplObjectStorage $members;

	private ?NameResolver $names = null;

	/** @var array<string, Tristate>  lowercased "class ancestor" => whether the one is the other's subtype */
	private array $subtypes = [];

	/** @var array<string, bool>  lowercased class => whether something declares every ancestor it names */
	private array $seenWhole = [];

	private readonly PhpStan $phpstan;


	/**
	 * The scopes wait for the first question that needs them, most of a file asking about classes alone; the nodes are
	 * found by where their text stood now, so that what the pass changes meanwhile moves nothing.
	 * @internal
	 */
	public function __construct(
		private readonly FileNode $file,
		private readonly string $path,
		PhpStan $phpstan,
	) {
		$this->expressions = new \SplObjectStorage;
		$this->declarations = new \SplObjectStorage;
		$this->members = new \SplObjectStorage;
		$code = Printer::print($file);
		$ast = null;
		$this->parse = function () use ($phpstan, $code, &$ast): array {
			return $ast ??= $phpstan->parse($code);
		};
		$this->phpstan = $phpstan->deriveFor($path, $code, $this->parse);
		$this->collectSpans($file);
	}


	/**
	 * Records where the text of the node and of everything under it stands, as `FileNode::findNode()` does.
	 * @return ?array{int, int}  its offsets, the end exclusive; null for a node without tokens
	 */
	private function collectSpans(Node $node): ?array
	{
		$start = $end = null;
		foreach ($node->getChildren() as $child) {
			if ($child instanceof Token) {
				$offset = $child->getCurrentOffset();
				$range = [$offset, $offset + strlen($child->text)];
			} else {
				$range = $this->collectSpans($child);
			}

			if ($range !== null) {
				$start ??= $range[0];
				$end = $range[1];
			}
		}

		if ($start === null || $end === null) {
			return null;
		} elseif ($node instanceof ExpressionNode || $node instanceof MethodNode) {
			$this->spans["$start:$end"][] = $node;
		}

		if ($node instanceof MemberNode) {
			$this->members[$node] = null;
		}

		return [$start, $end];
	}


	private function resolveScopes(): void
	{
		if ($this->parse === null) {
			return;
		}

		$ast = ($this->parse)();
		$this->parse = null;
		$circular = []; // class => whether its ancestors run in a circle, which leaves the nodes inside it without types
		$this->phpstan->resolveScopes($this->path, $ast, function (ParserNode $node, Scope $scope) use (&$circular): void {
			$class = $scope->getClassReflection();
			if ($class !== null && ($circular[$class->getName()] ??= PhpStan::hasCircularAncestors($class))) {
				return;
			} elseif ($node instanceof Expr) {
				$expression = $this->findSpan($node, ExpressionNode::class);
				if ($expression !== null && !isset($this->expressions[$expression])) {
					$this->expressions[$expression] = [$node, $scope];
				}
			} elseif ($node instanceof ParserNode\Stmt\ClassMethod) {
				$method = $this->findSpan($node, MethodNode::class);
				if ($method !== null && !isset($this->declarations[$method])) {
					$this->declarations[$method] = [$node, $scope];
				}
			}
		});
	}


	/**
	 * The outermost node of the class the text of the node of PHPStan stood at.
	 * @template T of ExpressionNode|MethodNode
	 * @param  class-string<T>  $class
	 * @return ?T
	 */
	private function findSpan(ParserNode $node, string $class): ?Node
	{
		$nodes = $this->spans[$node->getStartFilePos() . ':' . ($node->getEndFilePos() + 1)] ?? [];
		for ($i = count($nodes) - 1; $i >= 0; $i--) {
			if ($nodes[$i] instanceof $class) {
				return $nodes[$i];
			}
		}

		return null;
	}


	/**
	 * The parentheses around an expression are not a node of PHPStan, so they are answered by the expression inside.
	 * @return ?array{Expr, Scope}
	 */
	private function findExpression(ExpressionNode $node): ?array
	{
		$this->resolveScopes();
		while ($node instanceof ParenthesizedNode) {
			$node = $node->expression;
		}

		return $this->expressions[$node] ?? null;
	}


	/** @return ?array{ParserNode\Stmt\ClassMethod, Scope} */
	private function findDeclaration(MethodNode $node): ?array
	{
		$this->resolveScopes();
		return $this->declarations[$node] ?? null;
	}


	/**
	 * The type of the expression as PHPStan sees it, with `$at` as it sees it where that node stands; null for an
	 * expression the analysis was made without.
	 */
	private function getType(ExpressionNode $expression, ?ExpressionNode $at = null): ?Type
	{
		$found = $this->findExpression($expression);
		$scope = $at === null ? $found[1] ?? null : $this->findExpression($at)[1] ?? null;
		return $found === null || $scope === null ? null : $scope->getType($found[0]);
	}


	/**
	 * Whether the expression is of the type, written in the syntax of PHPDoc as PHPStan reads it (`int|string`,
	 * `list<int>`, `non-empty-string`), its classes fully qualified; maybe where it may or may not be, and for an
	 * expression the analysis was made without. With `$at` the expression is asked about where that node stands, which
	 * a part of the variable of `isset()` needs: PHPStan takes it there as not null, since `isset()` would not fail on it.
	 */
	public function isOfType(ExpressionNode $expression, string $type, ?ExpressionNode $at = null): Tristate
	{
		$actual = $this->getType($expression, $at);
		if ($actual === null) {
			return Tristate::Maybe;
		}

		$answer = $this->phpstan->resolveType($type)->isSuperTypeOf($actual);
		return match (true) {
			$answer->yes() => Tristate::Yes,
			$answer->no() => Tristate::No,
			default => Tristate::Maybe,
		};
	}


	/**
	 * Whether the loose and the strict comparison of the first expression with each of the others answer alike: yes
	 * where they are all integers, all booleans or all enum cases; no where the first and another are scalars or null
	 * of types with no value in common, which `===` never finds equal while `==` may (`1 == '1'`); maybe otherwise,
	 * two strings among it, `'1' == '01'` comparing them as numbers, and for an expression the analysis was made
	 * without.
	 * @param list<ExpressionNode> $expressions
	 */
	public function isComparedAlike(array $expressions): Tristate
	{
		if (array_any(
			['int', 'bool', \UnitEnum::class],
			fn(string $type) => array_all($expressions, fn(ExpressionNode $expression) => $this->isOfType($expression, $type) === Tristate::Yes),
		)) {
			return Tristate::Yes;
		}

		$first = $expressions === [] ? null : $this->getType($expressions[0]);
		if ($first === null || !$first->isScalar()->or($first->isNull())->yes()) {
			return Tristate::Maybe;
		}

		foreach (array_slice($expressions, 1) as $expression) {
			$type = $this->getType($expression);
			if (
				$type !== null
				&& $type->isScalar()->or($type->isNull())->yes()
				&& $first->isSuperTypeOf($type)->no()
				&& !$first->looseCompare($type, new PhpVersion(80000))->isFalse()->yes()
			) {
				return Tristate::No;
			}
		}

		return Tristate::Maybe;
	}


	/**
	 * The classes the expression is an instance of, fully qualified; none for anything that is no object.
	 * @return list<string>
	 */
	public function findClasses(ExpressionNode $expression): array
	{
		return $this->getType($expression)?->getObjectClassNames() ?? [];
	}


	/**
	 * The member the node reaches: the constant of a class constant access, the method of a call, the property of
	 * an access, the constructor of an instantiation, decided by the class that declares it and of the kind it is
	 * declared (`parent::recalc()` reaches a method, `$order->make()` a static one, `parent::__construct()` the
	 * constructor). Null for a node that reaches no known member, or whose name is an expression.
	 */
	public function findMember(ExpressionNode $node): ?Member
	{
		$receiver = $this->findReceiver($node);
		if ($receiver === null) {
			return null;
		}

		[$kind, $type, $name, $scope] = $receiver;
		$reflection = match ($kind) {
			MemberKind::Constant => $scope->getConstantReflection($type, $name),
			MemberKind::Method, MemberKind::StaticMethod, MemberKind::Constructor => $scope->getMethodReflection($type, $name),
			MemberKind::Property => $scope->getInstancePropertyReflection($type, $name),
			MemberKind::StaticProperty => $scope->getStaticPropertyReflection($type, $name),
		};
		if ($reflection === null) {
			return null;
		} elseif ($kind === MemberKind::Method || $kind === MemberKind::StaticMethod) {
			$kind = match (true) {
				strcasecmp($name, '__construct') === 0 => MemberKind::Constructor,
				$reflection->isStatic() => MemberKind::StaticMethod,
				default => MemberKind::Method,
			};
		}

		// a property reflection does not say its name, which the declaration cannot spell differently anyway
		return new Member($kind, $reflection instanceof ExtendedPropertyReflection ? $name : $reflection->getName(), $reflection->getDeclaringClass()->getName());
	}


	/**
	 * The member access the node makes, decided by the type of its receiver and not by the class that declares the
	 * member: a class constant access, a method call, a property access, or an instantiation of a named class. There
	 * is one for a member no class declares any more, and where a child overrides the member the classes are those of
	 * the receiver. Null for a name that is an expression and for a receiver that is no object.
	 */
	public function findMemberAccess(ExpressionNode $node): ?MemberAccess
	{
		$receiver = $this->findReceiver($node);
		if ($receiver === null) {
			return null;
		}

		[$kind, $type, $name] = $receiver;
		$classes = $type->getObjectClassNames();
		return new MemberAccess($kind, $name, $classes, array_any($classes, fn(string $class) => $this->hasMember($class, $kind, $name)));
	}


	/**
	 * The instantiation, or a call of `parent::__construct()`, as the access of the constructor it runs, whose class is
	 * the one that declares it: `new Child` of a child that declares none runs the constructor of its parent, a child
	 * that declares one its own. Null for any other node.
	 */
	public function findConstructorAccess(ExpressionNode $node): ?MemberAccess
	{
		$access = $node instanceof NewNode || ($node instanceof StaticMethodCallNode && $node->name instanceof IdentifierNode && $node->name->equals('__construct'))
			? $this->findMemberAccess($node)
			: null;
		if ($access === null) {
			return null;
		}

		$declaring = $this->findMember($node)?->declaringClass;
		return new MemberAccess(MemberKind::Constructor, '__construct', $declaring === null ? $access->classes : [$declaring], $access->declared);
	}


	/**
	 * The method a value in the shape of a callable names, `[$object, 'name']`, `[Order::class, 'name']` or
	 * `'Acme\Order::name'`, as an access of the class of the object or of the one named. Whether a class declares the
	 * method is not asked, a method the library removed being named as well as any. Null for a value of another shape.
	 */
	public function findCallableMethodAccess(ExpressionNode $callable): ?MemberAccess
	{
		$type = $this->getType($callable);
		$strings = $type?->getConstantStrings() ?? [];
		$arrays = $type?->getConstantArrays() ?? [];
		$pair = count($arrays) === 1 && array_map(fn(ConstantIntegerType|ConstantStringType $key) => $key->getValue(), $arrays[0]->getKeyTypes()) === [0, 1]
			? $arrays[0]->getValueTypes()
			: null;
		$names = $pair === null ? [] : $pair[1]->getConstantStrings();
		if (count($strings) === 1 && str_contains($strings[0]->getValue(), '::')) {
			[$class, $name] = explode('::', $strings[0]->getValue(), 2);
			$classes = [ltrim($class, '\\')];
			$named = true;
		} elseif ($pair !== null && count($names) === 1) {
			$name = $names[0]->getValue();
			$classes = $pair[0]->getObjectClassNames();
			$named = $classes === [];
			$classes = $named ? array_map(fn(ConstantStringType $class) => ltrim($class->getValue(), '\\'), $pair[0]->getConstantStrings()) : $classes;
		} else {
			return null;
		}

		if ($classes === [] || !preg_match('~^[a-z_\x80-\xff][\w\x80-\xff]*$~Di', $name)) {
			return null;
		}

		// a class named by its name calls a method that is not static too, where a container makes the object
		$kind = $named && array_all($classes, fn(string $class) => $this->isStaticMethod($class, $name) === Tristate::Yes)
			? MemberKind::StaticMethod
			: MemberKind::Method;
		return new MemberAccess($kind, $name, $classes, array_any($classes, fn(string $class) => $this->hasMember($class, $kind, $name)));
	}


	/**
	 * The parameters of the method the access calls, as the class of the receiver declares them; null for an access
	 * that is no call, for a method no class of the receiver declares, and where two of them declare it differently.
	 * @return ?list<Parameter>
	 */
	public function findParameters(MemberAccess $access): ?array
	{
		if (!in_array($access->kind, [MemberKind::Method, MemberKind::StaticMethod, MemberKind::Constructor], true)) {
			return null;
		}

		$found = null;
		foreach ($access->classes as $class) {
			if (!$this->hasMember($class, $access->kind, $access->name)) {
				continue;
			}

			// the default does not decide how an argument binds
			$parameters = $this->findMethodParameters($class, $access->name);
			$describe = fn(Parameter $parameter) => [$parameter->name, $parameter->type, $parameter->optional, $parameter->variadic, $parameter->byReference];
			if (
				$parameters === null
				|| ($found !== null && array_map($describe, $found) !== array_map($describe, $parameters))
			) {
				return null;
			}

			$found = $parameters;
		}

		return $found;
	}


	/**
	 * The parameters of the method as the class declares it, itself or through an ancestor, the constructor among them;
	 * null for a method the class does not have, for a class nothing declares and for a method of several variants.
	 * @return ?list<Parameter>
	 */
	public function findMethodParameters(string $class, string $method): ?array
	{
		$reflection = $this->phpstan->findClass($class);
		$variants = $reflection !== null && $reflection->hasNativeMethod($method) ? $reflection->getNativeMethod($method)->getVariants() : [];
		return count($variants) === 1 ? array_map(self::toParameter(...), $variants[0]->getParameters()) : null;
	}


	private static function toParameter(ExtendedParameterReflection $parameter): Parameter
	{
		return new Parameter(
			$parameter->getName(),
			self::writeNativeType($parameter->getNativeType()),
			$parameter->isOptional(),
			$parameter->isVariadic(),
			$parameter->passedByReference()->yes(),
			self::writeValue($parameter->getDefaultValue()),
		);
	}


	/** The class the method declaration belongs to; null for a declaration the analysis was made without. */
	public function findDeclaringClass(MethodNode $declaration): ?string
	{
		return $this->findDeclaredClass($declaration)?->getName();
	}


	/**
	 * The member of the same name a parent class or an interface declares, which the declaration overrides: a method
	 * by a method, a constant by a constant or an enum case, a property by a property or a promoted parameter; a
	 * private member of an ancestor is hidden, not overridden, and the next ancestor is asked. Null where the
	 * declaration overrides nothing, for one of several constants or properties, for a constant, an enum case or a
	 * property declared outside the constructor of an anonymous class, and for a declaration the analysis was made
	 * without.
	 */
	public function findOverridden(MethodNode|ClassConstNode|EnumCaseNode|PropertyNode|ParameterNode $declaration): ?Member
	{
		[$kind, $name] = match (true) {
			$declaration instanceof MethodNode => [MemberKind::Method, $declaration->name->text],
			$declaration instanceof EnumCaseNode => [MemberKind::Constant, $declaration->name->text],
			$declaration instanceof ClassConstNode => [MemberKind::Constant, $declaration->items->count() === 1 ? $declaration->items->getItems()[0]->name->text : null],
			$declaration instanceof PropertyNode => [MemberKind::Property, $declaration->items->count() === 1 ? $declaration->items->getItems()[0]->plainName : null],
			default => [MemberKind::Property, $declaration->promoted ? $declaration->variable->plainName : null],
		};
		$class = $name === null ? null : $this->findDeclaredClass($declaration);
		$member = $class === null ? null : self::findOverriddenMember($class, $kind, $name);
		return match (true) {
			$member instanceof ExtendedMethodReflection => new Member($member->isStatic() ? MemberKind::StaticMethod : MemberKind::Method, $member->getName(), $member->getDeclaringClass()->getName()),
			$member instanceof PhpPropertyReflection => new Member($member->isStatic() ? MemberKind::StaticProperty : MemberKind::Property, $member->getName(), $member->getDeclaringClass()->getName()),
			$member !== null => new Member(MemberKind::Constant, $member->getName(), $member->getDeclaringClass()->getName()),
			default => null,
		};
	}


	/**
	 * The property of the same name a trait the class uses declares, which PHP composes with the declaration, a
	 * property or a promoted parameter. Null where no trait declares one, for one of several properties, and for a
	 * declaration the analysis was made without.
	 */
	public function findTraitProperty(PropertyNode|ParameterNode $declaration): ?Member
	{
		$name = match (true) {
			$declaration instanceof PropertyNode => $declaration->items->count() === 1 ? $declaration->items->getItems()[0]->plainName : null,
			default => $declaration->promoted ? $declaration->variable->plainName : null,
		};
		$class = $name === null ? null : $this->findDeclaredClass($declaration);
		if ($name === null || $class === null) {
			return null;
		}

		foreach ($class->getTraits() as $trait) {
			if ($trait->hasNativeProperty($name)) {
				$property = $trait->getNativeProperty($name);
				return new Member($property->isStatic() ? MemberKind::StaticProperty : MemberKind::Property, $property->getName(), $trait->getName());
			}
		}

		return null;
	}


	/**
	 * Whether the parent class declares the method the way the declaration does, so that a caller sees no difference
	 * between the two: the same visibility and staticness, the same parameters by name, order, default, reference,
	 * variadic and native type, and the same native return type. False for a declaration the analysis was made without,
	 * for one no parent class declares, and for one whose parent declaration is private or abstract.
	 */
	public function matchesParentSignature(MethodNode $declaration): bool
	{
		$class = $this->findDeclaredClass($declaration);
		if ($class === null) {
			return false;
		}

		$name = $declaration->name->text;
		$parent = $class->getParentClass();
		if ($parent === null || !$class->hasNativeMethod($name) || !$parent->hasNativeMethod($name)) {
			return false;
		}

		$own = $class->getNativeMethod($name);
		$inherited = $parent->getNativeMethod($name);
		$abstract = $inherited->isAbstract();
		if (
			$inherited->isPrivate()
			|| ($abstract instanceof TrinaryLogic ? !$abstract->no() : $abstract)
			|| $own->isPublic() !== $inherited->isPublic()
			|| $own->isPrivate() !== $inherited->isPrivate()
			|| $own->isStatic() !== $inherited->isStatic()
			|| count($own->getVariants()) !== 1
			|| count($inherited->getVariants()) !== 1
		) {
			return false;
		}

		$ownVariant = $own->getOnlyVariant();
		$inheritedVariant = $inherited->getOnlyVariant();
		$ownParameters = $ownVariant->getParameters();
		$inheritedParameters = $inheritedVariant->getParameters();
		if (
			count($ownParameters) !== count($inheritedParameters)
			|| !$ownVariant->getNativeReturnType()->equals($inheritedVariant->getNativeReturnType())
		) {
			return false;
		}

		foreach ($ownParameters as $i => $parameter) {
			$other = $inheritedParameters[$i];
			$default = $parameter->getDefaultValue();
			$otherDefault = $other->getDefaultValue();
			if (
				$parameter->getName() !== $other->getName()
				|| $parameter->isOptional() !== $other->isOptional()
				|| $parameter->isVariadic() !== $other->isVariadic()
				|| !$parameter->passedByReference()->equals($other->passedByReference())
				|| !$parameter->getNativeType()->equals($other->getNativeType())
				|| ($default === null) !== ($otherDefault === null)
				|| ($default !== null && $otherDefault !== null && !$default->equals($otherDefault))
			) {
				return false;
			}
		}

		return true;
	}


	/**
	 * The method the declaration overrides as the parent class or an interface declares it natively, the first of
	 * them that declares it and not privately, with where the declaration departs from it; null where it overrides
	 * nothing, either has more than one variant, or the analysis was made without the declaration.
	 */
	public function findOverriddenSignature(MethodNode $declaration): ?OverriddenSignature
	{
		$class = $this->findDeclaredClass($declaration);
		if ($class === null) {
			return null;
		}

		$name = $declaration->name->text;
		$inherited = $class->hasNativeMethod($name) ? self::findOverriddenMember($class, MemberKind::Method, $name) : null;
		if (!$inherited instanceof ExtendedMethodReflection || count($inherited->getVariants()) !== 1) {
			return null;
		}

		$found = $this->findDeclaration($declaration); // the scopes are asked only for a declaration that overrides something
		if ($found === null) {
			return null;
		}

		// the declaration is read from the text of the pass, the reflection of its class is the file on the disk
		[$method, $scope] = $found;
		$ownReturnType = $scope->getFunctionType($method->returnType, false, false);
		$ownTypes = array_map(fn(ParserNode\Param $param) => $scope->getFunctionType(
			$param->type,
			$param->default instanceof Expr\ConstFetch && $param->default->name->toLowerString() === 'null',
			false,
		), $method->params);
		$variant = $inherited->getOnlyVariant();
		$returnType = $variant->getNativeReturnType();
		return new OverriddenSignature(
			$inherited->getDeclaringClass()->getName(),
			$inherited->isFinal()->yes(),
			$inherited->isStatic(),
			$inherited->isPublic() ? Visibility::Public : Visibility::Protected,
			self::writeNativeType($returnType),
			self::writeNativeType($returnType) !== null
			&& (self::writeNativeType($ownReturnType) === null || !$returnType->isSuperTypeOf($ownReturnType)->yes()),
			array_map(self::toParameter(...), $variant->getParameters()),
			array_keys(array_filter(
				$variant->getParameters(),
				fn(ExtendedParameterReflection $parameter, int $i) => isset($ownTypes[$i]) && !$ownTypes[$i]->isSuperTypeOf($parameter->getNativeType())->yes(),
				ARRAY_FILTER_USE_BOTH,
			)),
		);
	}


	/**
	 * The class the member declaration, or the constructor of the promoted parameter, belongs to, found by its name,
	 * which needs no scopes; only an anonymous class has none, and is asked of them for a method. Null for a
	 * declaration the analysis was made without.
	 */
	private function findDeclaredClass(MemberNode|ParameterNode $declaration): ?ClassReflection
	{
		$member = $declaration instanceof ParameterNode ? $declaration->findAncestor(MethodNode::class) : $declaration;
		if ($member === null || !isset($this->members[$member])) {
			return null;
		}

		$owner = $member->findAncestor(ClassLikeNode::class);
		$className = $owner === null ? null : ($this->names ??= new NameResolver($this->file))->getDeclaredName($owner);
		return match (true) {
			$className !== null => $this->phpstan->findClass($className),
			$member instanceof MethodNode => ($this->findDeclaration($member)[1] ?? null)?->getClassReflection(),
			default => null,
		};
	}


	/**
	 * The member of the kind and name the parent class or the first interface declares natively and not privately,
	 * which a member of the class overrides; a private member of an ancestor is hidden, not overridden, and a member
	 * only an annotation declares is none PHP overrides.
	 */
	private static function findOverriddenMember(
		ClassReflection $class,
		MemberKind $kind,
		string $name,
	): ClassConstantReflection|ExtendedMethodReflection|PhpPropertyReflection|null
	{
		$parent = $class->getParentClass();
		foreach ([...($parent === null ? [] : [$parent]), ...$class->getInterfaces()] as $ancestor) {
			$member = self::findNativeMember($ancestor, $kind, $name);
			if ($member !== null && !$member->isPrivate()) {
				return $member;
			}
		}

		return null;
	}


	/** The native type as PHP describes it; null for a declaration without one. */
	private static function writeNativeType(Type $type): ?string
	{
		return $type instanceof MixedType && !$type->isExplicitMixed() ? null : $type->describe(VerbosityLevel::typeOnly());
	}


	/** The value as PHP code; null for none and for a value that is no scalar, null or empty array. */
	private static function writeValue(?Type $value): ?string
	{
		$scalars = $value?->getConstantScalarValues() ?? [];
		return match (true) {
			$value === null => null,
			$value->isNull()->yes() => 'null',
			count($scalars) === 1 && is_bool($scalars[0]) => $scalars[0] ? 'true' : 'false',
			count($scalars) === 1 && $scalars[0] !== null => var_export($scalars[0], true),
			$value->isArray()->yes() && $value->isIterableAtLeastOnce()->no() => '[]',
			default => null,
		};
	}


	/**
	 * What the declaration of the member says about its deprecation; null for a member that is not deprecated, and for
	 * one its class does not have.
	 */
	public function findDeprecation(Member $member): ?Deprecation
	{
		$class = $this->phpstan->findClass($member->declaringClass);
		$reflection = $class === null ? null : self::findNativeMember($class, $member->kind, $member->name);
		return $reflection?->isDeprecated()->yes()
			? Deprecation::fromDescription($reflection->getDeprecatedDescription() ?? '')
			: null;
	}


	/**
	 * Whether the member is abstract, which a class that is not abstract must implement: a method or a property of an
	 * interface is, a constant never; maybe for a member its class does not have.
	 */
	public function isAbstract(Member $member): Tristate
	{
		$class = $this->phpstan->findClass($member->declaringClass);
		$reflection = $class === null ? null : self::findNativeMember($class, $member->kind, $member->name);
		$abstract = $reflection instanceof ClassConstantReflection ? false : $reflection?->isAbstract();
		return self::toTristate($abstract instanceof TrinaryLogic ? ($abstract->maybe() ? null : $abstract->yes()) : $abstract);
	}


	/**
	 * Whether the member of that name, which the class declaring the member has, can be written in its place without
	 * touching anything else: of the same kind and staticness, and for a method one that takes every call of the
	 * member the same way, the parameters of the member in the same order under the same names, each taking what
	 * the one of the member takes, and none required that the member does not have; at least as visible as the
	 * member, a private member of an ancestor being seen nowhere; for a property also at least as writable, and of the same
	 * native type.
	 */
	public function canReplace(Member $member, string $name): bool
	{
		$class = $this->phpstan->findClass($member->declaringClass);
		$own = $class === null ? null : self::findNativeMember($class, $member->kind, $member->name);
		$replacement = $class === null ? null : self::findNativeMember($class, $member->kind, $name);
		return $own !== null && $replacement !== null
			&& self::rankVisibility($replacement, $class) >= self::rankVisibility($own, $class)
			&& match ($member->kind) {
				MemberKind::Constant => true,
				MemberKind::Property, MemberKind::StaticProperty => $replacement->isStatic() === ($member->kind === MemberKind::StaticProperty)
					&& $own instanceof PhpPropertyReflection && $replacement instanceof PhpPropertyReflection
					&& self::rankWriting($replacement) >= self::rankWriting($own)
					&& $replacement->getNativeType()->equals($own->getNativeType()),
				MemberKind::Constructor => false,
				MemberKind::Method, MemberKind::StaticMethod => $this->canReplaceMethod($member, $name),
			};
	}


	/** The member of that kind the class declares natively, itself or through an ancestor. */
	private static function findNativeMember(
		ClassReflection $class,
		MemberKind $kind,
		string $name,
	): ClassConstantReflection|ExtendedMethodReflection|PhpPropertyReflection|null
	{
		return match ($kind) {
			MemberKind::Constant => $class->hasConstant($name) ? $class->getConstant($name) : null,
			MemberKind::Method, MemberKind::StaticMethod, MemberKind::Constructor => $class->hasNativeMethod($name) ? $class->getNativeMethod($name) : null,
			MemberKind::Property, MemberKind::StaticProperty => $class->hasNativeProperty($name) ? $class->getNativeProperty($name) : null,
		};
	}


	/** How widely the member of the class is seen: public, protected, private, and a private one of an ancestor least. */
	private static function rankVisibility(ClassMemberReflection $member, ClassReflection $class): int
	{
		return match (true) {
			$member->isPublic() => 3,
			!$member->isPrivate() => 2,
			$member->getDeclaringClass()->getName() === $class->getName() => 1,
			default => 0,
		};
	}


	/** How widely the property is written: nowhere outside its declaration, from its class, from it and its children, from anywhere. */
	private static function rankWriting(PhpPropertyReflection $property): int
	{
		return match (true) {
			$property->isReadOnly(), $property->isReadOnlyByPhpDoc(), !$property->isWritable(), $property->hasHook('get') && !$property->hasHook('set') => 0,
			$property->isPrivateSet() => 1,
			$property->isProtectedSet() => 2,
			default => 3,
		};
	}


	/** Whether the method of that name takes every call of the method of the member the same way. */
	private function canReplaceMethod(Member $member, string $name): bool
	{
		$ownStatic = $this->isStaticMethod($member->declaringClass, $member->name);
		$old = $this->findMethodParameters($member->declaringClass, $member->name);
		$new = $this->findMethodParameters($member->declaringClass, $name);
		if ($old === null || $new === null || $ownStatic !== $this->isStaticMethod($member->declaringClass, $name)) {
			return false;
		}

		foreach ($new as $i => $parameter) {
			$replaced = $old[$i] ?? null;
			if ($replaced === null ? !$parameter->optional : (
				$parameter->name !== $replaced->name
				|| $parameter->variadic !== $replaced->variadic
				|| (!$parameter->optional && $replaced->optional)
				|| !$parameter->canReplace($replaced)
			)) {
				return false;
			}
		}

		return count($new) >= count($old);
	}


	/**
	 * The declared spelling of a class, interface, trait or enum the project, its packages or PHP declare, given its
	 * fully qualified name in any letter case without a leading backslash; null for a name nothing declares.
	 */
	public function findClassName(string $name): ?string
	{
		return $this->phpstan->findClassName($name);
	}


	/**
	 * Whether the class is the ancestor, extends it, implements it or uses it as a trait, both fully qualified, in any
	 * letter case; maybe where either is a class nothing declares, or one whose ancestors run in a circle, which PHP
	 * refuses to load, and where an ancestor of the class is one nothing declares, so that its hierarchy is not seen whole.
	 */
	public function isSubtype(string $class, string $ancestor): Tristate
	{
		if (strcasecmp($class, $ancestor) === 0) {
			return Tristate::Yes;
		}

		$key = strtolower("$class $ancestor");
		if (!isset($this->subtypes[$key])) {
			$reflection = $this->phpstan->findClass($class);
			$ancestorReflection = $this->phpstan->findClass($ancestor);
			$this->subtypes[$key] = match (true) {
				$reflection === null || $ancestorReflection === null => Tristate::Maybe,
				$ancestorReflection->isTrait()
					? $reflection->hasTraitUse($ancestorReflection->getName())
					: $reflection->isSubclassOfClass($ancestorReflection) => Tristate::Yes,
				$this->isSeenWhole($reflection) => Tristate::No,
				default => Tristate::Maybe,
			};
		}

		return $this->subtypes[$key];
	}


	/** Whether PHP reads the class as an attribute, `#[\Attribute]` standing on it; maybe for a class nothing declares. */
	public function isAttributeClass(string $class): Tristate
	{
		return self::toTristate($this->phpstan->findClass($class)?->isAttributeClass());
	}


	/** Whether the class is an interface; maybe for a class nothing declares. */
	public function isInterface(string $class): Tristate
	{
		return self::toTristate($this->phpstan->findClass($class)?->isInterface());
	}


	/** Whether the class is declared final; maybe for a class nothing declares. */
	public function isFinalClass(string $class): Tristate
	{
		return self::toTristate($this->phpstan->findClass($class)?->isFinalByKeyword());
	}


	/**
	 * The abstract methods a class or an enum that is not abstract itself inherits from its parents, interfaces and
	 * traits and implements nowhere, each as the member of the ancestor or the trait declaring it; none for an abstract class, an
	 * interface, a trait and a class nothing declares.
	 * @return list<Member>
	 */
	public function findUnimplementedMethods(string $class): array
	{
		$reflection = $this->phpstan->findClass($class);
		if ($reflection === null || $reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
			return [];
		}

		$methods = [];
		foreach ($reflection->getNativeReflection()->getMethods() as $method) {
			if ($method->isAbstract()) {
				$native = $reflection->getNativeMethod($method->getName());
				$declaring = ($native instanceof PhpMethodReflection ? $native->getDeclaringTrait() : null) ?? $native->getDeclaringClass();
				$methods[] = new Member($method->isStatic() ? MemberKind::StaticMethod : MemberKind::Method, $method->getName(), $declaring->getName());
			}
		}

		return $methods;
	}


	/**
	 * Whether the class has the member of that kind, itself or through an ancestor; a magic one it does not have, and
	 * a class nothing declares has none: what the maps of members ask is whether the code still reaches a declaration,
	 * a member a library removed together with its class being as undeclared as one removed from the class alone.
	 */
	public function hasMember(string $class, MemberKind $kind, string $name): bool
	{
		$reflection = $this->phpstan->findClass($class);
		return $reflection !== null && match ($kind) {
			MemberKind::Constant => $reflection->hasConstant($name),
			MemberKind::Method, MemberKind::StaticMethod, MemberKind::Constructor => $reflection->hasNativeMethod($name),
			MemberKind::Property, MemberKind::StaticProperty => $reflection->hasNativeProperty($name),
		};
	}


	/**
	 * Whether the property the access reaches holds a plain value its reads and writes go straight to: declared,
	 * neither readonly, virtual nor hooked, and one no child can hook, being private or final or of a final class.
	 * No for a property reached through `__get` and `__set` and for a readonly, virtual or hooked one; maybe where
	 * a child may hook it, and for an access the types cannot tell or the analysis was made without.
	 */
	public function isPlainProperty(ExpressionNode $access): Tristate
	{
		$member = $this->findMember($access);
		if ($member === null) {
			// a property no class declares is magic where every class of the receiver answers through __get or __set
			$classes = $this->findMemberAccess($access)->classes ?? [];
			return $classes !== [] && array_all($classes, fn(string $name) => ($class = $this->phpstan->findClass($name)) !== null
				&& ($class->hasNativeMethod('__get') || $class->hasNativeMethod('__set')))
					? Tristate::No
					: Tristate::Maybe;
		}

		$class = $member->kind === MemberKind::Property || $member->kind === MemberKind::StaticProperty
			? $this->phpstan->findClass($member->declaringClass)
			: null;
		if ($class === null) {
			return Tristate::Maybe;
		} elseif (!$class->hasNativeProperty($member->name)) {
			return Tristate::No;
		}

		$property = $class->getNativeProperty($member->name);
		return match (true) {
			$property->isReadOnly(), $property->isVirtual()->yes(), $property->hasHook('get'), $property->hasHook('set') => Tristate::No,
			$property->isPrivate(), $property->isFinal()->yes(), $class->isFinalByKeyword() => Tristate::Yes,
			default => Tristate::Maybe,
		};
	}


	/**
	 * Whether the class has the method, in any letter case of either, and it is static; no for a class that has no such
	 * method, maybe for a class nothing declares and for one whose hierarchy is not seen whole and has no such method.
	 */
	public function isStaticMethod(string $class, string $method): Tristate
	{
		$reflection = $this->phpstan->findClass($class);
		return match (true) {
			$reflection === null => Tristate::Maybe,
			$reflection->hasNativeMethod($method) => $reflection->getNativeMethod($method)->isStatic() ? Tristate::Yes : Tristate::No,
			$this->isSeenWhole($reflection) => Tristate::No,
			default => Tristate::Maybe,
		};
	}


	/** Whether every ancestor the class and its ancestors name, its parent, interfaces and traits, is one something declares. */
	private function isSeenWhole(ClassReflection $class): bool
	{
		return $this->seenWhole[strtolower($class->getName())] ??= array_all(
			$class->getAncestors(),
			function (ClassReflection $ancestor): bool {
				$native = $ancestor->getNativeReflection()->getBetterReflection();
				return array_all(
					array_filter([$native->getParentClassName(), ...$native->getInterfaceClassNames(), ...$native->getTraitClassNames()]),
					fn(string $name) => $this->phpstan->findClass($name) !== null,
				);
			},
		);
	}


	private static function toTristate(?bool $answer): Tristate
	{
		return match ($answer) {
			true => Tristate::Yes,
			false => Tristate::No,
			null => Tristate::Maybe,
		};
	}


	/**
	 * What the declaration of the class says about its deprecation; null for one that is not deprecated or that nothing
	 * declares. The replacement is the class its description names, `use Acme\Mail\SmtpTransport`, in its declared spelling:
	 * a bare name is looked up beside the deprecated class before it is taken as written, a qualified one the other
	 * way round, and there is none where neither exists.
	 */
	public function findClassDeprecation(string $class): ?Deprecation
	{
		$reflection = $this->phpstan->findClass($class);
		if (!$reflection?->isDeprecated()) {
			return null;
		}

		$description = $reflection->getDeprecatedDescription() ?? '';
		$namespace = QualifiedNames::extractNamespace($reflection->getName());
		if (!preg_match('~^\\\\?(\w+(?:\\\\\w+)*)$~D', Deprecation::findReplacementCode($description) ?? '', $m)) {
			return new Deprecation($description);
		}

		$beside = ltrim("$namespace\\$m[1]", '\\');
		$replacement = str_contains($m[1], '\\')
			? $this->findClassName($m[1]) ?? $this->findClassName($beside)
			: $this->findClassName($beside) ?? $this->findClassName($m[1]);
		return new Deprecation($description, $replacement);
	}


	/**
	 * What the syntax of a member access says and the types add: the kind, the type of the receiver, the name as
	 * written and the scope; null for a node that is no access, whose name is an expression or whose receiver is no object.
	 * @return ?array{MemberKind, Type, string, Scope}
	 */
	private function findReceiver(ExpressionNode $node): ?array
	{
		$found = $this->findExpression($node);
		if ($found === null) {
			return null;
		}

		[$parserNode, $scope] = $found;
		if (
			$parserNode instanceof MethodCallableNode
			|| $parserNode instanceof StaticMethodCallableNode
			|| $parserNode instanceof InstantiationCallableNode
		) {
			$parserNode = $parserNode->getOriginalNode(); // a first-class callable comes as a node of PHPStan's own
		}

		[$kind, $type, $name] = match (true) {
			$node instanceof ClassConstantFetchNode && $node->name instanceof IdentifierNode && $parserNode instanceof Expr\ClassConstFetch
				=> [MemberKind::Constant, self::getClassType($parserNode->class, $scope), $node->name->text],
			$node instanceof StaticMethodCallNode && $node->name instanceof IdentifierNode && $parserNode instanceof Expr\StaticCall
				=> [MemberKind::StaticMethod, self::getClassType($parserNode->class, $scope), $node->name->text],
			$node instanceof MethodCallNode && $node->name instanceof IdentifierNode && ($parserNode instanceof Expr\MethodCall || $parserNode instanceof Expr\NullsafeMethodCall)
				=> [MemberKind::Method, $scope->getType($parserNode->var), $node->name->text],
			$node instanceof StaticPropertyFetchNode && $node->plainName !== null && $parserNode instanceof Expr\StaticPropertyFetch
				=> [MemberKind::StaticProperty, self::getClassType($parserNode->class, $scope), $node->plainName],
			$node instanceof PropertyFetchNode && $node->name instanceof IdentifierNode && ($parserNode instanceof Expr\PropertyFetch || $parserNode instanceof Expr\NullsafePropertyFetch)
				=> [MemberKind::Property, $scope->getType($parserNode->var), $node->name->text],
			$node instanceof NewNode && $node->class instanceof NameNode && $parserNode instanceof Expr\New_ && $parserNode->class instanceof ParserNode\Name
				=> [MemberKind::Constructor, $scope->resolveTypeByName($parserNode->class), '__construct'],
			default => [null, null, null],
		};
		// a receiver that may be null is the class it may be: where it is null, the call fails before and after alike
		$type = $type === null ? null : TypeCombinator::removeNull($type);
		return $kind === null || $type === null || $name === null || $type->getObjectClassNames() === []
			? null // no class the member could be declared by: mixed, a scalar, an unknown variable
			: [$kind, $type, $name, $scope];
	}


	private static function getClassType(ParserNode\Name|Expr $class, Scope $scope): Type
	{
		return $class instanceof ParserNode\Name ? $scope->resolveTypeByName($class) : $scope->getType($class);
	}
}
