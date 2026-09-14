<?php declare(strict_types=1);

/**
 * What a group may carry, so that asking for one never fights the standard the project chose.
 */

use DressCode\Config\RuleRegistry;
use DressCode\Group;
use DressCode\Preset;
use DressCode\Profile;
use DressCode\RuleInfo;
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
		'~-spacing$~', '~-indentation$~', '~-blank-lines$~', '~-position$~', '~-alignment$~', '~-casing$~',
		'~^multi-line-~', '~^ordered-~', '~^single-~', '~^indentation$~', '~^line-(length|ending)$~',
		'~^trailing-comma$~', '~^heredoc-~', '~^control-structure-braces$~', '~^import-notation$~',
	];
	foreach ($registry->getRules() as $name => $class) {
		$group = RuleInfo::of($class)->group;
		foreach ($group === null ? [] : $protected as $pattern) {
			Assert::false((bool) preg_match($pattern, substr($name, 10)), "$name in {$group?->value}");
		}
	}
});


test('a group carries every rule of its kind, whatever brings the rule in', function () use ($registry) {
	// the group is the label of the rule, so a rule of an extension joins the group the project already asks for
	$byGroup = [];
	foreach ($registry->getRules() as $name => $class) {
		$group = RuleInfo::of($class)->group;
		if ($group !== null) {
			$byGroup[$group->value][] = $name;
		}
	}

	Assert::same([], array_diff(array_column(Group::cases(), 'value'), array_keys($byGroup)), 'a group without a rule');
	foreach ($byGroup as $group => $names) {
		Assert::true(count($names) > 2, "$group has too few rules to be a group");
	}
});


test('every rule a preset names exists, and only the standards carry a style', function () use ($registry) {
	foreach ($registry->getPresets() as $name => $class) {
		$profile = profileOf($class);
		foreach ($profile->rules as $rule => $value) {
			Assert::same('dresscode/' . $rule, RuleInfo::of($registry->resolveRule($rule))->name, "$rule in $name");
		}

		// nette-style is the layout of the standard that composes it, so it carries a style; nothing else may
		if (
			$name !== 'dresscode/nette-style'
			&& !in_array($name, ['dresscode/per', 'dresscode/psr12', 'dresscode/nette', 'dresscode/symfony'], true)
		) {
			Assert::null($profile->indent, $name);
			Assert::null($profile->eol, $name);
			Assert::null($profile->lineLength, $name);
		}
	}
});
