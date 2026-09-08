<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;

use PhpParser\Node as ParserNode;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Node\{InstantiationCallableNode, MethodCallableNode, StaticMethodCallableNode};
use PHPStan\Reflection\{ClassConstantReflection, ExtendedMethodReflection, ExtendedParameterReflection, ExtendedPropertyReflection};
use PHPStan\TrinaryLogic;
use PHPStan\Type\{Type, VerbosityLevel};
use PhpSyntax\{Node, Printer};
use PhpSyntax\Nodes\Expression\{ClassConstantFetchNode, MethodCallNode, NewNode, PropertyFetchNode, StaticMethodCallNode, StaticPropertyFetchNode};
use PhpSyntax\Nodes\{ExpressionNode, FileNode, IdentifierNode, NameNode};
use PhpSyntax\Nodes\Member\MethodNode;
use function count;


/**
 * The types of the code, from the PHPStan of the project: what an expression is, which member a call or an
 * access reaches, and what the declaration of that member says about it. The answers are those of the text the
 * pass began with; a node inserted during the pass has none, and a rule asking for one asks with `?->`.
 * A rule asks questions, and getType() is the one answer in the words of PHPStan, for what the questions do
 * not cover; nothing else of the API names it.
 */
final class Types implements PassAnalysis
{
	/** @var \SplObjectStorage<ExpressionNode, array{Expr, Scope}> */
	private \SplObjectStorage $expressions;

	/** @var \SplObjectStorage<MethodNode, Scope> */
	private \SplObjectStorage $declarations;

	/** @var \WeakMap<Callee, ClassConstantReflection|ExtendedMethodReflection|ExtendedPropertyReflection> */
	private \WeakMap $reflections;

	/** @var array<string, bool>  lowercased "class ancestor" → whether the one is the other's subtype */
	private array $subtypes = [];


	public function __construct(
		FileNode $file,
		string $path,
		private readonly PhpStan $phpstan,
	) {
		$this->expressions = new \SplObjectStorage;
		$this->declarations = new \SplObjectStorage;
		$this->reflections = new \WeakMap;
		$index = $file->getIndex();
		$phpstan->resolveScopes($path, $phpstan->parse(Printer::print($file)), function (ParserNode $node, Scope $scope) use ($index): void {
			if ($node instanceof Expr) {
				$expression = $index->findNode($node->getStartFilePos(), $node->getEndFilePos() + 1, ExpressionNode::class);
				if ($expression !== null && !isset($this->expressions[$expression])) {
					$this->expressions[$expression] = [$node, $scope];
				}
			} elseif ($node instanceof ParserNode\Stmt\ClassMethod) {
				$method = $index->findNode($node->getStartFilePos(), $node->getEndFilePos() + 1, MethodNode::class);
				if ($method !== null && !isset($this->declarations[$method])) {
					$this->declarations[$method] = $scope;
				}
			}
		});
	}


	/** The type of the expression as PHPStan sees it; null for an expression the pass began without. */
	public function getType(ExpressionNode $expression): ?Type
	{
		if (!isset($this->expressions[$expression])) {
			return null;
		}

		[$node, $scope] = $this->expressions[$expression];
		return $scope->getType($node);
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
	 * an access, the constructor of an instantiation, decided by the class that declares it. Null for a node that
	 * reaches no known member, or whose name is an expression.
	 */
	public function findCallee(Node $node): ?Callee
	{
		$receiver = $this->findReceiver($node);
		if ($receiver === null) {
			return null;
		}

		[$kind, $type, $name, $scope] = $receiver;
		$reflection = match ($kind) {
			MemberKind::Constant => $scope->getConstantReflection($type, $name),
			MemberKind::Method, MemberKind::StaticMethod, MemberKind::Constructor => $scope->getMethodReflection($type, $name),
			MemberKind::Property, MemberKind::StaticProperty => $scope->getPropertyReflection($type, $name),
		};
		if ($reflection === null) {
			return null;
		}

		// a property reflection does not say its name, which the declaration cannot spell differently anyway
		$callee = new Callee($kind, $reflection instanceof ExtendedPropertyReflection ? $name : $reflection->getName(), $reflection->getDeclaringClass()->getName());
		$this->reflections[$callee] = $reflection;
		return $callee;
	}


	/**
	 * The member access the node makes, decided by the type of its receiver and not by the class that declares the
	 * member: a class constant access, a method call, a property access, or an instantiation of a named class. There
	 * is one for a member no class declares any more, and where a child overrides the member the classes are those of
	 * the receiver. Null for a name that is an expression and for a receiver that is no object.
	 */
	public function findAccess(Node $node): ?Access
	{
		$receiver = $this->findReceiver($node);
		if ($receiver === null) {
			return null;
		}

		[$kind, $type, $name] = $receiver;
		$classes = $type->getObjectClassNames();
		$declared = false;
		foreach ($classes as $class) {
			$reflection = $this->phpstan->findClass($class);
			$declared = $declared || ($reflection !== null && match ($kind) {
				MemberKind::Constant => $reflection->hasConstant($name),
				MemberKind::Method, MemberKind::StaticMethod, MemberKind::Constructor => $reflection->hasNativeMethod($name),
				MemberKind::Property, MemberKind::StaticProperty => $reflection->hasNativeProperty($name),
			});
		}

		return new Access($kind, $name, $classes, $declared);
	}


	/**
	 * The instantiation, or a call of `parent::__construct()`, as the access of the constructor it runs, whose class is
	 * the one that declares it: `new Child` of a child that declares none runs the constructor of its parent, a child
	 * that declares one its own. Null for any other node.
	 */
	public function findConstructorAccess(Node $node): ?Access
	{
		$access = $node instanceof NewNode || ($node instanceof StaticMethodCallNode && $node->name instanceof IdentifierNode && strcasecmp($node->name->text, '__construct') === 0)
			? $this->findAccess($node)
			: null;
		if ($access === null) {
			return null;
		}

		$declaring = $this->findCallee($node)?->declaringClass;
		return new Access(MemberKind::Constructor, '__construct', $declaring === null ? $access->classes : [$declaring], $access->declared);
	}


	/**
	 * The parameters of the method the access calls, as the class of the receiver declares them; null for an access
	 * that is no call, for a method no class of the receiver declares, and where two of them declare it differently.
	 * @return ?list<Parameter>
	 */
	public function findParameters(Access $access): ?array
	{
		if (!in_array($access->kind, [MemberKind::Method, MemberKind::StaticMethod, MemberKind::Constructor], true)) {
			return null;
		}

		$found = null;
		foreach ($access->classes as $class) {
			$reflection = $this->phpstan->findClass($class);
			if ($reflection === null || !$reflection->hasNativeMethod($access->name)) {
				continue;
			}

			$variants = $reflection->getNativeMethod($access->name)->getVariants();
			if (count($variants) !== 1) {
				return null;
			}

			$parameters = array_map(
				fn(ExtendedParameterReflection $parameter) => new Parameter(
					$parameter->getName(),
					$parameter->getNativeType()->describe(VerbosityLevel::typeOnly()),
					$parameter->isVariadic(),
					$parameter->passedByReference()->yes(),
					$parameter->isOptional(),
				),
				$variants[0]->getParameters(),
			);
			if ($found !== null && array_map(get_object_vars(...), $found) !== array_map(get_object_vars(...), $parameters)) {
				return null;
			}

			$found = $parameters;
		}

		return $found;
	}


	/** The class the method declaration belongs to; null for a declaration the pass began without. */
	public function findDeclaringClass(Node $declaration): ?string
	{
		return $declaration instanceof MethodNode && isset($this->declarations[$declaration])
			? $this->declarations[$declaration]->getClassReflection()?->getName()
			: null;
	}


	/**
	 * The method of the same name a parent class or an interface declares, which the declaration overrides; a private
	 * method of an ancestor is hidden, not overridden, and the next ancestor is asked. Null where the declaration
	 * overrides nothing, and for a declaration the pass began without.
	 */
	public function findOverridden(Node $declaration): ?Callee
	{
		if (!$declaration instanceof MethodNode || !isset($this->declarations[$declaration])) {
			return null;
		}

		$scope = $this->declarations[$declaration];
		$class = $scope->getClassReflection();
		if ($class === null) {
			return null;
		}

		$name = $declaration->name->text;
		$parent = $class->getParentClass();
		foreach ([...($parent === null ? [] : [$parent]), ...$class->getInterfaces()] as $ancestor) {
			if (!$ancestor->hasMethod($name)) {
				continue;
			}

			$method = $ancestor->getMethod($name, $scope);
			if ($method->isPrivate()) {
				continue; // a private method of an ancestor is not overridden, it is hidden
			}

			$callee = new Callee(MemberKind::Method, $method->getName(), $method->getDeclaringClass()->getName());
			$this->reflections[$callee] = $method;
			return $callee;
		}

		return null;
	}


	/**
	 * Whether the parent class declares the method the way the declaration does, so that a caller sees no difference
	 * between the two: the same visibility and staticness, the same parameters by name, order, default, reference,
	 * variadic and native type, and the same native return type. False for a declaration the pass began without,
	 * for one no parent class declares, and for one whose parent declaration is private or abstract.
	 */
	public function hasParentSignature(Node $declaration): bool
	{
		if (!$declaration instanceof MethodNode || !isset($this->declarations[$declaration])) {
			return false;
		}

		$name = $declaration->name->text;
		$class = $this->declarations[$declaration]->getClassReflection();
		$parent = $class?->getParentClass();
		if ($class === null || $parent === null || !$class->hasNativeMethod($name) || !$parent->hasNativeMethod($name)) {
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
	 * What the declaration of the member says about its deprecation; null for a member that is not deprecated. The
	 * callee must come from this analysis, so one kept from an earlier pass is refused.
	 */
	public function getDeprecation(Callee $callee): ?Deprecation
	{
		$reflection = $this->reflections[$callee] ?? throw new \InvalidArgumentException('The callee is not one of this analysis.');
		return $reflection->isDeprecated()->yes()
			? Deprecation::fromDescription($reflection->getDeprecatedDescription() ?? '')
			: null;
	}


	/**
	 * Whether the member of that name, which the class declaring the callee has, can be written in its place without
	 * touching anything else: of the same kind and staticness, and for a method one that takes every call of the
	 * callee the same way, the parameters of the callee in the same order under the same names, each taking what
	 * the one of the callee takes, and none required that the callee does not have.
	 */
	public function canReplace(Callee $callee, string $name): bool
	{
		$class = $this->phpstan->findClass($callee->declaringClass);
		if ($class === null) {
			return false;
		}

		switch ($callee->kind) {
			case MemberKind::Constant:
				return $class->hasConstant($name);
			case MemberKind::Property:
			case MemberKind::StaticProperty:
				return $class->hasNativeProperty($name)
					&& $class->getNativeProperty($name)->isStatic() === ($callee->kind === MemberKind::StaticProperty);
			case MemberKind::Constructor:
				return false;
		}

		$ownStatic = $this->isStaticMethod($callee->declaringClass, $callee->name);
		$old = $this->findParameters(new Access($callee->kind, $callee->name, [$callee->declaringClass], true));
		$new = $this->findParameters(new Access($callee->kind, $name, [$callee->declaringClass], true));
		if ($old === null || $new === null || $ownStatic !== $this->isStaticMethod($callee->declaringClass, $name)) {
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
	 * letter case; a class nothing declares is only ever itself.
	 */
	public function isSubtype(string $class, string $ancestor): bool
	{
		if (strcasecmp($class, $ancestor) === 0) {
			return true;
		}

		$key = strtolower("$class $ancestor");
		if (!isset($this->subtypes[$key])) {
			$reflection = $this->phpstan->findClass($class);
			$ancestorReflection = $this->phpstan->findClass($ancestor);
			$this->subtypes[$key] = $reflection !== null && $ancestorReflection !== null && ($ancestorReflection->isTrait()
				? $reflection->hasTraitUse($ancestorReflection->getName())
				: $reflection->isSubclassOfClass($ancestorReflection));
		}

		return $this->subtypes[$key];
	}


	/** Whether the class declares the property, itself or through an ancestor; a magic one is not declared. */
	public function hasProperty(string $class, string $property): bool
	{
		return $this->phpstan->findClass($class)?->hasNativeProperty($property) ?? false;
	}


	/** Whether the method the class has is static, in any letter case of either; null where the class has no such method. */
	public function isStaticMethod(string $class, string $method): ?bool
	{
		$reflection = $this->phpstan->findClass($class);
		return $reflection !== null && $reflection->hasNativeMethod($method)
			? $reflection->getNativeMethod($method)->isStatic()
			: null;
	}


	/**
	 * What the declaration of the class says about its deprecation; null for one that is not deprecated or that nothing
	 * declares. The replacement is the class its description names, `use Acme\Mail\SmtpTransport`, in its declared spelling:
	 * a bare name is looked up beside the deprecated class before it is taken as written, a qualified one the other
	 * way round, and there is none where neither exists.
	 */
	public function getClassDeprecation(string $class): ?Deprecation
	{
		$reflection = $this->phpstan->findClass($class);
		if (!$reflection?->isDeprecated()) {
			return null;
		}

		$description = $reflection->getDeprecatedDescription() ?? '';
		$namespace = substr($reflection->getName(), 0, (int) strrpos($reflection->getName(), '\\'));
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
	private function findReceiver(Node $node): ?array
	{
		if (!$node instanceof ExpressionNode || !isset($this->expressions[$node])) {
			return null;
		}

		[$parserNode, $scope] = $this->expressions[$node];
		if (
			$parserNode instanceof MethodCallableNode
			|| $parserNode instanceof StaticMethodCallableNode
			|| $parserNode instanceof InstantiationCallableNode
		) {
			$parserNode = $parserNode->getOriginalNode(); // a first-class callable comes as a node of PHPStan's own
		}

		[$kind, $type, $name] = match (true) {
			$node instanceof ClassConstantFetchNode && $node->name instanceof IdentifierNode && $parserNode instanceof Expr\ClassConstFetch
				=> [MemberKind::Constant, self::classType($parserNode->class, $scope), $node->name->text],
			$node instanceof StaticMethodCallNode && $node->name instanceof IdentifierNode && $parserNode instanceof Expr\StaticCall
				=> [MemberKind::StaticMethod, self::classType($parserNode->class, $scope), $node->name->text],
			$node instanceof MethodCallNode && $node->name instanceof IdentifierNode && ($parserNode instanceof Expr\MethodCall || $parserNode instanceof Expr\NullsafeMethodCall)
				=> [MemberKind::Method, $scope->getType($parserNode->var), $node->name->text],
			$node instanceof StaticPropertyFetchNode && $node->plainName !== null && $parserNode instanceof Expr\StaticPropertyFetch
				=> [MemberKind::StaticProperty, self::classType($parserNode->class, $scope), $node->plainName],
			$node instanceof PropertyFetchNode && $node->name instanceof IdentifierNode && ($parserNode instanceof Expr\PropertyFetch || $parserNode instanceof Expr\NullsafePropertyFetch)
				=> [MemberKind::Property, $scope->getType($parserNode->var), $node->name->text],
			$node instanceof NewNode && $node->class instanceof NameNode && $parserNode instanceof Expr\New_ && $parserNode->class instanceof ParserNode\Name
				=> [MemberKind::Constructor, $scope->resolveTypeByName($parserNode->class), '__construct'],
			default => [null, null, null],
		};
		return $kind === null || $type === null || $name === null || $type->getObjectClassNames() === []
			? null // no class the member could be declared by: mixed, a scalar, an unknown variable
			: [$kind, $type, $name, $scope];
	}


	private static function classType(ParserNode\Name|Expr $class, Scope $scope): Type
	{
		return $class instanceof ParserNode\Name ? $scope->resolveTypeByName($class) : $scope->getType($class);
	}
}
