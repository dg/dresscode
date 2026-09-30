<?php declare(strict_types=1);

use DressCode\Config\RuleRegistry;
use DressCode\{ConfigurableRule, ConfigurationException};
use DressCode\Interop\{PhpCodeSniffer, PhpCsFixer, Translation, Translator};
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
	$rules = (new RuleRegistry)->rules;
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
	$rules = (new RuleRegistry)->rules;
	foreach (array_keys(allTranslations()) as $foreign) {
		Assert::false(isset($rules[$foreign]), "$foreign is the name of a rule");
	}
});


test('a set becomes a preset, an unknown rule a warning', function () {
	$translation = (new Translator)->translate(['@PSR12' => true, 'PSR12' => true, '@Symfony' => true, '@PhpCsFixer' => true, 'no_such_fixer' => true]);
	Assert::same(['dresscode/psr12', 'dresscode/symfony'], $translation->presets);
	Assert::same([], $translation->rules);
	Assert::same([
		'The rule set `@PhpCsFixer` has no DressCode preset; start from the preset `perCs` or `psr12`.',
		'No DressCode rule covers `no_such_fixer`.',
	], $translation->warnings);
});


test('an element with no rule of its own is dropped from the list, not passed on', function () {
	// @Symfony asks for array_destructuring, which dresscode/trailingComma does not know
	$translation = (new Translator)->translate([
		'trailing_comma_in_multiline' => ['elements' => ['array_destructuring', 'arrays', 'match', 'parameters']],
	]);
	Assert::same(
		[
			'array' => 'required', 'parameter' => 'required', 'matchArm' => 'required', 'argument' => 'keep', 'closureUse' => 'keep',
			'import' => 'keep', 'list' => 'keep',
		],
		$translation->rules['dresscode/trailingComma'],
	);
	Assert::same(
		['`trailing_comma_in_multiline` with `array_destructuring` is translated only for `[...]`, which follows the place `array` of `dresscode/trailingComma`; a multi-line `list()` keeps its comma as written.'],
		$translation->warnings,
	);
});


test('a set of aliases PHP 8 no longer has is dropped from the list', function () {
	$translation = (new Translator)->translate([
		'no_alias_functions' => ['sets' => ['@internal', '@mbreg', '@exif']],
	]);
	Assert::same(['sets' => ['internal']], $translation->rules['dresscode/noAliasFunctions']);
	Assert::same([
		'`no_alias_functions` with `@mbreg` has no equivalent; PHP 8 has none of the aliases it replaces.',
		'`no_alias_functions` with `@exif` has no equivalent; PHP 8 has none of the aliases it replaces.',
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
		'dresscode/castSpacing' => ['spacing' => 'none'],
		'dresscode/concatSpacing' => ['spacing' => 'single'],
		'dresscode/elseifKeyword' => false,
	], $translation->rules);
	Assert::same(
		['`array_syntax` with `syntax=long` has no equivalent; DressCode writes the short syntax only.'],
		$translation->warnings,
	);
});


test('the kinds of ordered_class_elements are translated to the kinds of orderedMembers', function () {
	$translation = (new Translator)->translate(['ordered_class_elements' => ['order' => ['use_trait', 'constant_public', 'method_public_static', 'construct', 'magic']]]);
	Assert::same(
		['dresscode/orderedMembers' => ['order' => ['traitUse', 'publicConstant', 'publicStaticMethod', 'constructor', 'magicMethod']]],
		$translation->rules,
	);
});


test('the kinds of ordered_class_elements without an equivalent are left out and named', function () {
	$translation = (new Translator)->translate(['ordered_class_elements' => ['order' => ['use_trait', 'property_static', 'case', 'method:__construct', 'public', 'method_public']]]);
	Assert::same(
		['dresscode/orderedMembers' => ['order' => ['traitUse', 'case', 'publicMethod']]],
		$translation->rules,
	);
	Assert::same(
		['`ordered_class_elements` kinds without an equivalent in `dresscode/orderedMembers` were left out: `property_static`, `method:__construct`, `public`.'],
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
		[
			'array' => 'required', 'argument' => 'required', 'list' => 'optional', 'import' => 'optional', 'parameter' => 'keep',
			'matchArm' => 'keep', 'closureUse' => 'keep',
		],
		$translation->rules['dresscode/trailingComma'],
	);
	Assert::same(
		['parameter' => true, 'property' => false, 'return' => true],
		$translation->rules['dresscode/typeHintRequired'],
	);

	// a place one of them requires stays required whichever comes first
	$translation = (new Translator)->translate([
		'no_trailing_comma_in_singleline' => true,
		'SlevomatCodingStandard.Arrays.TrailingArrayComma' => true,
	]);
	Assert::same(
		[
			'argument' => 'optional', 'array' => 'required', 'list' => 'optional', 'import' => 'optional', 'parameter' => 'keep',
			'matchArm' => 'keep', 'closureUse' => 'keep',
		],
		$translation->rules['dresscode/trailingComma'],
	);
});


test('a translation of the trailing comma leaves the places it does not name as they are', function () {
	$places = ['array', 'argument', 'parameter', 'matchArm', 'closureUse', 'import', 'list'];
	$translation = (new Translator)->translate(['SlevomatCodingStandard.Functions.RequireTrailingCommaInDeclaration' => true]);
	$options = $translation->rules['dresscode/trailingComma'];
	Assert::same(['parameter' => 'required'] + array_fill_keys($places, 'keep'), $options);

	$rules = [
		'no_trailing_comma_in_singleline' => true,
		'trailing_comma_in_multiline' => ['elements' => ['arguments']],
		'SlevomatCodingStandard.Arrays.TrailingArrayComma' => true,
	];
	$expected = ['array' => 'required', 'argument' => 'required', 'list' => 'optional', 'import' => 'optional'] + array_fill_keys($places, 'keep');
	foreach ([$rules, array_reverse($rules, true)] as $order) {
		Assert::equal($expected, (new Translator)->translate($order)->rules['dresscode/trailingComma']);
	}
});


test('a place the translation does not name keeps what the preset of the translation gives it', function () {
	$preset = [
		'array' => 'required', 'argument' => 'required', 'parameter' => 'required', 'matchArm' => 'required', 'closureUse' => 'required',
		'import' => 'optional', 'list' => 'optional',
	];
	$translate = fn(array $rules) => (new Translator)->translate($rules)->rules['dresscode/trailingComma'];

	// the set still asks for the comma of a multi-line list, the rule of one line adds nothing to that
	Assert::equal($preset, $translate(['@PER-CS' => true, 'no_trailing_comma_in_singleline' => true]));

	// the fixer named replaces what the set says of a multi-line list, and the set still removes the comma on one line
	Assert::equal(
		['array' => 'required'] + array_fill_keys(array_keys($preset), 'optional'),
		$translate(['@PER-CS' => true, 'trailing_comma_in_multiline' => ['elements' => ['arrays']]]),
	);

	// a standard that does not run the rule leaves the places alone
	Assert::equal(
		['argument' => 'required'] + array_fill_keys(array_keys($preset), 'keep'),
		$translate(['PSR12' => true, 'SlevomatCodingStandard.Functions.RequireTrailingCommaInCall' => true]),
	);
});


test('options of a rule that takes none are reported, not dropped in silence', function () {
	$translation = (new Translator)->translate(['no_spaces_around_offset' => ['positions' => ['inside']]]);
	Assert::same(['dresscode/offsetBracketSpacing' => true], $translation->rules);
	Assert::same(
		['The options of `no_spaces_around_offset` were not translated; review `dresscode/offsetBracketSpacing` in the reference.'],
		$translation->warnings,
	);
});


test('a name of another tool stands for the rules covering it', function () {
	$translator = new Translator;
	Assert::same(['dresscode/castSpacing'], $translator->findRules('cast_spaces'));
	Assert::same(['dresscode/shortArraySyntax'], $translator->findRules('array_syntax'));
	Assert::same(['dresscode/visibilityRequired'], $translator->findRules('Squiz.Scope.MethodScope'));
	Assert::same([], $translator->findRules('no_such_fixer'));
	Assert::contains('cast_spaces', $translator->findForeignNames('dresscode/castSpacing'));
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
	Assert::same(['No DressCode rule covers `cast_spaces`.'], $translation->warnings);
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
	Assert::same(['`a_one` is switched off, but `acme/a` also covers `a_two` and is not turned off; turn it off if none of them applies.'], $translation->warnings);
	Assert::contains("'acme/b' => false,", $translation->toConfig());

	// a sniff shares nothing with a fixer, and a rule some foreign rule enables stays enabled in either order
	Assert::same(['acme/a' => false], $translator->translate(['Acme.Cat.A' => false])->rules);
	Assert::same(['acme/a' => true], $translator->translate(['a_one' => true, 'Acme.Cat.A' => false])->rules);
	Assert::same(['acme/a' => true], $translator->translate(['Acme.Cat.A' => false, 'a_one' => true])->rules);

	// an exclusion of one message of a sniff cannot narrow the rule of the sniff
	$translation = $translator->translate(['Acme.Cat.A.Message' => false]);
	Assert::same([], $translation->rules);
	Assert::same(['`Acme.Cat.A.Message` is excluded, but DressCode cannot narrow `acme/a` to it; the rule stays as the rest of the configuration says.'], $translation->warnings);
});


test('a rule switched off under a set stays off in the configuration the translation writes', function () {
	$dir = createTempDir('translator');
	$file = "$dir/dresscode.php";
	file_put_contents($file, (new Translator)->translate(['@PSR12' => true, 'no_closing_tag' => false])->toConfig());
	$resolved = new DressCode\Config\ConfigResolver(new RuleRegistry)
		->resolve(DressCode\Config\Loader::loadFile($file), '8.4');
	Assert::false($resolved->getRule('dresscode/noClosingTag')?->isActive());

	// braces_position shares its rule with control_structure_continuation_position, so it is said, not done
	$translation = (new Translator)->translate(['@PSR12' => true, 'braces_position' => false]);
	Assert::same([], $translation->rules);
	Assert::match('`braces_position` is switched off, but `dresscode/bracesPosition` also covers `control_structure_continuation_position%a%', $translation->warnings[0] ?? '');
});


test('a ruleset switches off a sniff it excludes inside a rule, and says it leaves the excluded paths out', function () {
	$dir = createTempDir('translator');
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
		'The paths the ruleset excludes are not carried over; set them with the key `excludePaths`.',
		'The paths excluded from `PSR12` are not carried over; turn its rules off there with an override.',
	], $warnings);
});


test('the sniffs of PSR12 listed one by one translate, all but four that no rule covers', function () {
	$sniffs = [
		'Generic.ControlStructures.InlineControlStructure', 'Generic.Files.ByteOrderMark', 'Generic.Files.LineEndings',
		'Generic.Files.LineLength', 'Generic.Formatting.DisallowMultipleStatements', 'Generic.Functions.FunctionCallArgumentSpacing',
		'Generic.NamingConventions.UpperCaseConstantName', 'Generic.PHP.DisallowAlternativePHPTags', 'Generic.PHP.DisallowShortOpenTag',
		'Generic.PHP.LowerCaseConstant', 'Generic.PHP.LowerCaseKeyword', 'Generic.PHP.LowerCaseType',
		'Generic.WhiteSpace.DisallowTabIndent', 'Generic.WhiteSpace.IncrementDecrementSpacing', 'Generic.WhiteSpace.ScopeIndent',
		'PEAR.Functions.ValidDefaultValue',
		'PSR1.Classes.ClassDeclaration', 'PSR1.Files.SideEffects', 'PSR1.Methods.CamelCapsMethodName',
		'PSR2.Classes.ClassDeclaration', 'PSR2.Classes.PropertyDeclaration', 'PSR2.ControlStructures.ElseIfDeclaration',
		'PSR2.ControlStructures.SwitchDeclaration', 'PSR2.Files.ClosingTag', 'PSR2.Files.EndFileNewline',
		'PSR2.Methods.FunctionCallSignature', 'PSR2.Methods.FunctionClosingBrace', 'PSR2.Methods.MethodDeclaration',
		'PSR12.Classes.AnonClassDeclaration', 'PSR12.Classes.ClassInstantiation', 'PSR12.Classes.ClosingBrace',
		'PSR12.Classes.OpeningBraceSpace', 'PSR12.ControlStructures.BooleanOperatorPlacement',
		'PSR12.ControlStructures.ControlStructureSpacing', 'PSR12.Files.DeclareStatement', 'PSR12.Files.FileHeader',
		'PSR12.Files.ImportStatement', 'PSR12.Files.OpenTag', 'PSR12.Functions.NullableTypeDeclaration',
		'PSR12.Functions.ReturnTypeDeclaration', 'PSR12.Keywords.ShortFormTypeKeywords', 'PSR12.Namespaces.CompoundNamespaceDepth',
		'PSR12.Operators.OperatorSpacing', 'PSR12.Properties.ConstantVisibility', 'PSR12.Traits.UseDeclaration',
		'Squiz.Classes.ValidClassName', 'Squiz.ControlStructures.ControlSignature', 'Squiz.ControlStructures.ForEachLoopDeclaration',
		'Squiz.ControlStructures.ForLoopDeclaration', 'Squiz.ControlStructures.LowercaseDeclaration', 'Squiz.Functions.FunctionDeclaration',
		'Squiz.Functions.FunctionDeclarationArgumentSpacing', 'Squiz.Functions.LowercaseFunctionKeywords',
		'Squiz.Functions.MultiLineFunctionDeclaration', 'Squiz.Scope.MethodScope', 'Squiz.WhiteSpace.CastSpacing',
		'Squiz.WhiteSpace.ControlStructureSpacing', 'Squiz.WhiteSpace.ScopeClosingBrace', 'Squiz.WhiteSpace.ScopeKeywordSpacing',
		'Squiz.WhiteSpace.SuperfluousWhitespace',
	];
	Assert::count(60, $sniffs);
	$translation = (new Translator)->translate(array_fill_keys($sniffs, true));
	$rules = array_keys($translation->rules);
	sort($rules);
	Assert::same([
		'dresscode/binaryOperatorSpacing', 'dresscode/blankLines', 'dresscode/bracesPosition', 'dresscode/castCanonicalType',
		'dresscode/classDefinitionSpacing', 'dresscode/commaSpacing', 'dresscode/concatSpacing',
		'dresscode/constructSpacing', 'dresscode/controlStructureBraces', 'dresscode/declareSpacing',
		'dresscode/doubleColonSpacing', 'dresscode/elseifKeyword', 'dresscode/eofLineEnding', 'dresscode/fallThroughComment',
		'dresscode/fullOpeningTag', 'dresscode/functionNameSpacing', 'dresscode/indentation', 'dresscode/keywordCasing',
		'dresscode/lineEnding', 'dresscode/lineLength', 'dresscode/multilineCall', 'dresscode/multilineCondition',
		'dresscode/nameCasing', 'dresscode/newArgumentParentheses', 'dresscode/noBom', 'dresscode/noClosingTag',
		'dresscode/noTrailingWhitespace', 'dresscode/orderedMembers', 'dresscode/parenthesesSpacing',
		'dresscode/semicolonSpacing', 'dresscode/singleMemberPerDeclaration', 'dresscode/singleMemberPerLine',
		'dresscode/singleStatementPerLine', 'dresscode/switchCaseColon', 'dresscode/switchCaseSpacing',
		'dresscode/ternaryOperatorSpacing', 'dresscode/trueFalseNullCasing', 'dresscode/typeHintSpacing',
		'dresscode/unaryOperatorSpacing', 'dresscode/uselessImportBackslash', 'dresscode/uselessParameterDefault',
		'dresscode/visibilityRequired',
	], $rules);
	Assert::same(
		[
			'Generic.PHP.DisallowAlternativePHPTags',
			'PSR1.Classes.ClassDeclaration',
			'PSR1.Files.SideEffects',
			'PSR12.Namespaces.CompoundNamespaceDepth',
		],
		array_values(array_filter($sniffs, fn($sniff) => in_array("No DressCode rule covers `$sniff`.", $translation->warnings, true))),
	);
	Assert::same(['anonymousClass' => 'keep'], $translation->rules['dresscode/newArgumentParentheses']);
	Assert::same(['order' => ['traitUse']], $translation->rules['dresscode/orderedMembers']);
	Assert::same(['members' => ['trait']], $translation->rules['dresscode/singleMemberPerDeclaration']);
	Assert::same(['spacing' => 'single'], $translation->rules['dresscode/concatSpacing']);
});


test('the properties of the sniffs of PSR12 translate, or say they have no equivalent', function () {
	Assert::same(
		['dresscode/multilineCondition' => ['operatorPosition' => 'start']],
		(new Translator)->translate(['PSR12.ControlStructures.BooleanOperatorPlacement' => ['allowOnly' => 'first']])->rules,
	);

	$translation = (new Translator)->translate(['PSR12.ControlStructures.BooleanOperatorPlacement' => ['allowOnly' => 'last']]);
	Assert::same([], $translation->rules);
	Assert::same(['`BooleanOperatorPlacement` with `allowOnly=last` has no equivalent; DressCode moves a boolean operator only to the start of a line.'], $translation->warnings);

	$translation = (new Translator)->translate([
		'PSR2.Methods.FunctionCallSignature' => ['allowMultipleArguments' => true, 'requiredSpacesAfterOpen' => 1],
		'Squiz.ControlStructures.ForLoopDeclaration' => ['requiredSpacesBeforeClose' => 1],
	]);
	Assert::same([
		'`FunctionCallSignature` with spaces inside the parentheses has no equivalent; DressCode writes none.',
		'`FunctionCallSignature` with `allowMultipleArguments=true` has no equivalent; DressCode puts every argument of a multi-line call on its own line.',
		'`ForLoopDeclaration` with spaces inside the parentheses has no equivalent; DressCode writes none.',
	], $translation->warnings);
});


test('a message excluded from a sniff turns off its rule, which the sniff covers through that message', function () {
	$translation = (new Translator)->translate(['PSR12' => true, 'PSR2.ControlStructures.SwitchDeclaration.SpaceBeforeColonCASE' => false]);
	Assert::same(['dresscode/switchCaseSpacing' => false], $translation->rules);
	Assert::same([], $translation->warnings);

	// the sniff excluded whole turns off what only it and its messages cover, and says what another sniff covers too
	$translation = (new Translator)->translate(['PSR12' => true, 'PSR2.ControlStructures.SwitchDeclaration' => false]);
	Assert::same(['dresscode/switchCaseColon' => false, 'dresscode/switchCaseSpacing' => false, 'dresscode/fallThroughComment' => false], $translation->rules);
	Assert::match('`PSR2.ControlStructures.SwitchDeclaration` is switched off, but `dresscode/keywordCasing` also covers `Generic.PHP.LowerCaseKeyword`%a%', $translation->warnings[0] ?? '');
});


test('a .php-cs-fixer.php that cannot be run is an error of the configuration, not the end of the run', function () {
	$dir = createTempDir('translator');

	// a class it misses is most likely PHP CS Fixer, which only the dresscode of the project sees
	file_put_contents("$dir/.php-cs-fixer.php", "<?php\nreturn (new Acme\\Missing\\Config)->setRules(['@PSR12' => true]);\n");
	Assert::exception(
		fn() => PhpCsFixer::readConfig("$dir/.php-cs-fixer.php"),
		ConfigurationException::class,
		'File `%a%.php-cs-fixer.php` cannot be read: Class "Acme\\Missing\\Config" not found; run the dresscode installed in the project beside PHP CS Fixer, which loads its classes.',
	);

	// any other error of the file is said as it is
	file_put_contents("$dir/.php-cs-fixer.php", "<?php\nreturn [\n");
	Assert::exception(
		fn() => PhpCsFixer::readConfig("$dir/.php-cs-fixer.php"),
		ConfigurationException::class,
		"File `%a%.php-cs-fixer.php` cannot be read: Unclosed '[' on line 2.",
	);
});


test('the configuration is written as a dresscode.php', function () {
	$translation = (new Translator)->translate(['@PSR12' => true, 'cast_spaces' => ['space' => 'none'], 'elseif' => true]);
	Assert::same(
		"<?php declare(strict_types=1);\n\n"
		. "use DressCode\\Config;\n\n"
		. "return new Config(\n"
		. "\tpresets: ['psr12'],\n"
		. "\trules: [\n"
		. "\t\t'castSpacing' => ['spacing' => 'none'],\n"
		. "\t\t'elseifKeyword' => true,\n"
		. "\t],\n"
		. ");\n",
		$translation->toConfig(),
	);
});


test('two foreign rules translated to nameNotation and nameFallback merge into options their schemas accept', function () {
	$translation = (new Translator)->translate(['global_namespace_import' => true, 'native_function_invocation' => true]);
	$notation = $translation->rules['dresscode/nameNotation'];
	$fallback = $translation->rules['dresscode/nameFallback'];
	Assert::equal([
		'globalClass' => 'import',
		'globalFunction' => ['*' => 'backslash'],
		'globalConstant' => ['*' => 'backslash'], // the fixer imports no constant by default
	], $notation);
	Assert::equal(['optimizedFunction' => 'qualified', 'function' => ['*' => 'fallback']], $fallback);
	Assert::noError(fn() => (new Processor)->process(NameNotationRule::getOptionsSchema(), $notation));
	Assert::noError(fn() => (new Processor)->process(DressCode\Rules\Namespaces\NameFallbackRule::getOptionsSchema(), $fallback));
});


test('what the fixers and the sniffs exclude or add to the functions and constants they write translates name by name', function () {
	$cases = [
		// strict takes the backslash from an excluded function, without strict it stays as written
		[['native_function_invocation' => ['include' => ['@all'], 'exclude' => ['dump'], 'scope' => 'namespaced']],
			['globalFunction' => ['*' => 'backslash']],
			['function' => ['*' => 'qualified', 'dump' => 'fallback']],
		],
		[['native_function_invocation' => [
			'include' => ['@compiler_optimized', 'dump'],
			'exclude' => ['var_dump'],
			'strict' => false,
			'scope' => 'namespaced',
		]],
			['globalFunction' => ['*' => 'backslash']],
			['optimizedFunction' => 'qualified', 'function' => ['dump' => 'qualified', 'var_dump' => 'keep']],
		],
		// fix_built_in asks for the constants PHP declares, qualified where the compiler computes with them, and strict takes the backslash from the rest
		[['native_constant_invocation' => ['scope' => 'namespaced']],
			['globalConstant' => ['*' => 'backslash']],
			['optimizedConstant' => 'qualified', 'constant' => ['*' => 'fallback']],
		],
		[['native_constant_invocation' => ['fix_built_in' => false, 'include' => ['PHP_EOL'], 'exclude' => ['DEBUG', 'null'], 'scope' => 'namespaced']],
			['globalConstant' => ['*' => 'backslash']],
			['constant' => ['PHP_EOL' => 'qualified', 'DEBUG' => 'fallback', '*' => 'fallback']],
		],
		// the special functions join an empty include instead of naming every function
		[['SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalFunctions' => ['includeSpecialFunctions' => true]],
			['globalFunction' => ['*' => 'backslash']],
			['optimizedFunction' => 'qualified'],
		],
		// an include limits the backslash to the names it lists, and an excluded name is left as it is
		[['SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalFunctions' => ['include' => ['dump'], 'exclude' => ['var_dump']]],
			['globalFunction' => ['dump' => 'backslash', 'var_dump' => 'keep']],
			['function' => ['dump' => 'qualified', 'var_dump' => 'keep']],
		],
		[['SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalConstants' => ['include' => ['PHP_EOL'], 'exclude' => ['DEBUG']]],
			['globalConstant' => ['PHP_EOL' => 'backslash', 'DEBUG' => 'keep']],
			['constant' => ['PHP_EOL' => 'qualified', 'DEBUG' => 'keep']],
		],
		[['SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly' => ['allowFullyQualifiedGlobalFunctions' => true, 'allowFallbackGlobalConstants' => false]],
			[
				'class' => 'import',
				'globalClass' => 'import',
				'function' => 'import',
				'globalFunction' => ['*' => 'keep'],
				'constant' => 'import',
				'globalConstant' => ['*' => 'import'],
			],
			['constant' => ['*' => 'qualified']],
		],
	];
	foreach ($cases as [$rules, $notation, $fallback]) {
		$translated = (new Translator)->translate($rules)->rules;
		Assert::equal($notation, $translated['dresscode/nameNotation']);
		Assert::equal($fallback, $translated['dresscode/nameFallback']);
		Assert::noError(fn() => (new Processor)->process(NameNotationRule::getOptionsSchema(), $notation));
		Assert::noError(fn() => (new Processor)->process(DressCode\Rules\Namespaces\NameFallbackRule::getOptionsSchema(), $fallback));
	}
});
