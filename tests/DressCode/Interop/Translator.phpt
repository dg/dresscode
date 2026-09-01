<?php declare(strict_types=1);

use DressCode\Config\RuleRegistry;
use DressCode\ConfigurableRule;
use DressCode\Interop\PhpCodeSniffer;
use DressCode\Interop\PhpCsFixer;
use DressCode\Interop\Translation;
use DressCode\Interop\Translator;
use Nette\Schema\Processor;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** @return array<string, string|Closure(array<string, mixed>, Translation): mixed> */
function allTranslations(): array
{
	return PhpCsFixer::getTranslations() + PhpCodeSniffer::getTranslations();
}


test('a translation names a rule that exists and options it accepts', function () {
	$rules = (new RuleRegistry)->getRules();
	$processor = new Processor;
	foreach (allTranslations() as $foreign => $translation) {
		$result = new Translation;
		if (is_string($translation)) {
			$result->enable($translation);
		} else {
			$translation([], $result); // total: every option is read through ??
		}

		Assert::notSame([], $result->rules, "$foreign translates to nothing");
		foreach ($result->rules as $name => $options) {
			$class = $rules[$name] ?? null;
			Assert::notNull($class, "$foreign names the unknown rule $name");
			if ($options !== true) {
				Assert::true(is_subclass_of($class, ConfigurableRule::class), "$foreign gives options to $name, which takes none");
				$processor->process($class::getOptionsSchema(), $options); // throws on an unknown option or value
			}
		}
	}
});


test('the two name spaces do not overlap', function () {
	Assert::same([], array_intersect_key(PhpCsFixer::getTranslations(), PhpCodeSniffer::getTranslations()));
	Assert::same([], array_intersect_key(PhpCsFixer::getSets(), PhpCodeSniffer::getSets()));
	foreach (array_keys(PhpCsFixer::getTranslations()) as $fixer) {
		Assert::false(str_contains($fixer, '.'), "$fixer is not a fixer name");
	}

	foreach (array_keys(PhpCodeSniffer::getTranslations()) as $sniff) {
		Assert::true((bool) preg_match('~^\w+(\.\w+)+$~D', $sniff), "$sniff is not a sniff code");
	}
});


test('no foreign name is the name of a rule', function () {
	$rules = (new RuleRegistry)->getRules();
	foreach (array_keys(allTranslations()) as $foreign) {
		Assert::false(isset($rules[$foreign]), "$foreign is the name of a rule");
	}
});


test('a set becomes a preset, an unknown rule a warning', function () {
	$translation = (new Translator)->translate(['@PSR12' => true, 'PSR12' => true, '@Symfony' => true, 'no_such_fixer' => true]);
	Assert::same(['dresscode/psr12'], $translation->presets);
	Assert::same([], $translation->rules);
	Assert::same([
		'The rule set @Symfony has no DressCode preset; start from dresscode/per or dresscode/psr12.',
		'No DressCode rule covers no_such_fixer.',
	], $translation->warnings);
});


test('options are translated, a rule switched off is skipped', function () {
	$translation = (new Translator)->translate([
		'cast_spaces' => ['space' => 'none'],
		'concat_space' => ['spacing' => 'one'],
		'array_syntax' => ['syntax' => 'long'],
		'elseif' => false,
	]);
	Assert::same([
		'dresscode/cast-spacing' => ['spacing' => 'none'],
		'dresscode/concat-spacing' => ['spacing' => 'single'],
	], $translation->rules);
	Assert::same(
		['array_syntax with syntax=long has no equivalent, DressCode writes the short syntax only'],
		$translation->warnings,
	);
});


test('foreign rules covering one rule are merged, not overwritten', function () {
	$translation = (new Translator)->translate([
		'SlevomatCodingStandard.Arrays.TrailingArrayComma' => true,
		'SlevomatCodingStandard.Functions.RequireTrailingCommaInCall' => true,
		'no_trailing_comma_in_singleline' => true,
		'SlevomatCodingStandard.TypeHints.ParameterTypeHint' => true,
		'SlevomatCodingStandard.TypeHints.ReturnTypeHint' => true,
	]);
	Assert::same(
		['multiLine' => ['arrays', 'arguments'], 'singleLine' => true],
		$translation->rules['dresscode/trailing-comma'],
	);
	Assert::same(
		['parameters' => true, 'properties' => false, 'returns' => true],
		$translation->rules['dresscode/type-hint-required'],
	);
});


test('options of a rule that takes none are reported, not dropped in silence', function () {
	$translation = (new Translator)->translate(['no_spaces_around_offset' => ['positions' => ['inside']]]);
	Assert::same(['dresscode/offset-bracket-spacing' => true], $translation->rules);
	Assert::same(
		['The options of no_spaces_around_offset were not translated; review dresscode/offset-bracket-spacing in the reference.'],
		$translation->warnings,
	);
});


test('a name of another tool stands for the rules covering it', function () {
	$translator = new Translator;
	Assert::same(['dresscode/cast-spacing'], $translator->findRules('cast_spaces'));
	Assert::same(['dresscode/short-array-syntax'], $translator->findRules('array_syntax'));
	Assert::same(['dresscode/visibility-required'], $translator->findRules('Squiz.Scope.MethodScope'));
	Assert::same([], $translator->findRules('no_such_fixer'));
	Assert::contains('cast_spaces', $translator->findForeignNames('dresscode/cast-spacing'));
	Assert::same([], $translator->findForeignNames('dresscode/no-such-rule'));
});


test('a translator over tables of its own', function () {
	$translator = new Translator(
		[
			'foo_bar' => 'acme/foo',
			'Acme.Bar' => fn(array $options, Translation $t) => $t
				->enable('acme/bar', ['x' => $options['x'] ?? 1])
				->enable('acme/foo'),
		],
		['@Acme' => 'acme/preset'],
	);
	$translation = $translator->translate(['@Acme' => true, 'Acme.Bar' => ['x' => 2], 'cast_spaces' => true]);
	Assert::same(['acme/preset'], $translation->presets);
	Assert::same(['acme/bar' => ['x' => 2], 'acme/foo' => true], $translation->rules);
	Assert::same(['No DressCode rule covers cast_spaces.'], $translation->warnings);
	Assert::same(['acme/bar', 'acme/foo'], $translator->findRules('Acme.Bar'));
	Assert::same(['foo_bar', 'Acme.Bar'], $translator->findForeignNames('acme/foo'));
});


test('the configuration is written as a dresscode.php', function () {
	$translation = (new Translator)->translate(['@PSR12' => true, 'cast_spaces' => ['space' => 'none'], 'elseif' => true]);
	Assert::same(
		"<?php declare(strict_types=1);\n\n"
		. "use DressCode\\Config;\n\n"
		. "return Config::create()\n"
		. "\t->preset('dresscode/psr12')\n"
		. "\t->enable('dresscode/cast-spacing', ['spacing' => 'none'])\n"
		. "\t->enable('dresscode/elseif-keyword');\n",
		$translation->toConfig(),
	);
});
