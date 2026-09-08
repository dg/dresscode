<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;

use DressCode\Tristate;
use PhpParser\Node as ParserNode;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Node\{InstantiationCallableNode, MethodCallableNode, StaticMethodCallableNode};
use PHPStan\Reflection\{ClassConstantReflection, ClassMemberReflection, ClassReflection, ExtendedMethodReflection, ExtendedParameterReflection, ExtendedPropertyReflection};
use PHPStan\Reflection\Php\PhpPropertyReflection;
use PHPStan\Type\{MixedType, Type, TypeCombinator, VerbosityLevel};
use PhpSyntax\{Node, Printer, Token};
use PhpSyntax\Nodes\Expression\{ClassConstantFetchNode, MethodCallNode, NewNode, PropertyFetchNode, StaticMethodCallNode, StaticPropertyFetchNode};
use PhpSyntax\Nodes\{ExpressionNode, FileNode, IdentifierNode, NameNode};
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

	/** @var ?\Closure(): array<ParserNode\Stmt>  the text parsed by PHPStan once asked, until the scopes are resolved from it */
	private ?\Closure $parse;

	/** @var array<string, list<ExpressionNode>>  "start:end" => the expressions the text stood at when the analysis was made, the innermost first */
	private array $spans = [];

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
		$code = Printer::print($file);
		$ast = null;
		$this->parse = function () use ($phpstan, $code, &$ast): array {
			return $ast ??= $phpstan->parse($code);
		};
		$this->phpstan = $phpstan->deriveFor($path, $code, $this->parse);
		$this->collectSpans($file);
	}


	/**
	 * Records where the text of the expressions under the node stands, as `FileNode::findNode()` does.
	 * @return ?array{int, int}  its offsets, the end exclusive; null for a node without tokens
	 */
	private function collectSpans(Node $node): ?array
	{
		$start = $end = null;
		foreach ($node->getChildren() as $child) {
			if ($child instanceof Token) {
				$offset = $child->currentOffset;
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
		} elseif ($node instanceof ExpressionNode) {
			$this->spans["$start:$end"][] = $node;
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
				$expression = $this->findSpan($node);
				if ($expression !== null && !isset($this->expressions[$expression])) {
					$this->expressions[$expression] = [$node, $scope];
				}
			}
		});
	}


	/** The outermost expression the text of the node of PHPStan stood at. */
	private function findSpan(ParserNode $node): ?ExpressionNode
	{
		$nodes = $this->spans[$node->getStartFilePos() . ':' . ($node->getEndFilePos() + 1)] ?? [];
		return $nodes[count($nodes) - 1] ?? null;
	}


	/** @return ?array{Expr, Scope} */
	private function findExpression(ExpressionNode $node): ?array
	{
		$this->resolveScopes();
		return $this->expressions[$node] ?? null;
	}


	/** The type of the expression as PHPStan sees it; null for an expression the analysis was made without. */
	private function getType(ExpressionNode $expression): ?Type
	{
		$found = $this->findExpression($expression);
		if ($found === null) {
			return null;
		}

		[$node, $scope] = $found;
		return $scope->getType($node);
	}


	/**
	 * Whether the expression is of the type, written in the syntax of PHPDoc as PHPStan reads it (`int|string`,
	 * `list<int>`, `non-empty-string`), its classes fully qualified; maybe where it may or may not be, and for an
	 * expression the analysis was made without.
	 */
	public function isOfType(ExpressionNode $expression, string $type): Tristate
	{
		$actual = $this->getType($expression);
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
			self::describeNative($parameter->getNativeType()),
			$parameter->isOptional(),
			$parameter->isVariadic(),
			$parameter->passedByReference()->yes(),
			self::writeValue($parameter->getDefaultValue()),
		);
	}


	/** The native type as PHP describes it; null for a declaration without one. */
	private static function describeNative(Type $type): ?string
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
	 * Whether the class has the member of that kind, itself or through an ancestor; a magic one it does not have, and
	 * a class nothing declares has none.
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
		// a receiver that may be null is the class it may be: where it is null, the call fails before and after alike
		$type = $type === null ? null : TypeCombinator::removeNull($type);
		return $kind === null || $type === null || $name === null || $type->getObjectClassNames() === []
			? null // no class the member could be declared by: mixed, a scalar, an unknown variable
			: [$kind, $type, $name, $scope];
	}


	private static function classType(ParserNode\Name|Expr $class, Scope $scope): Type
	{
		return $class instanceof ParserNode\Name ? $scope->resolveTypeByName($class) : $scope->getType($class);
	}
}
