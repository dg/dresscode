<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Testing;

use DressCode\{Config, ConfigurationException, Decision, FileResult, Rule};
use DressCode\Config\{ComposerJson, CorePlugin, Layer, LayerKind, PackageDiscovery, ProjectPackages, RunnerFactory, UpgradingFile};
use DressCode\Domains\Map;
use DressCode\Rules\Upgrading\MemberTarget;
use DressCode\Upgrading\{MemberMapGrammar, MemberPattern, MemberPatternKind};
use Nette\Neon\{Exception, Neon};
use Nette\Schema\ValidationException;
use Nette\Utils\FileSystem;
use function count, is_array, is_string, strlen;


/**
 * Checks an upgrading file of a package against the library it is about, installed in the project the test runs in:
 * every section is what the grammars of its maps accept, and what the sections come to at the installed version
 * replaces, in `replacedClasses` and `replacedMembers` and by a static method in `replacedFunctions`, by classes,
 * members and methods that exist. A replacement the file replaces in its turn is followed to its end, the way the
 * passes of a run follow it, and one that leads back to where it started is a problem. What is replaced is not looked
 * up: a library has usually removed it. The keys under `extra.dresscode` of the composer.json of the package are
 * checked too, and so are the forbidden maps, whose entry may be null in a project but must give its sentence in a
 * package. Works from any test framework: the problems come back as sentences, none when the file is sound. What the
 * files make of code written for the old API `runSample()` says.
 */
final class UpgradingTester
{
	/** the longest sentence a forbidden* map may give, the message around it and the backticks not counted */
	private const MaxSentence = 160;

	/** the section of the maps of API changes, which an upgrading file names without it */
	private const Api = 'upgrading.api.';


	/**
	 * @param  string  $root  the project whose vendor holds the library, which the classes are loaded from
	 * @param  list<class-string<Rule>>  $packageRules  the rules the package ships itself, whose maps its files may fill
	 * @return list<string>
	 */
	public static function collectProblems(string $file, string $root, array $packageRules = []): array
	{
		$project = ProjectPackages::read($root);
		$source = basename($file);
		$layer = new Layer(LayerKind::Package, $source);
		try {
			$upgrading = UpgradingFile::fromFile($file, $layer->formatReference());
		} catch (ConfigurationException $e) {
			return [$e->getMessage()];
		}

		$package = $upgrading->package;
		if (!$project->has($package)) {
			return ["The package `$package` that `$source` is about is not installed in `$root`, so nothing of it can be checked; require `$package` in `require-dev`."];
		}

		// every section as written, which a later one must not hide, for what the grammars accept; then what the installed
		// version reaches, for what exists
		$problems = self::collectUnknownKeyProblems($file);
		foreach ($upgrading->getSections() as $version => [$maps, $requirements]) {
			[$mapProblems, $every] = self::validate($maps, $packageRules);
			$sectionProblems = [
				...$mapProblems,
				...self::collectRequirementProblems($requirements, $packageRules),
				...self::collectMissingSentenceProblems($maps),
				...self::collectSentenceProblems($every),
			];
			array_push($problems, ...array_map(fn(string $problem) => "`since $version`: $problem", $sectionProblems));
		}

		$installed = $project->installed[$package]['version'] ?? null;
		$reached = $upgrading->selectFor($installed, $layer);
		return $problems === [] ? self::collectExistenceProblems(self::validate($reached->maps, $packageRules)[1]) : $problems;
	}


	/**
	 * Checks that `extra.dresscode.packages` of the package at the root says exactly which packages its upgrading files
	 * are about, by which `upgrade` offers it to a project lacking it: none of them left out, and none listed that no
	 * file is about.
	 * @return list<string>
	 */
	public static function collectPackageProblems(string $root): array
	{
		$composer = ComposerJson::read("$root/composer.json") ?? [];
		$name = is_string($composer['name'] ?? null) ? $composer['name'] : 'the root package';
		$extra = $composer['extra']['dresscode'] ?? null;
		if (!is_array($extra)) {
			return [];
		}

		try {
			$about = [];
			foreach (PackageDiscovery::findUpgradingFiles($root, $extra, $name) as $file) {
				$package = Neon::decodeFile("$root/$file")['package'] ?? null;
				if (is_string($package)) {
					$about[$package] = $file;
				}
			}
		} catch (ConfigurationException|Exception $e) {
			return [$e->getMessage()];
		}

		$listed = is_array($extra['packages'] ?? null) ? array_values(array_filter($extra['packages'], is_string(...))) : [];
		$problems = [];
		foreach ($about as $package => $file) {
			if (!PackageDiscovery::isAbout($listed, $package)) {
				$problems[] = "Package `$name`: `extra.dresscode.packages` in its `composer.json` does not list `$package`, which `$file` is about.";
			}
		}

		foreach ($listed as $package) {
			if (!array_any(array_keys($about), fn(string $library) => PackageDiscovery::isAbout([$package], $library))) {
				$problems[] = "Package `$name`: `extra.dresscode.packages` in its `composer.json` lists `$package`, which no upgrading file is about.";
			}
		}

		return $problems;
	}


	/**
	 * Runs code written for the old API of the libraries through the rules as the project fixes it: every section
	 * the installed version of a library reaches applies, whatever the constraint of the project allows, and the
	 * types come from its PHPStan, which reads the code from a file, so it is written to `temp/<name>.php` of the root
	 * for the run, PHPStan reading the declarations of that file alone.
	 * @param  string  $root  the project whose vendor holds the libraries and whose packages bring the upgrading files
	 * @param  array<string, mixed>  $decisions  those of the rules the package ships itself, by their sections
	 */
	public static function runSample(string $code, string $root, array $decisions = [], string $name = 'sample'): FileResult
	{
		$decisions['upgrading'] = ['packages' => true, ...$decisions['upgrading'] ?? []];
		$file = "$root/temp/$name.php";
		FileSystem::write($file, $code);
		$installed = array_filter(array_map(
			fn(array $package) => $package['version'],
			ProjectPackages::read($root)->installed,
		));
		$factory = new RunnerFactory;
		try {
			$runner = $factory->createRunner(
				$factory->resolve(
					new Config(paths: ["temp/$name.php"], typeAnalysis: 'phpstan', targets: $installed, decisions: $decisions),
					$root,
				),
				cache: false,
			);
			return $runner->processCode($file, $code);
		} finally {
			FileSystem::delete($file);
		}
	}


	/**
	 * What the maps say of their entries, and the entries as the rules get them, a value NEON read as an entity as code.
	 * @param  array<string, mixed>  $maps  map => its entries
	 * @param  list<class-string<Rule>>  $own  the rules of the package, whose maps are named by the paths of their decisions
	 * @return array{list<string>, array<string, array<string, mixed>>}
	 */
	private static function validate(array $maps, array $own): array
	{
		$domains = [];
		foreach ((new CorePlugin)->getManifest()->decisions as $decision) {
			if ($decision->domain instanceof Map && str_starts_with($decision->path, self::Api)) {
				$domains[substr($decision->path, strlen(self::Api))] = $decision->domain;
			}
		}

		$problems = $normalized = [];
		foreach ($maps as $map => $entries) {
			$domain = $domains[$map] ?? self::findOwnDecision($own, $map)?->domain;
			if (!$domain instanceof Map || $domain->grammar === null) {
				$problems[] = "Unknown map `$map`; an upgrading file holds `"
					. implode('`, `', array_keys($domains))
					. '` and the maps of the rules of its package by the paths of their decisions.';
				continue;
			}

			try {
				$normalized[$map] = $domain->read($entries, $map);
			} catch (ConfigurationException $e) {
				$messages = $e->getPrevious() instanceof ValidationException ? $e->getPrevious()->getMessages() : [$e->getMessage()];
				array_push($problems, ...array_map(fn(string $message) => "`$map`: $message", $messages));
			}
		}

		return [$problems, $normalized];
	}


	/**
	 * What is wrong with the requirements the file turns on: each must be one of the rules of the package, given a word
	 * its domain takes.
	 * @param  array<string, string>  $requirements  path => its value
	 * @param  list<class-string<Rule>>  $own  the rules of the package
	 * @return list<string>
	 */
	private static function collectRequirementProblems(array $requirements, array $own): array
	{
		$problems = [];
		foreach ($requirements as $path => $value) {
			$decision = self::findOwnDecision($own, $path);
			if ($decision === null || !$decision->isRequirement() || $decision->domain instanceof Map) {
				$problems[] = "`$path`: An upgrading file turns on only a requirement of the rules of its package.";
				continue;
			}

			try {
				$decision->domain->accept($value, $path);
			} catch (ConfigurationException $e) {
				$problems[] = $e->getMessage();
			}
		}

		return $problems;
	}


	/**
	 * The decision of the path one of the rules of the package declares.
	 * @param  list<class-string<Rule>>  $own
	 */
	private static function findOwnDecision(array $own, string $path): ?Decision
	{
		foreach ($own as $rule) {
			$decision = array_find($rule::getDecisions(), fn(Decision $decision) => $decision->path === $path);
			if ($decision !== null) {
				return $decision;
			}
		}

		return null;
	}


	/**
	 * The keys under `extra.dresscode` of the composer.json the file belongs to, the nearest one at or above it, that
	 * DressCode does not read.
	 * @return list<string>
	 */
	private static function collectUnknownKeyProblems(string $file): array
	{
		$composerFile = ComposerJson::findFile(dirname($file));
		$composer = $composerFile === null ? null : json_decode((string) file_get_contents($composerFile), associative: true);
		$extra = is_array($composer) && is_array($composer['extra'] ?? null) ? $composer['extra']['dresscode'] ?? null : null;
		if (!is_array($extra)) {
			return [];
		}

		$package = is_string($composer['name'] ?? null) ? "Package `$composer[name]`" : 'The package';
		$known = implode('`, `', PackageDiscovery::Keys);
		$problems = [];
		foreach (array_diff(array_map(strval(...), array_keys($extra)), PackageDiscovery::Keys) as $key) {
			$problems[] = "$package: `extra.dresscode` in its `composer.json` holds the key `$key`, which DressCode does not know; it reads `$known`.";
		}

		return $problems;
	}


	/**
	 * The entries of the forbidden* maps that give no sentence: a project may leave it out, the data of a package
	 * must say what to do instead.
	 * @param  array<string, mixed>  $maps  map => its entries, those of every section
	 * @return list<string>
	 */
	private static function collectMissingSentenceProblems(array $maps): array
	{
		$problems = [];
		foreach (['forbiddenClasses', 'forbiddenMembers', 'forbiddenFunctions', 'forbiddenConstants'] as $map) {
			foreach (is_array($maps[$map] ?? null) ? $maps[$map] : [] as $key => $sentence) {
				if ($sentence === null) {
					$problems[] = "`$map`: The entry `$key` must give a sentence saying what to write instead.";
				}
			}
		}

		return $problems;
	}


	/**
	 * The sentences of the forbidden* maps, which end the message after "… is forbidden:": in lower case unless they
	 * begin with a name, without a period at the end, double quotes or "should", and at most MaxSentence long, backticks
	 * not counted.
	 * @param  array<string, array<string, mixed>>  $maps  map => its entries, those of every section
	 * @return list<string>
	 */
	private static function collectSentenceProblems(array $maps): array
	{
		$problems = [];
		foreach (['forbiddenClasses', 'forbiddenMembers', 'forbiddenFunctions', 'forbiddenConstants'] as $map) {
			foreach ($maps[$map] ?? [] as $key => $sentence) {
				if (!is_string($sentence) || $sentence === MemberMapGrammar::Keep) {
					continue;
				}

				$flaws = array_filter([
					'ends with a period' => str_ends_with($sentence, '.'),
					'holds a double quote' => str_contains($sentence, '"'),
					'begins with a capital letter, but not with a name' => preg_match('~^[A-Z][a-z]*\b(?![\\\\:(])~', $sentence) === 1,
					'says `should`' => preg_match('~\bshould\b~i', $sentence) === 1,
					'is longer than ' . self::MaxSentence . ' characters' => mb_strlen(str_replace('`', '', $sentence)) > self::MaxSentence,
				]);
				foreach (array_keys($flaws) as $flaw) {
					$problems[] = "`$map`: The sentence of `$key` $flaw.";
				}
			}
		}

		return $problems;
	}


	/**
	 * @param  array<string, array<string, mixed>>  $maps  map => its entries, those of the sections the installed version reaches
	 * @return list<string>
	 */
	private static function collectExistenceProblems(array $maps): array
	{
		// lowercased class => the class written instead, and lowercased class::name in its case => [class, name, the entry as written]
		$classes = $members = $written = [];
		foreach ($maps['replacedClasses'] ?? [] as $old => $new) {
			if ($new !== MemberMapGrammar::Keep) {
				$classes[$key = strtolower(ltrim((string) $old, '\\'))] = ltrim($new, '\\');
				$written[$key] = "`$old` is replaced by `$new`";
			}
		}

		$functions = [];
		foreach ($maps['replacedMembers'] ?? [] as $key => $code) {
			if ($code === MemberMapGrammar::Keep) {
				continue;
			}

			$pattern = MemberPattern::fromKey((string) $key);
			$target = MemberTarget::fromCode($code, $pattern);
			$member = strtolower($pattern->class) . "::$pattern->name";
			$written[$member] = "`$key` is replaced by `$code`";
			if ($target->global) {
				$functions[$member] = $target->name;
			} else {
				$members[$member] = [$target->class ?? $pattern->class, $target->name, $pattern->kind];
			}
		}

		$problems = [];
		foreach ($classes as $old => $new) {
			// a replacement the file replaces in its turn is followed, as the passes of a run follow it
			for ($steps = count($classes); isset($classes[strtolower($new)]) && strtolower($new) !== $old && $steps > 0; $steps--) {
				$new = $classes[strtolower($new)];
			}

			if (strtolower($new) === $old) {
				$problems[] = "`replacedClasses`: $written[$old], whose replacements lead back to `$new`.";
			} elseif (isset($classes[strtolower($new)])) {
				$problems[] = "`replacedClasses`: $written[$old], whose replacements go round in a circle.";
			} elseif (!self::isClass($new)) {
				$problems[] = "`replacedClasses`: $written[$old], which does not exist"
					. ($new === $classes[$old] ? '.' : ", and neither does `$new`, where it leads.");
			}
		}

		foreach ($functions as $member => $function) {
			if (!function_exists($function)) {
				$problems[] = "`replacedMembers`: $written[$member], which does not exist.";
			}
		}

		// a function of a library is declared by a file loaded with its first use, so only a static method is checked
		foreach ($maps['replacedFunctions'] ?? [] as $old => $new) {
			if (!is_string($new) || !str_contains($new, '::')) {
				continue;
			}

			[$class, $method] = explode('::', ltrim($new, '\\'), 2);
			if (!self::hasMember($class, $method, MemberPatternKind::Method)) {
				$problems[] = "`replacedFunctions`: `$old` is replaced by `$new`, which does not exist.";
			}
		}

		foreach ($members as $member => [$class, $name, $kind]) {
			$first = "$class::$name";
			for (
				$steps = count($members);
				isset($members[$next = strtolower($class) . "::$name"]) && $next !== $member && $steps > 0;
				$steps--
			) {
				[$class, $name] = $members[$next];
			}

			$renamed = $classes[strtolower($class)] ?? $class; // a member of a class the file renames too
			if ($next === $member) {
				$problems[] = "`replacedMembers`: $written[$member], whose replacements lead back to `$class::$name`.";
			} elseif (isset($members[$next])) {
				$problems[] = "`replacedMembers`: $written[$member], whose replacements go round in a circle.";
			} elseif (isset($functions[$next])) {
				continue; // checked as a function
			} elseif (!self::hasMember($renamed, $name, $kind)) {
				$problems[] = "`replacedMembers`: $written[$member], which does not exist"
					. ($first === "$renamed::$name" ? '.' : ", and neither does `$renamed::$name`, where it leads.");
			}
		}

		foreach ($maps['attributeForMember'] ?? [] as $key => $attribute) {
			if ($attribute !== MemberMapGrammar::Keep && !self::isClass(ltrim((string) strstr("$attribute(", '(', true), '\\'))) {
				$problems[] = "`attributeForMember`: `$key` is replaced by the attribute `$attribute`, which does not exist.";
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
	private static function hasMember(string $class, string $name, MemberPatternKind $kind): bool
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
			MemberPatternKind::Property => $reflection->hasProperty($name),
			MemberPatternKind::Method => $reflection->hasMethod($name) || self::hasMagicMethod($reflection, $name),
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
			if (preg_match(
				'~@method\s+(?:static\s+)?(?:[^\s(]+\s+)?' . preg_quote($name, '~') . '\s*\(~i',
				(string) $class->getDocComment(),
			)) {
				return true;
			}
		}

		return false;
	}
}
