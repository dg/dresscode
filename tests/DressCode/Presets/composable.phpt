<?php declare(strict_types=1);

/**
 * A built-in preset that is no standard adds to any of them: it is hygiene or what a framework declares, so it must
 * decide nothing about the layout and set no option a standard decides. The content of every one of them is written
 * out, so that a change of it is a change of the file and not of a rule somewhere else.
 */

use DressCode\Config\RuleRegistry;
use DressCode\Preset;
use DressCode\Presets;
use DressCode\Profile;
use DressCode\RuleInfo;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$composable = [
	Presets\Cleanup::class, Presets\Modern::class, Presets\Types::class,
	Presets\PhpDoc::class, Presets\Imports::class, Presets\Classes::class, Presets\Optimizations::class,
	Presets\SymfonyConfigurator::class,
];


function profileOf(string $class): Profile
{
	$preset = new $class;
	assert($preset instanceof Preset);
	return $preset->getProfile();
}


test('a preset added to a standard drags nothing in and brings no style', function () use ($composable) {
	foreach ($composable as $class) {
		$profile = profileOf($class);
		Assert::same([], $profile->presets, $class);
		Assert::true($profile->rules !== [] || $profile->namespaces !== ['functions' => [], 'constants' => []], "$class is empty");
		Assert::null($profile->indent, $class);
		Assert::null($profile->eol, $class);
	}
});


test('the presets added to a standard do not overlap', function () use ($composable) {
	$seen = [];
	foreach ($composable as $class) {
		foreach (profileOf($class)->rules as $rule => $value) {
			Assert::false(isset($seen[$rule]), "$rule is in " . ($seen[$rule] ?? '') . " and in $class");
			$seen[$rule] = $class;
		}
	}

	Assert::same(55, count($seen));
});


test('a preset added to a standard sets no option a standard decides', function () use ($composable) {
	// the preset is laid above the standard, so an option both set would be taken from the standard; a value that is
	// no map decides the whole rule
	$decided = [];
	foreach ([Presets\Per::class, Presets\Psr12::class, Presets\Nette::class, Presets\Symfony::class] as $standard) {
		foreach (profileOf($standard)->rules as $rule => $value) {
			$decided[$rule] = [...$decided[$rule] ?? [], ...(is_array($value) ? array_keys($value) : ($value === true ? [] : ['*']))];
		}
	}

	foreach ($composable as $class) {
		foreach (profileOf($class)->rules as $rule => $value) {
			Assert::true(
				$value === true || (is_array($value) && !in_array('*', $decided[$rule] ?? [], true) && !array_intersect(array_keys($value), $decided[$rule] ?? [])),
				"$rule in $class",
			);
		}
	}
});


test('a preset added to a standard decides nothing about the layout', function () use ($composable) {
	// the areas a standard owns: what such a preset touched there would fight the standard it is added to
	$protected = [
		'~-spacing$~', '~-indentation$~', '~-blank-lines$~', '~-position$~', '~-alignment$~', '~-casing$~',
		'~^multi-line-~', '~^ordered-~', '~^single-~', '~^indentation$~', '~^line-(length|ending)$~',
		'~^trailing-comma$~', '~^heredoc-~', '~^control-structure-braces$~', '~^import-notation$~',
	];
	foreach ($composable as $class) {
		foreach (profileOf($class)->rules as $rule => $value) {
			foreach ($protected as $pattern) {
				Assert::false((bool) preg_match($pattern, $rule), "$rule in $class");
			}
		}
	}
});


test('every rule a preset added to a standard names exists', function () use ($composable) {
	$registry = new RuleRegistry;
	foreach ($composable as $class) {
		foreach (profileOf($class)->rules as $rule => $value) {
			Assert::same('dresscode/' . $rule, RuleInfo::of($registry->resolveRule($rule))->name, "$rule in $class");
		}
	}
});
