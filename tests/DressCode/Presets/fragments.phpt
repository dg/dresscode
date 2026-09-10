<?php declare(strict_types=1);

/**
 * A fragment is a preset of hygiene alone: it composes with any standard, so it must decide nothing about
 * the layout and carry no value. The content of every one of them is written out, so that a change of it
 * is a change of the file and not of a rule somewhere else.
 */

use DressCode\Config;
use DressCode\Config\RuleRegistry;
use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;
use DressCode\Presets;
use DressCode\RuleInfo;
use Tester\Assert;


require __DIR__ . '/../../bootstrap.php';


$fragments = [
	Presets\Cleanup::class, Presets\Modern::class, Presets\Types::class,
	Presets\PhpDoc::class, Presets\Imports::class, Presets\Classes::class,
];
$context = new PresetContext(Config::DefaultPhpVersion);


/** @return array<string, mixed> */
function rulesOf(string $class, PresetContext $context): array
{
	$preset = new $class;
	assert($preset instanceof Preset);
	return $preset->getRules($context);
}


test('a fragment says of itself that it is one, and drags nothing in', function () use ($fragments, $context) {
	foreach ($fragments as $class) {
		Assert::true(PresetInfo::of($class)->fragment, $class);
		Assert::same([], rulesOf($class, $context) === [] ? ['empty'] : (new $class)->getParents(), $class);
		Assert::null(PresetInfo::of($class)->indent, $class);
		Assert::null(PresetInfo::of($class)->eol, $class);
	}

	// a standard is not a fragment: it takes a stand on the layout and brings a style
	foreach ([Presets\Per::class, Presets\Psr12::class, Presets\Nette::class, Presets\Symfony::class] as $class) {
		Assert::false(PresetInfo::of($class)->fragment, $class);
	}
});


test('the fragments do not overlap', function () use ($fragments, $context) {
	$seen = [];
	foreach ($fragments as $class) {
		foreach (rulesOf($class, $context) as $rule => $value) {
			Assert::false(isset($seen[$rule]), "$rule is in " . ($seen[$rule] ?? '') . " and in $class");
			$seen[$rule] = $class;
		}
	}

	Assert::same(50, count($seen));
});


test('a fragment enables a rule, it does not configure it', function () use ($fragments, $context) {
	foreach ($fragments as $class) {
		foreach (rulesOf($class, $context) as $rule => $value) {
			Assert::true($value === true, "$rule in $class");
		}
	}
});


test('a fragment decides nothing about the layout', function () use ($fragments, $context) {
	// the areas a standard owns: what a fragment touched there would fight the standard it is composed with
	$protected = [
		'~-spacing$~', '~-indentation$~', '~-blank-lines$~', '~-position$~', '~-alignment$~', '~-casing$~',
		'~^multi-line-~', '~^ordered-~', '~^single-~', '~^indentation$~', '~^line-(length|ending)$~',
		'~^trailing-comma$~', '~^heredoc-~', '~^control-structure-braces$~', '~^import-notation$~',
	];
	foreach ($fragments as $class) {
		foreach (rulesOf($class, $context) as $rule => $value) {
			foreach ($protected as $pattern) {
				Assert::false((bool) preg_match($pattern, $rule), "$rule in $class");
			}
		}
	}
});


test('every rule a fragment names exists', function () use ($fragments, $context) {
	$registry = new RuleRegistry;
	foreach ($fragments as $class) {
		foreach (rulesOf($class, $context) as $rule => $value) {
			Assert::same('dresscode/' . $rule, RuleInfo::of($registry->resolveRule($rule))->name, "$rule in $class");
		}
	}
});
