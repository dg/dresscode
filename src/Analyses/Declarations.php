<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;

use DressCode\Tristate;
use PHPStan\Reflection\{ClassReflection, ExtendedParameterReflection};
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
		$variants = $reflection !== null && $reflection->hasNativeMethod($method)
			? $reflection->getNativeMethod($method)->getVariants()
			: [];
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
}
