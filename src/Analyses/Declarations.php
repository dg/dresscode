<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;

use DressCode\Rules\QualifiedNames;
use DressCode\Tristate;
use PHPStan\Reflection\{ClassConstantReflection, ClassMemberReflection, ClassReflection, ExtendedMethodReflection, ExtendedParameterReflection};
use PHPStan\Reflection\Php\{PhpMethodReflection, PhpPropertyReflection};
use PHPStan\TrinaryLogic;
use PHPStan\Type\{MixedType, Type, VerbosityLevel};
use function count, is_bool;


/**
 * What the declarations of the project, its packages and PHP say of a class and its members, asked by their names: one
 * object per PHPStan, so that its answers hold for every pass of every file that PHPStan reads. Each question is that of
 * the same name of `Types`, which describes it and asks it here.
 * @internal
 */
final class Declarations
{
	/** @var array<string, Tristate>  lowercased "class ancestor" => whether the one is the other's subtype */
	private array $subtypes = [];

	/** @var array<string, bool>  lowercased class => whether something declares every ancestor it names */
	private array $seenWhole = [];


	public function __construct(
		private readonly PhpStan $phpstan,
	) {
	}


	/** @return ?list<Parameter> */
	public function findMethodParameters(string $class, string $method): ?array
	{
		$reflection = $this->phpstan->findClass($class);
		$variants = $reflection !== null && $reflection->hasNativeMethod($method) ? $reflection->getNativeMethod($method)->getVariants() : [];
		return count($variants) === 1 ? array_map(self::toParameter(...), $variants[0]->getParameters()) : null;
	}


	public static function toParameter(ExtendedParameterReflection $parameter): Parameter
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


	/** The native type as PHP describes it; null for a declaration without one. */
	public static function writeNativeType(Type $type): ?string
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


	public function findDeprecation(Member $member): ?Deprecation
	{
		$class = $this->phpstan->findClass($member->declaringClass);
		$reflection = $class === null ? null : self::findNativeMember($class, $member->kind, $member->name);
		return $reflection?->isDeprecated()->yes()
			? Deprecation::fromDescription($reflection->getDeprecatedDescription() ?? '')
			: null;
	}


	public function isAbstract(Member $member): Tristate
	{
		$class = $this->phpstan->findClass($member->declaringClass);
		$reflection = $class === null ? null : self::findNativeMember($class, $member->kind, $member->name);
		$abstract = $reflection instanceof ClassConstantReflection ? false : $reflection?->isAbstract();
		return self::toTristate($abstract instanceof TrinaryLogic ? ($abstract->maybe() ? null : $abstract->yes()) : $abstract);
	}


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
	public static function findNativeMember(
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


	public function findClassName(string $name): ?string
	{
		return $this->phpstan->findClassName($name);
	}


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


	public function isAttributeClass(string $class): Tristate
	{
		return self::toTristate($this->phpstan->findClass($class)?->isAttributeClass());
	}


	public function isInterface(string $class): Tristate
	{
		return self::toTristate($this->phpstan->findClass($class)?->isInterface());
	}


	public function isFinalClass(string $class): Tristate
	{
		return self::toTristate($this->phpstan->findClass($class)?->isFinalByKeyword());
	}


	/** @return list<Member> */
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


	public function hasMember(string $class, MemberKind $kind, string $name): bool
	{
		$reflection = $this->phpstan->findClass($class);
		return $reflection !== null && match ($kind) {
			MemberKind::Constant => $reflection->hasConstant($name),
			MemberKind::Method, MemberKind::StaticMethod, MemberKind::Constructor => $reflection->hasNativeMethod($name),
			MemberKind::Property, MemberKind::StaticProperty => $reflection->hasNativeProperty($name),
		};
	}


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
}
