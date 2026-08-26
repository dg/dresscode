<?php declare(strict_types=1);

/**
 * The naming conventions of the classes of the rules, so that they cannot drift again: the class lies in the directory
 * its namespace names, and its name, without the suffix and with its first letter in lower case, is built in the shape
 * of its kind: a decision is the noun of the construct, hygiene a sentence of state, a policy the name of the area.
 * The list of exceptions is the point of the test: it keeps visible how many names step outside the shapes.
 */

use DressCode\Config\{Catalogue, PluginRegistry};
use DressCode\Decision;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

$rules = [];
foreach ((new PluginRegistry)->rules as $class) {
	$rules[lcfirst(substr($class, strrpos($class, '\\') + 1, -strlen('Rule')))] = $class;
}

Assert::true(count($rules) > 100);


/** @return list<string>  the words of a camelCase name, `phpdoc`, `multiline` and the like being one */
function splitWords(string $name): array
{
	return preg_split('~(?=[A-Z])~', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}


test('the class ends with Rule and lies in the directory of its namespace', function () use ($rules) {
	foreach ($rules as $class) {
		Assert::match('~Rule$~', $class);
		$file = new ReflectionClass($class)->getFileName();
		Assert::same(
			str_replace('\\', '/', substr($class, strlen('DressCode\\'))) . '.php',
			str_replace('\\', '/', substr((string) $file, strpos((string) $file, 'src') + 4)),
			$class,
		);
	}
});


test('no name of a rule has more than five words', function () use ($rules) {
	Assert::same([], array_values(array_filter(array_keys($rules), fn(string $slug) => count(splitWords($slug)) > 5)));
});


test('multiline and singleline are one word in a name of a rule', function () use ($rules) {
	Assert::same([], array_values(array_filter(array_keys($rules), fn(string $slug) => (bool) preg_match('~[Mm]ultiLine|[Ss]ingleLine~', $slug))));
});


test('every name is built in the shape of its kind', function () use ($rules) {
	// three kinds, three shapes: a policy is the area it governs and takes the project's own list,
	// hygiene is a sentence of state, a decision is the noun of the construct and takes a value
	$suffixes = ['Spacing', 'BlankLines', 'Indentation', 'Casing', 'Notation', 'Required', 'Position', 'Alignment'];
	$prefixes = ['no', 'useless', 'multiline'];
	$infixes = ['Canonical', 'For'];

	// a name says a state or a construct, never the step the fixer takes
	$verbs = ['add', 'convert', 'disallow', 'enforce', 'prefer', 'remove', 'require', 'rewrite'];

	/** @param  list<Decision>  $decisions */
	$shapeOf = function (string $slug, array $decisions) use ($suffixes, $prefixes, $infixes, $verbs): ?string {
		$words = splitWords($slug);
		// a policy governs an area and takes the list the project brings: what may not be there, what is written instead
		if (in_array($words[0], ['forbidden', 'replaced'], true)) {
			return 'policy';
		} elseif (in_array($words[0], $verbs, true)) {
			return null;
		} elseif (count($words) > 1 && in_array(implode('', array_slice($words, -2)), $suffixes, true)) {
			return 'hygiene';
		} elseif (count($words) > 1 && (in_array($words[count($words) - 1], $suffixes, true) || in_array($words[0], $prefixes, true))) {
			return 'hygiene';
		} elseif (count($words) > 2 && array_intersect(array_slice($words, 1, -1), $infixes)) {
			return 'hygiene';
		}

		// what is left is a bare noun phrase, which only a rule with a value to give it may carry:
		// a requirement whose domain holds more than one value, `keep` aside
		foreach ($decisions as $decision) {
			if ($decision->isRequirement() && $decision->domain->findSoleValue() === null) {
				return 'decision';
			}
		}

		return null;
	};

	// names kept as they are by decision, though they step outside the shapes
	$exceptions = [
		'modifierOrder', // named after its decision, `classes.modifierOrder`, whose one value is `canonical`
		'finalLineEndings', // the count is fixed at one, so there is no value to give
		'lineLength', // the example of a decision: the noun of the construct, the width its value
		'redundantArguments', // named after its decision, `cleanup.redundantArguments`
		'phpdocAboveAttributes', // says the state, in the words of the two constructs
		'singlelinePropertyPhpdoc', // says the state, in the words of the two constructs
	];

	$outside = [];
	foreach ($rules as $slug => $class) {
		if ($shapeOf($slug, Catalogue::collectDecisions($class)) === null) {
			$outside[] = $slug;
		}
	}

	Assert::same($exceptions, $outside, 'names outside the three shapes');
});
