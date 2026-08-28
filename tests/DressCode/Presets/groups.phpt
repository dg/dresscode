<?php declare(strict_types=1);

/**
 * What a group may carry, so that asking for one never fights the standard the project chose.
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


test('every rule a preset names exists, and only the standards carry a style', function () use ($registry) {
	foreach ($registry->presets as $name => $class) {
		$profile = profileOf($class);
		foreach ($profile->rules as $rule => $value) {
			Assert::same('dresscode/' . $rule, RuleInfo::of($registry->resolveRule($rule))->name, "$rule in $name");
		}

		if (!in_array($name, ['dresscode/perCs', 'dresscode/psr12'], true)) {
			Assert::null($profile->indent, $name);
			Assert::null($profile->lineEnding, $name);
			Assert::null($profile->lineLength, $name);
		}
	}
});
