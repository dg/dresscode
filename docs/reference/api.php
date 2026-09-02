#!/usr/bin/env php
<?php declare(strict_types=1);


/**
 * Generates api.md, the public surface as reflection sees it: every class of src/ not marked @internal with its public
 * members not marked @internal, a built-in rule by its class alone. Run by `composer api`; the output is committed and
 * CI diffs it, so that a change of the surface shows in the diff of the commit making it. Which class is public is
 * decided by its @internal tag.
 */
use DressCode\Rule;

require __DIR__ . '/../../vendor/autoload.php';


/** @param ReflectionClass<*>|ReflectionClassConstant|ReflectionMethod|ReflectionProperty $reflection */
function isInternal(ReflectionClass|ReflectionClassConstant|ReflectionMethod|ReflectionProperty $reflection): bool
{
	return (bool) preg_match('~^\s*(/\*)?\*\s*@internal\b~m', (string) $reflection->getDocComment());
}


function writeType(?ReflectionType $type): string
{
	return $type === null ? '' : $type . ' ';
}


function writeValue(mixed $value): string
{
	return match (true) {
		$value === null => 'null',
		$value === [] => '[]',
		is_array($value) => '[' . implode(', ', array_map(
			fn($key, $item) => (array_is_list($value) ? '' : writeValue($key) . ' => ') . writeValue($item),
			array_keys($value),
			$value,
		)) . ']',
		$value instanceof UnitEnum => $value::class . '::' . $value->name,
		is_object($value) => 'new ' . $value::class,
		is_string($value) && preg_match('~[\x00-\x1F]~', $value) => '"' . addcslashes($value, "\0..\37\"\\\$") . '"',
		default => var_export($value, true),
	};
}


function writeParameter(ReflectionParameter $parameter): string
{
	$default = '';
	if ($parameter->isDefaultValueAvailable()) {
		$default = ' = ' . ($parameter->getDefaultValueConstantName() ?? writeValue($parameter->getDefaultValue()));
	}

	return writeType($parameter->getType())
		. ($parameter->isPassedByReference() ? '&' : '')
		. ($parameter->isVariadic() ? '...' : '')
		. '$' . $parameter->getName()
		. $default;
}


function writeMethod(ReflectionMethod $method): string
{
	$parameters = array_map(writeParameter(...), $method->getParameters());
	return ($method->isAbstract() && !$method->getDeclaringClass()->isInterface() ? 'abstract ' : '')
		. ($method->isFinal() ? 'final ' : '')
		. 'public '
		. ($method->isStatic() ? 'static ' : '')
		. 'function ' . $method->getName() . '(' . implode(', ', $parameters) . ')'
		. ($method->hasReturnType() ? ': ' . $method->getReturnType() : '');
}


function writeProperty(ReflectionProperty $property): string
{
	return 'public '
		. ($property->isStatic() ? 'static ' : '')
		. ($property->isReadOnly() ? 'readonly ' : '')
		. ($property->isPrivateSet() && !$property->isReadOnly() ? 'private(set) ' : '')
		. ($property->isProtectedSet() && !$property->isReadOnly() ? 'protected(set) ' : '')
		. writeType($property->getType())
		. '$' . $property->getName()
		. ($property->hasDefaultValue() && $property->getDefaultValue() !== null ? ' = ' . writeValue($property->getDefaultValue()) : '');
}


/** @param ReflectionClass<*> $class */
function writeHead(ReflectionClass $class): string
{
	$parent = $class->getParentClass();
	$interfaces = $class->getInterfaceNames();
	sort($interfaces);
	if ($class instanceof ReflectionEnum) {
		$backing = $class->getBackingType();
		$interfaces = array_values(array_diff($interfaces, [UnitEnum::class, BackedEnum::class]));
		return 'enum ' . $class->getShortName() . ($backing === null ? '' : ": $backing")
			. ($interfaces === [] ? '' : ' implements ' . implode(', ', $interfaces));
	} elseif ($class->isInterface()) {
		return 'interface ' . $class->getShortName() . ($interfaces === [] ? '' : ' extends ' . implode(', ', $interfaces));
	}

	return ($class->isAbstract() ? 'abstract ' : '')
		. ($class->isFinal() ? 'final ' : '')
		. ($class->isReadOnly() ? 'readonly ' : '')
		. 'class ' . $class->getShortName()
		. ($parent === false ? '' : ' extends ' . $parent->getName())
		. ($interfaces === [] ? '' : ' implements ' . implode(', ', $interfaces));
}


/**
 * @param  ReflectionClass<*>  $class
 * @return list<string>
 */
function writeMembers(ReflectionClass $class): array
{
	$lines = [];
	$own = fn(ReflectionClassConstant|ReflectionMethod|ReflectionProperty $member) => $member->getDeclaringClass()->getName() === $class->getName()
		&& $member->isPublic()
		&& !isInternal($member);
	foreach ($class->getReflectionConstants() as $constant) {
		if ($own($constant)) {
			$lines[] = $constant->isEnumCase()
				? 'case ' . $constant->getName() . ($constant->getValue() instanceof BackedEnum ? ' = ' . writeValue($constant->getValue()->value) : '')
				: 'public const ' . $constant->getName() . ' = ' . writeValue($constant->getValue());
		}
	}

	foreach ($class->getProperties() as $property) {
		if ($own($property) && !$class->isEnum()) {
			$lines[] = writeProperty($property);
		}
	}

	foreach ($class->getMethods() as $method) {
		if ($own($method) && !($class->isEnum() && in_array($method->getName(), ['cases', 'from', 'tryFrom'], true))) {
			$lines[] = writeMethod($method);
		}
	}

	return $lines;
}


$src = (string) realpath(__DIR__ . '/../../src');
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
	if ($file->getExtension() === 'php') {
		require_once (string) $file;
	}
}

$classes = [];
foreach ([...get_declared_classes(), ...get_declared_interfaces()] as $name) {
	$class = enum_exists($name) ? new ReflectionEnum($name) : new ReflectionClass($name);
	if (str_starts_with((string) $class->getFileName(), $src) && !isInternal($class)) {
		$classes[$name] = $class;
	}
}

ksort($classes, SORT_STRING | SORT_FLAG_CASE);

$rules = [];
$out = "# Public API\n\nGenerated by `composer api` from the classes of `src/` that are not `@internal`, with their public members that\n"
	. "are not either; a built-in rule is named by its class alone, its name and options being in [rules.md](rules.md).\n";
foreach ($classes as $name => $class) {
	if (str_starts_with($name, 'DressCode\Rules\\') && $class->isSubclassOf(Rule::class) && !$class->isAbstract()) {
		$rules[] = $name;
		continue;
	}

	$members = writeMembers($class);
	$out .= "\n## `$name`\n\n```php\n" . writeHead($class) . "\n" . implode('', array_map(fn($line) => "\t$line\n", $members)) . "```\n";
}

$out .= "\n## Rules\n\n" . implode('', array_map(fn($rule) => "- `$rule`\n", $rules));
file_put_contents(__DIR__ . '/api.md', $out);
echo 'api.md: ', count($classes) - count($rules), ' classes, ', count($rules), " rules\n";
