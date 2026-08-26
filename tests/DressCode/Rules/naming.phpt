<?php declare(strict_types=1);

/**
 * The naming conventions of the rule catalogue, so that it cannot drift again: the name and the class
 * name say the same thing, the class lies in the directory its namespace names, and every name is built
 * in the shape of its kind: a decision is the noun of the construct, hygiene a sentence of state,
 * a policy the name of the area. The list of exceptions is the point of the test: it keeps visible how
 * many names step outside the shapes.
 */

use DressCode\Config\RuleRegistry;
use DressCode\{ConfigurableRule, RuleInfo};
use Nette\Schema\Elements\{AnyOf, Structure, Type};
use Nette\Schema\Schema;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

$rules = (new RuleRegistry)->rules;
Assert::true(count($rules) > 100);


/** @return list<string>  the words of a camelCase name, `phpdoc`, `multiline` and the like being one */
function splitWords(string $name): array
{
	return preg_split('~(?=[A-Z])~', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}


test('the name is the class name without the suffix, its first letter in lower case', function () use ($rules) {
	foreach ($rules as $name => $class) {
		$slug = substr($name, strpos($name, '/') + 1);
		Assert::same(ucfirst($slug) . 'Rule', substr($class, strrpos($class, '\\') + 1), "name $slug");
	}
});


test('the registry knows a built-in rule by the name its RuleInfo gives', function () use ($rules) {
	foreach ($rules as $name => $class) {
		Assert::same(RuleInfo::of($class)->name, $name, $class);
	}
});


test('the class lies in the directory of its namespace', function () use ($rules) {
	foreach ($rules as $class) {
		$file = new ReflectionClass($class)->getFileName();
		Assert::same(
			str_replace('\\', '/', substr($class, strlen('DressCode\\'))) . '.php',
			str_replace('\\', '/', substr((string) $file, strpos((string) $file, 'src') + 4)),
			$class,
		);
	}
});


test('the name is camelCase under the dresscode vendor', function () use ($rules) {
	foreach (array_keys($rules) as $name) {
		Assert::match('~^dresscode/[a-z][a-z0-9]*([A-Z][a-z0-9]*)*$~', $name);
	}
});


test('no name of a rule or of an option has more than five words', function () use ($rules) {
	$long = [];
	foreach ($rules as $name => $class) {
		$slug = substr($name, strpos($name, '/') + 1);
		if (count(splitWords($slug)) > 5) {
			$long[] = $slug;
		}

		$schema = is_subclass_of($class, ConfigurableRule::class) ? $class::getOptionsSchema() : null;
		foreach ($schema instanceof Structure ? array_keys($schema->getShape()) : [] as $option) {
			if (count(splitWords((string) $option)) > 5) {
				$long[] = "$slug.$option";
			}
		}
	}

	Assert::same([], $long);
});


test('multiline and singleline are one word in a name of a rule and of an option', function () use ($rules) {
	$split = [];
	foreach ($rules as $name => $class) {
		$slug = substr($name, strpos($name, '/') + 1);
		if (preg_match('~[Mm]ultiLine|[Ss]ingleLine~', $slug)) {
			$split[] = $slug;
		}

		$schema = is_subclass_of($class, ConfigurableRule::class) ? $class::getOptionsSchema() : null;
		foreach ($schema instanceof Structure ? array_keys($schema->getShape()) : [] as $option) {
			if (preg_match('~[Mm]ultiLine|[Ss]ingleLine~', (string) $option)) {
				$split[] = "$slug.$option";
			}
		}
	}

	Assert::same([], $split);
});


test('every name is built in the shape of its kind', function () use ($rules) {
	// three kinds, three shapes: a policy is the area it governs and takes the project's own list,
	// hygiene is a sentence of state, a decision is the noun of the construct and takes a value
	$suffixes = ['Spacing', 'BlankLines', 'Indentation', 'Casing', 'Notation', 'Syntax', 'Position', 'Alignment', 'Required'];
	$prefixes = ['no', 'useless', 'single', 'ordered', 'multiline', 'short', 'combined'];
	$infixes = ['Canonical', 'For'];

	// a name says a state or a construct, never the step the fixer takes
	$verbs = ['add', 'convert', 'disallow', 'enforce', 'prefer', 'remove', 'require', 'rewrite'];

	// sentences of state the vocabulary has no pattern for; keep this list short and argued
	$exceptions = [
		'attributeAfterPhpdoc', 'complexStringVariable', 'controlStructureBraces', 'elseifKeyword', 'eofLineEnding', 'explicitAssertion',
		'explicitOperatorPrecedence', 'fullOpeningTag', 'lineEnding', 'nowdocWithoutInterpolation', 'phpdocTrim',
		'propertyPhpdocSingleline', 'propertyVarAnnotation', 'referenceThrowableOnly', 'staticClosure', 'strictCall', 'strictComparison',
		'switchCaseColon', 'symbolicLogicalOperators',
	];

	$shapeOf = function (string $slug, string $class) use ($suffixes, $prefixes, $infixes, $verbs): ?string {
		$words = splitWords($slug);
		// a policy governs an area and takes the list the project brings: what may not be there, what is written instead
		if (in_array($words[0], ['forbidden', 'replaced'], true)) {
			return 'policy';
		} elseif (count($words) > 1 && in_array(implode('', array_slice($words, -2)), $suffixes, true)) {
			return 'hygiene';
		} elseif (count($words) > 1 && (in_array($words[count($words) - 1], $suffixes, true) || in_array($words[0], $prefixes, true))) {
			return 'hygiene';
		} elseif (in_array($words[0], $verbs, true)) {
			return null;
		} elseif (count($words) > 2 && array_intersect(array_slice($words, 1, -1), $infixes)) {
			return 'hygiene';
		}

		// what is left is a bare noun phrase, which only a rule with a value to give it may carry
		return is_subclass_of($class, ConfigurableRule::class) ? 'decision' : null;
	};

	$outside = $redundant = [];
	foreach ($rules as $name => $class) {
		$slug = substr($name, strpos($name, '/') + 1);
		$shape = $shapeOf($slug, $class);
		if ($shape === null && !in_array($slug, $exceptions, strict: true)) {
			$outside[] = $slug;
		} elseif ($shape !== null && in_array($slug, $exceptions, strict: true)) {
			$redundant[] = $slug;
		}
	}

	Assert::same([], $redundant, 'exceptions a shape already covers');
	Assert::same([], $outside, 'names outside the three shapes; add one to $exceptions only with a reason');

	// an exception that no longer names a rule only inflates the list
	$slugs = array_map(fn(string $name) => substr($name, strpos($name, '/') + 1), array_keys($rules));
	Assert::same([], array_values(array_diff($exceptions, $slugs)), 'exceptions naming no rule');
});


test('a place is singular in the key and the value of an option', function () use ($rules) {
	$plurals = [
		'classes', 'functions', 'methods', 'constants', 'properties', 'variables', 'cases', 'parameters', 'arguments', 'arrays',
		'lists', 'arms', 'uses', 'imports', 'traits', 'structures', 'catches', 'returns', 'bodies', 'types',
		'groups', 'comments',
	];

	// the plural is the object of a verb, or what a count or a signature of several places counts
	$exceptions = ['ignoreImports', 'minImports', 'multilineParameters'];

	/** @return list<string>  the strings an option allows; free text is none */
	$collect = function (Schema $schema) use (&$collect): array {
		if ($schema instanceof AnyOf) {
			$set = Closure::bind(fn() => $this->set, $schema, AnyOf::class)();
			$strings = [];
			foreach ($set as $item) {
				if (is_string($item)) {
					$strings[] = $item;
				} elseif ($item instanceof Schema) {
					$strings = [...$strings, ...$collect($item)];
				}
			}

			return $strings;

		} elseif ($schema instanceof Type) {
			$items = Closure::bind(fn() => $this->itemsValue, $schema, Type::class)();
			return $items instanceof Schema ? $collect($items) : [];
		}

		return [];
	};

	/** names the project brings: a list or a map with no value fixed beforehand */
	$isFreeList = function (Schema $schema) use ($collect): bool {
		if (!$schema instanceof Type) {
			return false;
		}

		$type = Closure::bind(fn() => $this->type, $schema, Type::class)();
		return ($type === 'list' || $type === 'array') && $collect($schema) === [];
	};

	$isPlural = fn(string $word): bool => in_array(lcfirst(array_slice(splitWords($word), -1)[0] ?? ''), $plurals, true);

	$offenders = [];
	foreach ($rules as $name => $class) {
		$slug = substr($name, strpos($name, '/') + 1);
		$schema = is_subclass_of($class, ConfigurableRule::class) ? $class::getOptionsSchema() : null;
		foreach ($schema instanceof Structure ? $schema->getShape() : [] as $option => $value) {
			$option = (string) $option;
			if (
				!str_starts_with($option, 'before')
				&& !str_starts_with($option, 'after')
				&& !str_starts_with($option, 'between')
				&& !in_array($option, $exceptions, true)
				&& !$isFreeList($value)
				&& $isPlural($option)
			) {
				$offenders[] = "$slug.$option";
			}

			foreach (array_unique($collect($value)) as $string) {
				if ($isPlural($string)) {
					$offenders[] = "$slug.$option = $string";
				}
			}
		}
	}

	Assert::same([], $offenders);
});
