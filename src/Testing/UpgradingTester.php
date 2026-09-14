<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Testing;

use DressCode\Analyses\MemberKind;
use DressCode\{Config, ConfigurableRule, ConfigurationException, FileResult, Rule};
use DressCode\Config\{PackageProfiles, ProjectPackages, RuleRegistry, RunnerFactory};
use DressCode\Rules\Upgrading\{MemberMaps, MemberPattern, MemberTarget};
use Nette\Schema\{Processor, ValidationException};
use Nette\Utils\FileSystem;
use function count;


/**
 * Checks an upgrading file of a package against the library it is about, installed in the project the test runs in:
 * every section is what the rules it names accept, and what the sections come to at the installed version replaces,
 * in replaced-classes and replaced-members, by classes, members and functions that exist. A replacement the file replaces in its turn is followed to its end,
 * the way the passes of a run follow it, and one that leads back to where it started is a problem. What is replaced
 * is not looked up: a library has usually removed it. Works from any test framework: the problems come back as
 * sentences, none when the file is sound. What the files make of code written for the old API runSample() says.
 */
final class UpgradingTester
{
	/**
	 * @param  string  $root  the project whose vendor holds the library, which the classes are loaded from
	 * @param  list<class-string<Rule>>  $rules  the rules the package ships itself, which its files may name
	 * @return list<string>
	 */
	public static function check(string $file, string $root, array $rules = []): array
	{
		$project = ProjectPackages::read($root);
		$source = basename($file);
		try {
			// every section, for what the rules accept; then the ones the installed version reaches, for what exists
			$whole = PackageProfiles::readFile($file, $source, $project);
			if ($whole === null) {
				return ["Upgrading file $source: The package it is about is not installed in $root, so nothing of it can be checked."];
			}

			$all = PackageProfiles::readFile($file, $source, $project->withTargets([$whole->package => '99999']));
			$installed = $project->installed[$whole->package]['version'] ?? null;
			$reached = $installed === null
				? $all
				: PackageProfiles::readFile($file, $source, $project->withTargets([$whole->package => $installed]));

		} catch (ConfigurationException $e) {
			return [$e->getMessage()];
		}

		assert($all !== null && $reached !== null);
		[$problems] = self::validate($all->profile->rules, $rules);
		return $problems === [] ? self::checkExistence(self::validate($reached->profile->rules, $rules)[1]) : $problems;
	}


	/**
	 * Runs code written for the old API of the libraries through the rules as the project fixes it: every section
	 * the installed version of a library reaches applies, whatever the constraint of the project allows, and the
	 * types come from its PHPStan, which reads the code from a file, so it is written to `temp/<name>.php` of the root.
	 * @param  string  $root  the project whose vendor holds the libraries and whose packages bring the upgrading files
	 * @param  list<string>  $rules  names of the rules the code runs through
	 */
	public static function runSample(string $code, string $root, array $rules, string $name = 'sample'): FileResult
	{
		$file = "$root/temp/$name.php";
		FileSystem::write($file, $code);
		$installed = array_filter(array_map(
			fn(array $package) => $package['version'],
			ProjectPackages::read($root)->installed,
		));
		$runner = (new RunnerFactory)->createRunner(
			new Config(paths: ['temp'], rules: array_fill_keys($rules, true), types: 'phpstan', packages: $installed),
			$root,
			cache: false,
		);
		return $runner->processFile($file, $code);
	}


	/**
	 * What the rules say of their options, and the options as the rules get them, a value NEON read as an entity as code.
	 * @param  array<string, mixed>  $rules  rule → its options
	 * @param  list<class-string<Rule>>  $own  the rules of the package, known by their names besides those of DressCode
	 * @return array{list<string>, array<string, array<string, mixed>>}
	 */
	private static function validate(array $rules, array $own): array
	{
		$registry = new RuleRegistry;
		array_walk($own, $registry->registerRule(...));
		$problems = $normalized = [];
		foreach ($rules as $rule => $options) {
			try {
				$class = $registry->resolveRule($rule);
			} catch (ConfigurationException $e) {
				$problems[] = $e->getMessage();
				continue;
			}

			if (!is_subclass_of($class, ConfigurableRule::class)) {
				$problems[] = "Rule $rule takes no options.";
				continue;
			}

			try {
				$normalized[$rule] = (array) (new Processor)->process($class::getOptionsSchema(), $options);
			} catch (ValidationException $e) {
				array_push($problems, ...array_map(fn(string $message) => "$rule: $message", $e->getMessages()));
			}
		}

		return [$problems, $normalized];
	}


	/**
	 * @param  array<string, array<string, mixed>>  $rules  rule → its options, those of the sections the installed version reaches
	 * @return list<string>
	 */
	private static function checkExistence(array $rules): array
	{
		// lowercased class → the class written instead, and lowercased class::name in its case → [class, name, the entry as written]
		$classes = $members = $written = [];
		foreach ($rules['replaced-classes'] ?? [] as $old => $new) {
			if ($new !== MemberMaps::Keep) {
				$classes[$key = strtolower(ltrim((string) $old, '\\'))] = ltrim($new, '\\');
				$written[$key] = "$old is replaced by $new";
			}
		}

		$functions = [];
		foreach ($rules['replaced-members'] ?? [] as $key => $code) {
			if ($code === MemberMaps::Keep) {
				continue;
			}

			$pattern = MemberPattern::fromKey((string) $key);
			$target = MemberTarget::fromCode($code, $pattern);
			$member = strtolower($pattern->class) . "::$pattern->name";
			$written[$member] = "$key is replaced by $code";
			if ($target->isFunction) {
				$functions[$member] = $target->name;
			} else {
				$members[$member] = [$target->class ?? $pattern->class, $target->name, $pattern->kind];
			}
		}

		$problems = [];
		foreach ($classes as $old => $new) {
			// a replacement the file replaces in its turn is followed, as the passes of a run follow it
			for ($steps = count($classes); isset($classes[strtolower($new)]) && $steps > 0; $steps--) {
				$new = $classes[strtolower($new)];
			}

			if (strtolower($new) === $old || isset($classes[strtolower($new)])) {
				$problems[] = "replaced-classes: $written[$old], which leads back to it.";
			} elseif (!self::isClass($new)) {
				$problems[] = "replaced-classes: $written[$old], which does not exist" . ($new === $classes[$old] ? '.' : ", and neither does $new, where it leads.");
			}
		}

		foreach ($functions as $member => $function) {
			if (!function_exists($function)) {
				$problems[] = "replaced-members: $written[$member], which does not exist.";
			}
		}

		foreach ($members as $member => [$class, $name, $kind]) {
			$first = "$class::$name";
			for ($steps = count($members); isset($members[$next = strtolower($class) . "::$name"]) && $steps > 0; $steps--) {
				[$class, $name] = $members[$next];
			}

			$class = $classes[strtolower($class)] ?? $class; // a member of a class the file renames too
			if ($next === $member || isset($members[$next])) {
				$problems[] = "replaced-members: $written[$member], which leads back to it.";
			} elseif (isset($functions[$next])) {
				continue; // checked as a function
			} elseif (!self::hasMember($class, $name, $kind)) {
				$problems[] = "replaced-members: $written[$member], which does not exist" . ($first === "$class::$name" ? '.' : ", and neither does $class::$name, where it leads.");
			}
		}

		return $problems;
	}


	/**
	 * Whether the class exists; one that cannot be loaded, its parent standing in a package that is not installed,
	 * exists all the same.
	 * @phpstan-assert-if-true class-string $class
	 */
	private static function isClass(string $class): bool
	{
		try {
			return class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class);
		} catch (\Error) {
			return true;
		}
	}


	/**
	 * Whether the class has the member of the kind a key says: a property, a method, or without a kind a constant or
	 * a method, a magic method its phpDoc declares with `@method` among them. A class that cannot be loaded is taken
	 * at its word.
	 */
	private static function hasMember(string $class, string $name, ?MemberKind $kind): bool
	{
		try {
			if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
				return false;
			}
		} catch (\Error) {
			return true;
		}

		$reflection = new \ReflectionClass($class);
		return match ($kind) {
			MemberKind::Property => $reflection->hasProperty($name),
			MemberKind::Method => $reflection->hasMethod($name) || self::hasMagicMethod($reflection, $name),
			default => $reflection->hasConstant($name) || $reflection->hasMethod($name) || self::hasMagicMethod($reflection, $name),
		};
	}


	/**
	 * Whether the phpDoc of the class, or of a class it extends, declares the method with `@method`.
	 * @param  \ReflectionClass<object>  $reflection
	 */
	private static function hasMagicMethod(\ReflectionClass $reflection, string $name): bool
	{
		for ($class = $reflection; $class !== false; $class = $class->getParentClass()) {
			if (preg_match('~@method\s+(?:static\s+)?(?:[^\s(]+\s+)?' . preg_quote($name, '~') . '\s*\(~i', (string) $class->getDocComment())) {
				return true;
			}
		}

		return false;
	}
}
