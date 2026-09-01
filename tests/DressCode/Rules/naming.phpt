<?php declare(strict_types=1);

/**
 * The naming conventions of the rule catalogue, so that it cannot drift again: the slug and the class
 * name say the same thing, the class lies in the directory its namespace names, and every slug is built
 * from the vocabulary. The list of exceptions is the point of the test: it keeps visible how many names
 * step outside the rules.
 */

use DressCode\Config\RuleRegistry;
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
		$file = (new ReflectionClass($class))->getFileName();
		Assert::same(
			str_replace('\\', '/', substr($class, strlen('DressCode\\'))) . '.php',
			str_replace('\\', '/', substr((string) $file, strpos((string) $file, 'src') + 4 + strlen('DressCode/'))),
			$class,
		);
	}
});


test('the slug is kebab-case under the dresscode vendor', function () use ($rules) {
	foreach ($rules as $name => $class) {
		Assert::match('~^dresscode/[a-z0-9]+(-[a-z0-9]+)*$~', $name);
	}
});


test('every slug follows the vocabulary', function () use ($rules) {
	// a name says what the rule enforces: a forbidden construct, a useless one, or the wanted shape
	$suffixes = ['-spacing', '-blank-lines', '-indentation', '-casing', '-notation', '-syntax', '-position', '-alignment', '-operator', '-required'];
	$prefixes = ['no-', 'useless-', 'single-', 'ordered-', 'multi-line-', 'short-', 'combined-', 'forbidden-'];
	$infixes = ['-canonical-'];

	// names that stand outside the vocabulary on purpose; keep this list short and argued
	$exceptions = [
		'annotation-name', 'arrow-function', 'attribute-after-phpdoc', 'commented-out-function',
		'complex-string-variable', 'control-structure-braces', 'early-exit', 'elseif-keyword', 'eof-newline', 'explicit-operator-precedence',
		'explicit-assertion', 'fall-through-comment', 'final-internal-class', 'full-opening-tag',
		'global-imports', 'line-ending', 'line-length', 'modern-class-name-reference',
		'new-argument-parentheses', 'nullable-type-for-default-null', 'nowdoc-without-interpolation',
		'numeric-literal-separator', 'phpdoc-null-last', 'phpdoc-trim',
		'property-phpdoc-single-line', 'property-var-annotation', 'reference-throwable-only',
		'reference-used-names-only', 'self-for-current-class', 'static-closure', 'strict-call', 'strict-comparison',
		'switch-case-colon', 'symbolic-logical-operators', 'ternary-for-simple-branch', 'trailing-comma',
		'union-type-format', 'unused-imports', 'use-from-same-namespace',
	];

	$matches = function (string $slug) use ($suffixes, $prefixes, $infixes): bool {
		foreach ($suffixes as $suffix) {
			if (str_ends_with($slug, $suffix)) {
				return true;
			}
		}

		foreach ($prefixes as $prefix) {
			if (str_starts_with($slug, $prefix)) {
				return true;
			}
		}

		foreach ($infixes as $infix) {
			if (str_contains($slug, $infix)) {
				return true;
			}
		}

		return false;
	};

	$outside = $redundant = [];
	foreach ($rules as $name => $class) {
		$slug = substr($name, strpos($name, '/') + 1);
		if (!$matches($slug) && !in_array($slug, $exceptions, strict: true)) {
			$outside[] = $slug;
		}
	}

	// an exception the vocabulary already covers only inflates the list
	foreach ($exceptions as $slug) {
		if ($matches($slug)) {
			$redundant[] = $slug;
		}
	}

	Assert::same([], $redundant, 'exceptions the vocabulary already covers');

	Assert::same([], $outside, 'slugs outside the vocabulary; add one to $exceptions only with a reason');

	// an exception that no longer names a rule, or one the vocabulary already covers, only inflates the list
	$slugs = array_map(fn(string $name) => substr($name, strpos($name, '/') + 1), array_keys($rules));
	Assert::same([], array_values(array_diff($exceptions, $slugs)), 'exceptions naming no rule');
});
