<?php declare(strict_types=1);

/**
 * What a group may carry, so that asking for one never fights the standard the project chose, and what is left
 * outside every group and every standard, which is the list of rules a project turns on by name.
 */

use DressCode\Config\RuleRegistry;
use DressCode\{Preset, Profile, RuleGroup, RuleInfo};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$registry = new RuleRegistry;

function profileOf(string $class): Profile
{
	$preset = new $class;
	assert($preset instanceof Preset);
	return $preset->getProfile();
}


test('a group decides nothing about the layout', function () use ($registry) {
	// the areas a standard owns: a rule of them in a group would fight the standard the project chose, because
	// a group brings the defaults of the rule and knows nothing of the layout around it
	$protected = [
		'~Spacing$~', '~Indentation$~', '~^blankLines$~', '~Position$~', '~Alignment$~', '~Casing$~',
		'~^multiline~', '~^ordered~', '~^single~', '~^indentation$~', '~^line(Length|Ending)$~',
		'~^trailingComma$~', '~^heredoc~', '~^controlStructureBraces$~', '~^importNotation$~',
	];
	$slugs = array_map(fn(string $name) => substr($name, 10), array_keys($registry->rules));
	foreach ($protected as $pattern) {
		Assert::true((bool) preg_grep($pattern, $slugs), "$pattern matches no rule");
	}

	foreach ($registry->rules as $name => $class) {
		$group = RuleInfo::of($class)->group;
		foreach ($group === null ? [] : $protected as $pattern) {
			Assert::false((bool) preg_match($pattern, substr($name, 10)), "$name in {$group?->value}");
		}
	}
});


test('a group carries every rule of its kind, whatever brings the rule in', function () use ($registry) {
	// the group is the label of the rule, so a rule of a plugin joins the group the project already asks for
	$byGroup = [];
	foreach ($registry->rules as $name => $class) {
		$group = RuleInfo::of($class)->group;
		if ($group !== null) {
			$byGroup[$group->value][] = $name;
		}
	}

	Assert::same([], array_diff(array_column(RuleGroup::cases(), 'value'), array_keys($byGroup)), 'a group without a rule');
	foreach ($byGroup as $group => $names) {
		Assert::true(count($names) > 2, "$group has too few rules to be a group");
	}
});


test('a rule is in a group, chosen by a standard, or turned on by name on purpose', function () use ($registry) {
	// a rule outside every group and every preset is one a project asks for itself, and the reason is here
	$onRequest = [
		'earlyExit', // the shape of a function body, which no standard prescribes
		'finalInternalClass', // what a project does with its own internals
		'groupImport', // whether the imports of a namespace stand under one prefix, which is the project's own decision
		'lineLength', // a value of the style, which a standard sets where it has one
		'multilineImport', // the shape of a group use, which a project chooses together with writing one
		'nameFallback', // the decision needs an option, and a group carries none
		'noUnlistedNamespacedDeclarations', // the guard of a certain name resolution, which turns itself on
		'readonlyForUnwrittenProperty', 'readonlyClassForReadonlyMembers', 'readonlyForAnnotation',
		'sensitiveParameterRequired', // the list of what is sensitive is the project's
		'singleLevelIndentation', // a measure of the shape of a body, not of its layout
		'staticForMethodWithoutThis', // how a class is built, which no group decides for it
		'staticClosure', // a decision of the project about what its code means
		// the rules of maps: a group turns them on through the upgrading files of its intent, a project by name for its own list
		'attributeForAnnotation', 'attributeForMember', 'forbiddenClasses', 'forbiddenFunctions', 'forbiddenMembers',
		'replacedCalls', 'replacedClasses', 'replacedFunctions', 'replacedMembers',
	];

	$chosen = [];
	foreach ($registry->presets as $class) {
		foreach (profileOf($class)->rules as $rule => $value) {
			$chosen[$registry->resolveRule($rule)] = true;
		}
	}

	$left = [];
	foreach ($registry->rules as $name => $class) {
		if (RuleInfo::of($class)->group === null && !isset($chosen[$class])) {
			$left[] = substr($name, 10);
		}
	}

	sort($left);
	$expected = $onRequest;
	sort($expected);
	Assert::same($expected, $left, 'a rule nothing turns on; give it a group, a standard, or a reason here');
});


test('every rule a preset names exists, and only the standards carry a style', function () use ($registry) {
	foreach ($registry->presets as $name => $class) {
		$profile = profileOf($class);
		foreach ($profile->rules as $rule => $value) {
			Assert::same('dresscode/' . $rule, RuleInfo::of($registry->resolveRule($rule))->name, "$rule in $name");
		}

		if (!in_array($name, ['dresscode/perCs', 'dresscode/psr12', 'dresscode/nette', 'dresscode/symfony'], true)) {
			Assert::null($profile->indent, $name);
			Assert::null($profile->lineEnding, $name);
			Assert::null($profile->lineLength, $name);
		}
	}
});
