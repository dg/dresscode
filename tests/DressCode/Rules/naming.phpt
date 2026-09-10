<?php declare(strict_types=1);

/**
 * The naming conventions of the rule catalogue, so that it cannot drift again: the slug and the class
 * name say the same thing, the class lies in the directory its namespace names, and every slug is built
 * in the shape of its kind: a decision is the noun of the construct, hygiene a sentence of state,
 * a policy the name of the area. The list of exceptions is the point of the test: it keeps visible how
 * many names step outside the shapes.
 */

use DressCode\Config\RuleRegistry;
use DressCode\ConfigurableRule;
use Tester\Assert;


require __DIR__ . '/../../bootstrap.php';

$rules = (new RuleRegistry)->getRules();
Assert::true(count($rules) > 100);


test('the slug is the class name in kebab-case', function () use ($rules) {
	$compounds = ['phpdoc' => 'PhpDoc', 'eof' => 'Eof', 'inheritdoc' => 'InheritDoc', 'elseif' => 'Elseif'];
	foreach ($rules as $name => $class) {
		$slug = substr($name, strpos($name, '/') + 1);
		$expected = implode('', array_map(
			fn(string $word) => $compounds[$word] ?? ucfirst($word),
			explode('-', $slug),
		)) . 'Rule';
		Assert::same($expected, substr($class, strrpos($class, '\\') + 1), "slug $slug");
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


test('the slug is kebab-case under the dresscode vendor', function () use ($rules) {
	foreach ($rules as $name => $class) {
		Assert::match('~^dresscode/[a-z0-9]+(-[a-z0-9]+)*$~', $name);
	}
});


test('every slug is built in the shape of its kind', function () use ($rules) {
	// three kinds, three shapes: a policy is the area it governs and takes the project's own list,
	// hygiene is a sentence of state, a decision is the noun of the construct and takes a value
	$suffixes = ['-spacing', '-blank-lines', '-indentation', '-casing', '-notation', '-syntax', '-position', '-alignment', '-operator', '-required'];
	$prefixes = ['no-', 'useless-', 'single-', 'ordered-', 'multi-line-', 'short-', 'combined-'];
	$infixes = ['-canonical-'];

	// a name says a state or a construct, never the step the fixer takes
	$verbs = ['add-', 'convert-', 'disallow-', 'enforce-', 'prefer-', 'remove-', 'require-', 'rewrite-'];

	// sentences of state the vocabulary has no pattern for; keep this list short and argued
	$exceptions = [
		'annotation-name', 'attribute-after-phpdoc', 'complex-string-variable', 'control-structure-braces',
		'elseif-keyword', 'eof-newline', 'explicit-assertion', 'explicit-operator-precedence',
		'full-opening-tag', 'line-ending', 'nullable-type-for-default-null', 'nowdoc-without-interpolation',
		'phpdoc-null-last', 'phpdoc-trim', 'property-phpdoc-single-line', 'property-var-annotation',
		'reference-throwable-only', 'self-for-current-class', 'static-closure', 'strict-call',
		'strict-comparison', 'switch-case-colon', 'symbolic-logical-operators', 'ternary-for-simple-branch',
		'use-from-same-namespace',
	];

	$shapeOf = function (string $slug, string $class) use ($suffixes, $prefixes, $infixes, $verbs): ?string {
		if (str_starts_with($slug, 'forbidden-')) {
			return 'policy';
		}

		foreach ($suffixes as $suffix) {
			if (str_ends_with($slug, $suffix)) {
				return 'hygiene';
			}
		}

		foreach ($prefixes as $prefix) {
			if (str_starts_with($slug, $prefix)) {
				return 'hygiene';
			}
		}

		foreach ($verbs as $verb) {
			if (str_starts_with($slug, $verb)) {
				return null;
			}
		}

		foreach ($infixes as $infix) {
			if (str_contains($slug, $infix)) {
				return 'hygiene';
			}
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
	Assert::same([], $outside, 'slugs outside the three shapes; add one to $exceptions only with a reason');

	// an exception that no longer names a rule only inflates the list
	$slugs = array_map(fn(string $name) => substr($name, strpos($name, '/') + 1), array_keys($rules));
	Assert::same([], array_values(array_diff($exceptions, $slugs)), 'exceptions naming no rule');
});
