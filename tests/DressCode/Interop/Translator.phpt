<?php declare(strict_types=1);

use DressCode\Config\RuleRegistry;
use DressCode\ConfigurableRule;
use DressCode\Interop\PhpCodeSniffer;
use DressCode\Interop\PhpCsFixer;
use DressCode\Interop\Translation;
use DressCode\Interop\Translator;
use DressCode\Rules\Namespaces\NameNotationRule;
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
	$translation = (new Translator)->translate(['@PSR12' => true, 'PSR12' => true, '@Symfony' => true, '@PhpCsFixer' => true, 'no_such_fixer' => true]);
	Assert::same(['dresscode/psr12', 'dresscode/symfony'], $translation->presets);
	Assert::same([], $translation->rules);
	Assert::same([
		'The rule set @PhpCsFixer has no DressCode preset; start from dresscode/per or dresscode/psr12.',
		'No DressCode rule covers no_such_fixer.',
	], $translation->warnings);
});


test('an element with no rule of its own is dropped from the list, not passed on', function () {
	// @Symfony asks for array_destructuring, which dresscode/trailing-comma does not know
	$translation = (new Translator)->translate([
		'trailing_comma_in_multiline' => ['elements' => ['array_destructuring', 'arrays', 'match', 'parameters']],
	]);
	Assert::same(
		['multiLine' => ['arrays', 'match', 'parameters'], 'singleLine' => false],
		$translation->rules['dresscode/trailing-comma'],
	);
	Assert::same(
		['trailing_comma_in_multiline with array_destructuring has no equivalent, dresscode/trailing-comma leaves the comma of a destructuring alone'],
		$translation->warnings,
	);
});


test('a set of aliases PHP 8 no longer has is dropped from the list', function () {
	$translation = (new Translator)->translate([
		'no_alias_functions' => ['sets' => ['@internal', '@mbreg', '@exif']],
	]);
	Assert::same(['sets' => ['internal']], $translation->rules['dresscode/no-alias-functions']);
	Assert::same([
		'no_alias_functions with @mbreg has no equivalent, PHP 8 has none of the aliases it replaces',
		'no_alias_functions with @exif has no equivalent, PHP 8 has none of the aliases it replaces',
	], $translation->warnings);
});


test('options are translated, a rule switched off is turned off', function () {
	$translation = (new Translator)->translate([
		'cast_spaces' => ['space' => 'none'],
		'concat_space' => ['spacing' => 'one'],
		'array_syntax' => ['syntax' => 'long'],
		'elseif' => false,
	]);
	Assert::same([
		'dresscode/cast-spacing' => ['spacing' => 'none'],
		'dresscode/concat-spacing' => ['spacing' => 'single'],
		'dresscode/elseif-keyword' => false,
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


test('a rule switched off turns off what only it covers in its tool, and leaves what another rule of the tool covers too', function () {
	$translator = new Translator(
		['a_one' => 'acme/a', 'a_two' => 'acme/a', 'b_only' => 'acme/b', 'Acme.Cat.A' => 'acme/a'],
		['@Acme' => 'acme/preset'],
	);
	$translation = $translator->translate(['@Acme' => true, 'a_one' => false, 'b_only' => false]);
	Assert::same(['acme/b' => false], $translation->rules);
	Assert::same(['a_one is switched off, but acme/a also covers a_two and is not turned off; turn it off if none of them applies.'], $translation->warnings);
	Assert::contains("'acme/b' => false,", $translation->toConfig());

	// a sniff shares nothing with a fixer, and a rule some foreign rule enables stays enabled in either order
	Assert::same(['acme/a' => false], $translator->translate(['Acme.Cat.A' => false])->rules);
	Assert::same(['acme/a' => true], $translator->translate(['a_one' => true, 'Acme.Cat.A' => false])->rules);
	Assert::same(['acme/a' => true], $translator->translate(['Acme.Cat.A' => false, 'a_one' => true])->rules);

	// an exclusion of one message of a sniff cannot narrow the rule of the sniff
	$translation = $translator->translate(['Acme.Cat.A.Message' => false]);
	Assert::same([], $translation->rules);
	Assert::same(['Acme.Cat.A.Message is excluded, but DressCode cannot narrow acme/a to it; the rule stays as the rest of the configuration says.'], $translation->warnings);
});


test('a rule switched off under a set stays off in the configuration the translation writes', function () {
	$dir = __DIR__ . '/../../temp/translator';
	@mkdir($dir, recursive: true); // @ - may exist
	$file = "$dir/dresscode.php";
	file_put_contents($file, (new Translator)->translate(['@PSR12' => true, 'no_closing_tag' => false])->toConfig());
	$resolved = new DressCode\Config\PresetResolver(new RuleRegistry)
		->resolve(DressCode\Config\Loader::loadFile($file), '8.4');
	Assert::false($resolved->getRule('dresscode/no-closing-tag')?->isActive());

	// braces_position shares its rule with control_structure_continuation_position, so it is said, not done
	$translation = (new Translator)->translate(['@PSR12' => true, 'braces_position' => false]);
	Assert::same([], $translation->rules);
	Assert::match('braces_position is switched off, but dresscode/braces-position also covers control_structure_continuation_position%a%', $translation->warnings[0] ?? '');
});


test('a ruleset switches off a sniff it excludes inside a rule, and says it leaves the excluded paths out', function () {
	$dir = __DIR__ . '/../../temp/translator';
	@mkdir($dir, recursive: true); // @ - may exist
	file_put_contents("$dir/phpcs.xml", <<<'XX'
		<?xml version="1.0"?>
		<ruleset name="test">
			<exclude-pattern>tests/*</exclude-pattern>
			<rule ref="PSR12">
				<exclude name="PSR2.Classes.ClassDeclaration"/>
				<exclude-pattern>legacy/*</exclude-pattern>
			</rule>
		</ruleset>
		XX);
	[$rules, $warnings] = PhpCodeSniffer::readConfig("$dir/phpcs.xml");
	Assert::same(['PSR12' => true, 'PSR2.Classes.ClassDeclaration' => false], $rules);
	Assert::same([
		'The paths the ruleset excludes are not carried over; set them with excludePaths.',
		'The paths excluded from PSR12 are not carried over; turn its rules off there with an override.',
	], $warnings);
});


test('the configuration is written as a dresscode.php', function () {
	$translation = (new Translator)->translate(['@PSR12' => true, 'cast_spaces' => ['space' => 'none'], 'elseif' => true]);
	Assert::same(
		"<?php declare(strict_types=1);\n\n"
		. "use DressCode\\Config;\n\n"
		. "return new Config(\n"
		. "\tpresets: ['dresscode/psr12'],\n"
		. "\trules: [\n"
		. "\t\t'dresscode/cast-spacing' => ['spacing' => 'none'],\n"
		. "\t\t'dresscode/elseif-keyword' => true,\n"
		. "\t],\n"
		. ");\n",
		$translation->toConfig(),
	);
});


test('two foreign rules translated to name-notation and name-fallback merge into options their schemas accept', function () {
	$translation = (new Translator)->translate(['global_namespace_import' => true, 'native_function_invocation' => true]);
	$notation = $translation->rules['dresscode/name-notation'];
	$fallback = $translation->rules['dresscode/name-fallback'];
	Assert::equal([
		'globalClasses' => 'import',
		'globalFunctions' => ['*' => 'backslash'],
		'globalConstants' => ['*' => 'backslash'], // the fixer imports no constant by default
	], $notation);
	Assert::equal(['optimizedFunctions' => 'qualified', 'functions' => ['*' => 'fallback']], $fallback);
	Assert::noError(fn() => (new Processor)->process(NameNotationRule::getOptionsSchema(), $notation));
	Assert::noError(fn() => (new Processor)->process(DressCode\Rules\Namespaces\NameFallbackRule::getOptionsSchema(), $fallback));
});


test('what the fixers and the sniffs exclude or add to the functions and constants they write translates name by name', function () {
	$cases = [
		// strict takes the backslash from an excluded function, without strict it stays as written
		[['native_function_invocation' => ['include' => ['@all'], 'exclude' => ['dump'], 'scope' => 'namespaced']],
			['globalFunctions' => ['*' => 'backslash']],
			['functions' => ['*' => 'qualified', 'dump' => 'fallback']],
		],
		[['native_function_invocation' => [
			'include' => ['@compiler_optimized', 'dump'],
			'exclude' => ['var_dump'],
			'strict' => false,
			'scope' => 'namespaced',
		]],
			['globalFunctions' => ['*' => 'backslash']],
			['optimizedFunctions' => 'qualified', 'functions' => ['dump' => 'qualified', 'var_dump' => 'keep']],
		],
		// fix_built_in asks for the constants PHP declares, qualified where the compiler computes with them, and strict takes the backslash from the rest
		[['native_constant_invocation' => ['scope' => 'namespaced']],
			['globalConstants' => ['*' => 'backslash']],
			['optimizedConstants' => 'qualified', 'constants' => ['*' => 'fallback']],
		],
		[['native_constant_invocation' => ['fix_built_in' => false, 'include' => ['PHP_EOL'], 'exclude' => ['DEBUG', 'null'], 'scope' => 'namespaced']],
			['globalConstants' => ['*' => 'backslash']],
			['constants' => ['PHP_EOL' => 'qualified', 'DEBUG' => 'fallback', '*' => 'fallback']],
		],
		// the special functions join an empty include instead of naming every function
		[['SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalFunctions' => ['includeSpecialFunctions' => true]],
			['globalFunctions' => ['*' => 'backslash']],
			['optimizedFunctions' => 'qualified'],
		],
		// an include limits the backslash to the names it lists, and an excluded name is left as it is
		[['SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalFunctions' => ['include' => ['dump'], 'exclude' => ['var_dump']]],
			['globalFunctions' => ['dump' => 'backslash', 'var_dump' => 'keep']],
			['functions' => ['dump' => 'qualified', 'var_dump' => 'keep']],
		],
		[['SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalConstants' => ['include' => ['PHP_EOL'], 'exclude' => ['DEBUG']]],
			['globalConstants' => ['PHP_EOL' => 'backslash', 'DEBUG' => 'keep']],
			['constants' => ['PHP_EOL' => 'qualified', 'DEBUG' => 'keep']],
		],
		[['SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly' => ['allowFullyQualifiedGlobalFunctions' => true, 'allowFallbackGlobalConstants' => false]],
			[
				'classes' => 'import',
				'globalClasses' => 'import',
				'functions' => 'import',
				'globalFunctions' => ['*' => 'keep'],
				'constants' => 'import',
				'globalConstants' => ['*' => 'import'],
			],
			['constants' => ['*' => 'qualified']],
		],
	];
	foreach ($cases as [$rules, $notation, $fallback]) {
		$translated = (new Translator)->translate($rules)->rules;
		Assert::equal($notation, $translated['dresscode/name-notation']);
		Assert::equal($fallback, $translated['dresscode/name-fallback']);
		Assert::noError(fn() => (new Processor)->process(NameNotationRule::getOptionsSchema(), $notation));
		Assert::noError(fn() => (new Processor)->process(DressCode\Rules\Namespaces\NameFallbackRule::getOptionsSchema(), $fallback));
	}
});
