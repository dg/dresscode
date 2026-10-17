<?php declare(strict_types=1);

use DressCode\Config\{ConfigResolver, Loader, PluginRegistry};
use DressCode\ConfigurationException;
use DressCode\Interop\{PhpCodeSniffer, PhpCsFixer, Translation, Translator};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** @return array<string, array<string, mixed>|Closure(array<string, mixed>, Translation): mixed> */
function allTranslations(): array
{
	return PhpCsFixer::getTranslations() + PhpCodeSniffer::getTranslations();
}


/** Resolves the configuration the translation prints, which throws on a decision or a value the catalogue does not accept. */
function resolveTranslation(Translation $translation): DressCode\Config\ResolvedConfig
{
	$file = createTempDir('translator') . '/dresscode.php';
	file_put_contents($file, $translation->toPhp());
	return new ConfigResolver(new PluginRegistry)->resolve(Loader::loadFile($file), DressCode\Config::DefaultPhpVersion);
}


/**
 * @param  array<string, mixed>  $places  place => value
 * @return array<string, mixed>  path => value
 */
function commaPlaces(array $places): array
{
	$paths = [];
	foreach ($places as $place => $value) {
		$paths["multiline.trailingComma.$place"] = $value;
	}

	return $paths;
}


test('a translation names a decision that exists and values it accepts', function () {
	$catalogue = (new PluginRegistry)->getCatalogue();
	$translator = new Translator;
	foreach (array_keys(allTranslations()) as $foreign) {
		$paths = $translator->findPaths($foreign);
		Assert::true(
			$paths !== [] || $translator->translate([$foreign => true])->warnings !== [],
			"$foreign translates to nothing and says nothing",
		);
		foreach ($paths as $path) {
			Assert::notNull($catalogue->find($path), "$foreign names the unknown decision $path");
		}

		Assert::noError(fn() => resolveTranslation($translator->translate([$foreign => true])));
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


test('a set becomes a preset, an unknown rule a warning', function () {
	$translation = (new Translator)->translate([
		'@PSR12' => true, 'PSR12' => true, '@Symfony' => true, '@PhpCsFixer' => true, '@PHP80Migration' => true, 'no_such_fixer' => true,
		'Squiz' => true, 'Generic.Files.LineLength.TooLong' => true, 'Acme.Cat.Name.Code' => true,
	]);
	Assert::same(['dresscode/psr12', 'dresscode/symfony'], $translation->presets);
	Assert::same([], $translation->decisions);
	Assert::same([
		'The rule set `@PhpCsFixer` has no DressCode preset; start from the preset `symfony`, the nearest one.',
		'The rule set `@PHP80Migration` has no DressCode preset; start from the preset `perCs` or `psr12`.',
		'No DressCode rule covers `no_such_fixer`.',
		'The standard `Squiz` has no DressCode preset; start from the preset `perCs` or `psr12`.',
		'`Generic.Files.LineLength.TooLong` is one message of `Generic.Files.LineLength`, which DressCode cannot enable alone.',
		'No DressCode rule covers `Acme.Cat.Name.Code`.',
	], $translation->warnings);
});


test('an element with no rule of its own is dropped from the list, not passed on', function () {
	// @Symfony asks for array_destructuring, which dresscode/trailingComma does not know
	$translation = (new Translator)->translate([
		'trailing_comma_in_multiline' => ['elements' => ['array_destructuring', 'arrays', 'match', 'parameters']],
	]);
	Assert::same(
		commaPlaces([
			'array' => 'required', 'parameter' => 'required', 'matchArm' => 'required', 'argument' => 'keep', 'closureUse' => 'keep',
			'import' => 'keep', 'list' => 'keep',
		]),
		$translation->decisions,
	);
	Assert::same(
		['`trailing_comma_in_multiline` with `array_destructuring` is translated only for the short `[...]`, which `trailingComma` sets under the key `array`; a multi-line `list()` keeps its comma as written.'],
		$translation->warnings,
	);
});


test('a set of aliases PHP 8 no longer has is dropped from the list', function () {
	$translation = (new Translator)->translate([
		'no_alias_functions' => ['sets' => ['@internal', '@mbreg', '@exif']],
	]);
	Assert::same(['hygiene.aliasFunctions' => ['core']], $translation->decisions);
	Assert::same([
		'`no_alias_functions` with `@mbreg` has no equivalent; PHP 8 has none of the aliases it replaces.',
		'`no_alias_functions` with `@exif` has no equivalent; PHP 8 has none of the aliases it replaces.',
	], $translation->warnings);
});


test('options are translated, a rule switched off is turned off', function () {
	$translation = (new Translator)->translate([
		'cast_spaces' => ['space' => 'none'],
		'concat_space' => ['spacing' => 'one'],
		'yoda_style' => ['less_and_greater' => true],
		'elseif' => false,
		'multiline_whitespace_before_semicolons' => ['strategy' => 'new_line_for_chained_calls'],
	]);
	Assert::same([
		'spacing.cast' => 'compact',
		'spacing.concatenation' => 'spaced',
		'expressions.comparison.yoda' => 'required',
		'controlFlow.elseif' => 'keep',
		'multiline.semicolon' => 'nextLine',
	], $translation->decisions);
	Assert::same(
		['`yoda_style` decides `==`, `===`, `<` and `>` one by one, which DressCode decides together for the equality comparisons alone.'],
		$translation->warnings,
	);
	Assert::same(
		['literals.arraySyntax.array' => 'long', 'literals.arraySyntax.list' => 'long', 'expressions.assignment.increment' => 'postfix'],
		(new Translator)->translate([
			'array_syntax' => ['syntax' => 'long'],
			'list_syntax' => ['syntax' => 'long'],
			'increment_style' => ['style' => 'post'],
		])->decisions,
	);
});


test('a decision that needs the types of the code is left out and said so, a keep of it is not', function () {
	$translation = (new Translator)->translate(['class_keyword' => true, 'array_syntax' => true]);
	Assert::same(['literals.arraySyntax.array' => 'short'], $translation->decisions);
	Assert::same(
		['`literals.classNameInString` needs the types of the code, so it is left out; set it together with `typeAnalysis: phpstan` where the project has PHPStan.'],
		$translation->warnings,
	);
	Assert::noError(fn() => resolveTranslation($translation));
	Assert::same(['literals.classNameInString' => 'keep'], (new Translator)->translate(['class_keyword' => false])->decisions);
});


test('the kinds of ordered_class_elements are translated to the kinds of memberOrder', function () {
	$translation = (new Translator)->translate(
		['ordered_class_elements' => ['order' => ['use_trait', 'constant_public', 'method_public_static', 'construct', 'magic']]],
	);
	Assert::same(
		['classes.members.order' => ['traitUse', 'publicConstant', 'publicStaticMethod', 'constructor', 'magicMethod']],
		$translation->decisions,
	);
});


test('the kinds of ordered_class_elements without an equivalent are left out and named', function () {
	$translation = (new Translator)->translate(
		['ordered_class_elements' => ['order' => [
			'use_trait', 'property_static', 'case', 'method:__construct', 'public', 'method_public',
		]]],
	);
	Assert::same(
		['classes.members.order' => ['traitUse', 'enumCase', 'publicMethod']],
		$translation->decisions,
	);
	Assert::same(
		['The kinds `property_static`, `method:__construct`, `public` of `ordered_class_elements` have no equivalent in `classes.members.order` and were left out.'],
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
		commaPlaces([
			'array' => 'required', 'argument' => 'required', 'list' => 'optional', 'import' => 'optional', 'parameter' => 'keep',
			'matchArm' => 'keep', 'closureUse' => 'keep',
		]),
		array_filter($translation->decisions, fn(string $path) => str_starts_with($path, 'multiline.trailingComma.'), ARRAY_FILTER_USE_KEY),
	);
	Assert::same(
		['types.declaration.parameter' => 'required', 'types.declaration.return' => 'required'],
		array_filter($translation->decisions, fn(string $path) => str_starts_with($path, 'types.'), ARRAY_FILTER_USE_KEY),
	);

	// a place one of them requires stays required whichever comes first
	$translation = (new Translator)->translate([
		'no_trailing_comma_in_singleline' => true,
		'SlevomatCodingStandard.Arrays.TrailingArrayComma' => true,
	]);
	Assert::same(
		commaPlaces([
			'argument' => 'optional', 'array' => 'required', 'list' => 'optional', 'import' => 'optional', 'parameter' => 'keep',
			'matchArm' => 'keep', 'closureUse' => 'keep',
		]),
		$translation->decisions,
	);
});


test('foreign rules deciding one thing come to the same translation in either order', function () {
	$translate = fn(array $rules) => [(new Translator)->translate($rules), (new Translator)->translate(array_reverse($rules, true))];

	// a fixer of the space before a comma leaves its alignment to the one of the space after it
	foreach ($translate(['no_whitespace_before_comma_in_array' => true, 'whitespace_after_comma_in_array' => true]) as $translation) {
		Assert::same(['spacing.comma' => 'spaced', 'spacing.alignment.comma' => 'optional'], $translation->decisions);
	}

	// an order set in full wins over the one place another rule only prefers, and two different orders contradict
	foreach ($translate([
		'ordered_class_elements' => ['order' => ['constant_public', 'use_trait']],
		'PSR12.Traits.UseDeclaration' => true,
	]) as $translation) {
		Assert::same(['publicConstant', 'traitUse'], $translation->decisions['classes.members.order']);
	}

	$translator = new Translator([
		'a_order' => fn(array $o, Translation $t) => $t->setOrder('acme.order', ['a', 'b']),
		'b_order' => fn(array $o, Translation $t) => $t->setOrder('acme.order', ['b', 'a']),
	]);
	foreach ([['a_order', 'b_order'], ['b_order', 'a_order']] as $order) {
		Assert::same([], $translator->translate(array_fill_keys($order, true))->decisions);
	}

	// what each rule separates adds up
	foreach ($translate([
		'SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition' => true,
		'SlevomatCodingStandard.Classes.DisallowMultiPropertyDefinition' => true,
	]) as $translation) {
		Assert::equal(
			['classes.members.grouping.constant' => 'separated', 'classes.members.grouping.property' => 'separated'],
			array_intersect_key(
				$translation->decisions,
				[
					'classes.members.grouping.constant' => 1,
					'classes.members.grouping.property' => 1,
					'classes.members.grouping.traitUse' => 1,
				],
			),
		);
	}

	// counts of blank lines widen to the range of both, a count a map gives included
	foreach ($translate(['class_attributes_separation' => true, 'Squiz.WhiteSpace.FunctionSpacing' => true]) as $translation) {
		Assert::same([1, 2], $translation->decisions['blankLines.aroundMethods']);
	}

	foreach ($translate([
		'blank_line_before_statement' => true,
		'SlevomatCodingStandard.ControlStructures.JumpStatementsSpacing' => true,
	]) as $translation) {
		Assert::same([1, null], $translation->decisions['blankLines.beforeStatement']['return']);
		Assert::same(1, $translation->decisions['blankLines.beforeStatement']['yield']);
	}

	// values that contradict each other leave the decision out, and a keep a table writes gives way
	foreach ($translate(['group_import' => true, 'single_import_per_statement' => true]) as $translation) {
		Assert::false(isset($translation->decisions['imports.grouping.class']));
		Assert::contains(
			'The foreign rules give `imports.grouping.class` values that contradict each other; DressCode leaves it out, for the configuration to set by hand.',
			$translation->warnings,
		);
	}

	$translator = new Translator([
		'a_keep' => ['acme.foo' => 'keep'],
		'a_set' => ['acme.foo' => 'x'],
		'a_other' => ['acme.foo' => 'y'],
	]);
	Assert::same(['acme.foo' => 'x'], $translator->translate(['a_keep' => true, 'a_set' => true])->decisions);
	Assert::same(['acme.foo' => 'x'], $translator->translate(['a_set' => true, 'a_keep' => true])->decisions);
	foreach ([['a_set', 'a_keep', 'a_other'], ['a_other', 'a_set', 'a_keep'], ['a_keep', 'a_other', 'a_set']] as $order) {
		Assert::same([], $translator->translate(array_fill_keys($order, true))->decisions);
	}
});


test('an option with no value of DressCode to write is left out and said so, not written to be refused', function () {
	$translate = fn(array $rules) => (new Translator)->translate($rules);

	$translation = $translate(
		['SlevomatCodingStandard.ControlStructures.BlockControlStructureSpacing' => ['controlStructures' => ['if', 'case', 'default']]],
	);
	Assert::same(['blankLines.beforeStatement' => ['if' => 1], 'blankLines.afterStatement' => ['if' => 1]], $translation->decisions);
	Assert::same(
		['`SlevomatCodingStandard.ControlStructures.BlockControlStructureSpacing` with `case`, `default` has no equivalent; DressCode counts the blank lines before a `case` in `blankLines.betweenCases`.'],
		$translation->warnings,
	);
	Assert::noError(fn() => resolveTranslation($translation));

	$translation = $translate(
		['SlevomatCodingStandard.ControlStructures.BlockControlStructureSpacing' => ['controlStructures' => ['case', 'default']]],
	);
	Assert::same([], $translation->decisions);
	Assert::count(1, $translation->warnings);

	foreach ([
		'SlevomatCodingStandard.Classes.RequireMultiLineMethodSignature',
		'SlevomatCodingStandard.ControlStructures.RequireMultiLineCondition',
	] as $sniff) {
		$translation = $translate([$sniff => ['minLineLength' => 0]]);
		Assert::null($translation->lineLength);
		Assert::match("`$sniff` with `minLineLength=0` has no equivalent; %a%", $translation->warnings[0] ?? '');
		Assert::noError(fn() => resolveTranslation($translation));
	}

	$translation = $translate(['no_alias_functions' => ['sets' => ['@snmp', '@IMAP']]]);
	Assert::same(['hygiene.aliasFunctions' => ['imap']], $translation->decisions);
	Assert::same(
		['`no_alias_functions` with `@snmp` has no equivalent; DressCode leaves the aliases of the SNMP functions alone.'],
		$translation->warnings,
	);
	Assert::noError(fn() => resolveTranslation($translation));

	$translation = $translate(['numeric_literal_separator' => ['strategy' => 'no_separator']]);
	Assert::same(
		['literals.digitSeparator.integer' => 'forbidden', 'literals.digitSeparator.fraction' => 'forbidden'],
		$translation->decisions,
	);
	Assert::same([], $translation->warnings);

	// a ruleset writes no value as the text `null`
	$translation = $translate(['Generic.PHP.ForbiddenFunctions' => ['forbiddenFunctions' => ['delete' => 'null', 'sizeof' => 'count']]]);
	Assert::same(['upgrading.libraries.forbiddenFunctions' => ['delete' => null, 'sizeof' => 'use `count()`']], $translation->decisions);
});


test('a translation of the trailing comma leaves the places it does not name as they are', function () {
	$places = ['array', 'argument', 'parameter', 'matchArm', 'closureUse', 'import', 'list'];
	$translation = (new Translator)->translate(['SlevomatCodingStandard.Functions.RequireTrailingCommaInDeclaration' => true]);
	Assert::same(commaPlaces(['parameter' => 'required'] + array_fill_keys($places, 'keep')), $translation->decisions);

	$rules = [
		'no_trailing_comma_in_singleline' => true,
		'trailing_comma_in_multiline' => ['elements' => ['arguments']],
		'SlevomatCodingStandard.Arrays.TrailingArrayComma' => true,
	];
	$expected = ['array' => 'required', 'argument' => 'required', 'list' => 'optional', 'import' => 'optional'] + array_fill_keys(
		$places,
		'keep',
	);
	foreach ([$rules, array_reverse($rules, true)] as $order) {
		Assert::equal(commaPlaces($expected), (new Translator)->translate($order)->decisions);
	}
});


test('a translation of empty parentheses leaves the places it does not name as they are', function () {
	$translation = (new Translator)->translate(['attribute_empty_parentheses' => true]);
	Assert::equal(['classes.emptyParentheses.attribute' => 'forbidden'], $translation->decisions);

	$rules = ['new_with_parentheses' => ['anonymous_class' => false], 'attribute_empty_parentheses' => ['use_parentheses' => true]];
	$expected = [
		'classes.emptyParentheses.instantiation' => 'required',
		'classes.emptyParentheses.anonymousClass' => 'forbidden',
		'classes.emptyParentheses.attribute' => 'required',
	];
	foreach ([$rules, array_reverse($rules, true)] as $order) {
		Assert::equal($expected, (new Translator)->translate($order)->decisions);
	}

	// the sniff removes the parentheses of a named class and passes an anonymous one by
	$translation = (new Translator)->translate(['SlevomatCodingStandard.ControlStructures.NewWithoutParentheses' => true]);
	Assert::equal(['classes.emptyParentheses.instantiation' => 'forbidden'], $translation->decisions);
});


test('a fixer of the case of one kind of native names leaves the other kinds as they are', function () {
	Assert::equal(
		['casing.type' => 'lowercase'],
		(new Translator)->translate(['native_type_declaration_casing' => true])->decisions,
	);

	$rules = ['native_function_casing' => true, 'class_reference_name_casing' => true];
	foreach ([$rules, array_reverse($rules, true)] as $order) {
		Assert::equal(
			['casing.class' => 'declared', 'casing.function' => 'declared'],
			(new Translator)->translate($order)->decisions,
		);
	}
});


test('a fixer of the imports leaves the names of the global namespace to a fixer of those', function () {
	$translate = fn(array $rules) => (new Translator)->translate($rules)->decisions;
	Assert::same(['imports.leadingBackslash' => 'forbidden'], $translate(['no_leading_import_slash' => true]));

	$rules = ['no_leading_import_slash' => true, 'PhpCsFixerCustomFixers/no_leading_slash_in_global_namespace' => true];
	foreach ([$rules, array_reverse($rules, true)] as $order) {
		Assert::equal(
			['imports.leadingBackslash' => 'forbidden', 'qualification.leadingBackslash' => 'forbidden'],
			$translate($order),
		);
	}
});


test('a fixer of the case of constants leaves the keywords alone', function () {
	$translate = fn(array $rules) => (new Translator)->translate($rules)->decisions;
	Assert::equal(['casing.trueFalseNull' => 'uppercase'], $translate(['constant_case' => ['case' => 'upper']]));
	Assert::equal(
		['casing.keyword' => 'lowercase', 'casing.magicConstant' => 'uppercase'],
		$translate(['magic_constant_casing' => true, 'lowercase_keywords' => true]),
	);
});


test('the order of the types of a doc comment translates to the options typeNotation has too', function () {
	Assert::equal(
		['phpdoc.types.unionOrder' => 'keep', 'phpdoc.types.nullPosition' => 'last'],
		(new Translator)->translate(['phpdoc_types_order' => ['null_adjustment' => 'always_last', 'sort_algorithm' => 'none']])->decisions,
	);
	Assert::equal(
		['types.unionOrder' => 'alphabetical', 'types.nullPosition' => 'keep'],
		(new Translator)->translate(['ordered_types' => ['null_adjustment' => 'none']])->decisions,
	);
});


test('a fixer of the names of doc types leaves the notation of an array alone, unless another one sets it', function () {
	$translate = fn(array $rules) => (new Translator)->translate($rules)->decisions['phpdoc.types.array'] ?? null;
	Assert::null($translate(['phpdoc_scalar' => true]));

	$rules = ['phpdoc_types' => true, 'phpdoc_array_type' => true];
	foreach ([$rules, array_reverse($rules, true)] as $order) {
		Assert::same('generic', $translate($order));
	}
});


test('a fixer keeping group uses gives way to one making them', function () {
	$translate = fn(array $rules) => (new Translator)->translate($rules)->decisions['imports.grouping.class'] ?? null;
	Assert::same(['separated', 'grouped'], $translate(['single_import_per_statement' => ['group_to_single_imports' => false]]));
	Assert::same('separated', $translate(['single_import_per_statement' => true]));

	// the sniffs expand a group use, or forbid the comma between its names
	Assert::same('separated', $translate(['PSR2.Namespaces.UseDeclaration' => true]));
	Assert::same('separated', $translate(['SlevomatCodingStandard.Namespaces.MultipleUsesPerLine' => true]));
	Assert::same(['separated', 'combined'], $translate(['SlevomatCodingStandard.Namespaces.DisallowGroupUse' => true]));

	$rules = ['group_import' => true, 'single_import_per_statement' => ['group_to_single_imports' => false]];
	foreach ([$rules, array_reverse($rules, true)] as $order) {
		Assert::same('grouped', (new Translator)->translate($order)->decisions['imports.grouping.class']);
	}
});


test('a place the translation does not name keeps what the preset of the translation gives it', function () {
	$preset = [
		'array' => 'required', 'argument' => 'required', 'parameter' => 'required', 'matchArm' => 'required', 'closureUse' => 'required',
		'import' => 'optional', 'list' => 'optional',
	];
	$translate = fn(array $rules) => (new Translator)->translate($rules)->decisions;

	// the set still asks for the comma of a multi-line list, the rule of one line adds nothing to that
	Assert::equal(commaPlaces($preset), $translate(['@PER-CS' => true, 'no_trailing_comma_in_singleline' => true]));

	// the fixer named replaces what the set says of a multi-line list, and the set still removes the comma on one line
	Assert::equal(
		commaPlaces(['array' => 'required'] + array_fill_keys(array_keys($preset), 'optional')),
		$translate(['@PER-CS' => true, 'trailing_comma_in_multiline' => ['elements' => ['arrays']]]),
	);

	// a standard that does not run the rule leaves the places alone
	Assert::equal(
		commaPlaces(['argument' => 'required'] + array_fill_keys(array_keys($preset), 'keep')),
		$translate(['PSR12' => true, 'SlevomatCodingStandard.Functions.RequireTrailingCommaInCall' => true]),
	);
});


test('options of a rule that takes none are reported, not dropped in silence', function () {
	$translation = (new Translator)->translate(['no_spaces_around_offset' => ['positions' => ['inside']]]);
	Assert::same(['spacing.padding.offset' => 'forbidden'], $translation->decisions);
	Assert::same(
		['The options of `no_spaces_around_offset` were not translated; set them by hand, `dresscode explain spacing.padding.offset` describes them.'],
		$translation->warnings,
	);
});


test('a rule of blank lines translates to the places it counts, with the counts its options give', function () {
	$translate = fn(array $rules) => (new Translator)->translate($rules);
	Assert::same(
		['blankLines.afterNamespace' => 1, 'blankLines.beforeDeclaration' => 1],
		$translate(['PSR2.Namespaces.NamespaceDeclaration' => true])->decisions,
	);
	Assert::same(
		['blankLines.afterImports' => 1, 'blankLines.beforeDeclaration' => 1],
		$translate(['single_line_after_imports' => true])->decisions,
	);
	Assert::same(
		['blankLines.aroundMethods' => [1, 2], 'blankLines.aroundInterfaceMethods' => [1, 2]],
		$translate(['SlevomatCodingStandard.Classes.MethodSpacing' => ['maxLinesCount' => 2]])->decisions,
	);
	Assert::same(
		['blankLines.betweenMembers' => [0, 1], 'blankLines.betweenMemberKinds' => [0, 1], 'blankLines.beforeDocumentedMember' => 2],
		$translate(
			['SlevomatCodingStandard.Classes.ConstantSpacing' => [
				'minLinesCountBeforeWithComment' => 2,
				'maxLinesCountBeforeWithComment' => 2,
			]],
		)->decisions,
	);

	$translation = $translate(
		['class_attributes_separation' => ['elements' => ['method' => 'one', 'property' => 'only_if_meta', 'trait_import' => 'none']]],
	);
	Assert::same([
		'blankLines.aroundMethods' => 1,
		'blankLines.aroundInterfaceMethods' => 1,
		'blankLines.betweenTraitUses' => 0,
		'blankLines.betweenMembers' => 0, 'blankLines.betweenMemberKinds' => 0,
		'blankLines.beforeDocumentedMember' => 1,
	], $translation->decisions);
	Assert::same([], $translation->warnings);

	$translation = $translate(['no_extra_blank_lines' => ['tokens' => ['curly_brace_block', 'return', 'use_trait', 'extra']]]);
	Assert::same([
		'blankLines.bodyStart.function' => 0,
		'blankLines.bodyStart.closure' => 0,
		'blankLines.bodyStart.propertyHook' => 0,
		'blankLines.bodyStart.controlStructure' => 0,
		'blankLines.bodyEnd.function' => 0,
		'blankLines.bodyEnd.closure' => 0,
		'blankLines.bodyEnd.propertyHook' => 0,
		'blankLines.bodyEnd.controlStructure' => 0,
		'blankLines.afterStatement' => ['return' => 0],
		'blankLines.betweenTraitUses' => 0,
	], $translation->decisions);
	Assert::same([
		'`no_extra_blank_lines` with `return` removes the blank lines before the closing brace or the next `case` too, while `blankLines.afterStatement` counts only those before the next statement; set `blankLines.beforeBlockClosingBrace` and `blankLines.betweenCases` for those.',
		'`no_extra_blank_lines` with `extra` has no equivalent; DressCode counts the blank lines place by place in `blankLines`, with no limit of their own for these.',
	], $translation->warnings);

	$translation = $translate(['no_extra_blank_lines' => ['tokens' => ['attribute']]]);
	Assert::same(['blankLines.afterAttributes' => 0], $translation->decisions);
	Assert::same([], $translation->warnings);

	// a sniff of control structures asks for a blank line after one at least, and checks only their own braces and parentheses
	$translation = $translate(['Squiz.WhiteSpace.ControlStructureSpacing' => true]);
	Assert::same([1, null], $translation->decisions['blankLines.afterStatement']['if']);
	Assert::same([0, 'forbidden'], [
		$translation->decisions['blankLines.bodyStart.controlStructure'],
		$translation->decisions['spacing.padding.controlStructure'],
	]);
	Assert::same([], $translation->warnings);

	// the defaults of the fixer separate enum cases apart, which is not worth a warning, a separation chosen is
	$translation = $translate(['class_attributes_separation' => true]);
	Assert::same(
		['blankLines.betweenMembers' => [0, 1], 'blankLines.betweenMemberKinds' => [0, 1], 'blankLines.beforeDocumentedMember' => [0, 1]],
		array_intersect_key(
			$translation->decisions,
			['blankLines.betweenMembers' => 1, 'blankLines.betweenMemberKinds' => 1, 'blankLines.beforeDocumentedMember' => 1],
		),
	);
	Assert::same([], $translation->warnings);
	$translation = $translate(['class_attributes_separation' => ['elements' => ['const' => 'none', 'property' => 'one']]]);
	Assert::same(
		['blankLines.betweenMembers' => [0, 1], 'blankLines.betweenMemberKinds' => [0, 1], 'blankLines.beforeDocumentedMember' => [0, 1]],
		$translation->decisions,
	);
	Assert::match(
		'`class_attributes_separation` separates constants, properties and enum cases differently, %a%',
		$translation->warnings[0] ?? '',
	);

	// two sniffs counting the members differently widen the count to the range of both, in either order
	$rules = [
		'SlevomatCodingStandard.Classes.ConstantSpacing' => true,
		'SlevomatCodingStandard.Classes.PropertySpacing' => [
			'minLinesCountBeforeWithoutComment' => 1,
			'maxLinesCountBeforeWithoutComment' => 2,
		],
	];
	foreach ([$rules, array_reverse($rules, true)] as $order) {
		$translation = $translate($order);
		Assert::same(
			['blankLines.betweenMembers' => [0, 2], 'blankLines.betweenMemberKinds' => [0, 2], 'blankLines.beforeDocumentedMember' => 1],
			$translation->decisions,
		);
		Assert::same(
			[
				'The foreign rules count the blank lines of `blankLines.betweenMembers` differently; DressCode has one count there and takes the range of them all.',
				'The foreign rules count the blank lines of `blankLines.betweenMemberKinds` differently; DressCode has one count there and takes the range of them all.',
			],
			$translation->warnings,
		);
	}

	$translation = $translate([
		'SlevomatCodingStandard.Classes.ConstantSpacing' => true,
		'SlevomatCodingStandard.Classes.PropertySpacing' => true,
	]);
	Assert::same(
		['blankLines.betweenMembers' => [0, 1], 'blankLines.betweenMemberKinds' => [0, 1], 'blankLines.beforeDocumentedMember' => 1],
		$translation->decisions,
	);
	Assert::same([], $translation->warnings);

	// imports of different kinds keep the blank line another fixer asks for, in either order
	$rules = ['no_extra_blank_lines' => ['tokens' => ['use']], 'blank_line_between_import_groups' => true];
	foreach ([$rules, array_reverse($rules, true)] as $order) {
		Assert::same(1, $translate($order)->decisions['blankLines.betweenImportKinds']);
	}
});


test('a fixer of a declaration translates the parentheses, the comma and the brace of a function', function () {
	$translate = fn(array $rules) => (new Translator)->translate($rules);
	$translation = $translate(['function_declaration' => true]);
	Assert::same('forbidden', $translation->decisions['spacing.padding.argument']);
	Assert::same('optional', $translation->decisions['multiline.trailingComma.parameter']);
	Assert::same([], $translation->warnings);

	// a comma another fixer requires stays, in either order
	$rules = ['function_declaration' => true, 'trailing_comma_in_multiline' => ['elements' => ['parameters']]];
	foreach ([$rules, array_reverse($rules, true)] as $order) {
		Assert::same('required', $translate($order)->decisions['multiline.trailingComma.parameter']);
	}

	$translation = $translate(['function_declaration' => ['trailing_comma_single_line' => true]]);
	Assert::false(isset($translation->decisions['multiline.trailingComma.parameter']));
	Assert::same(
		['`function_declaration` with `trailing_comma_single_line=true` has no equivalent; DressCode removes the trailing comma of parameters on one line.'],
		$translation->warnings,
	);

	$translation = $translate(['braces_position' => true]);
	Assert::same(
		['nextLine', 'sameLine'],
		[$translation->decisions['braces.position.function'], $translation->decisions['braces.position.multilineSignature']],
	);
	$translation = $translate(['braces_position' => ['functions_opening_brace' => 'same_line']]);
	Assert::same(
		['sameLine', 'sameLine'],
		[$translation->decisions['braces.position.function'], $translation->decisions['braces.position.multilineSignature']],
	);

	// the destructuring of the fixer alone has no place of its own
	$translation = $translate(['trailing_comma_in_multiline' => ['elements' => ['array_destructuring']]]);
	Assert::same([], $translation->decisions);
	Assert::match(
		'`trailing_comma_in_multiline` with `array_destructuring` and without `arrays` has no equivalent; %a%',
		$translation->warnings[0] ?? '',
	);

	Assert::match(
		'`Squiz.ControlStructures.ForEachLoopDeclaration` also puts a single space around the `=>` of a `foreach`, %a%',
		$translate(['Squiz.ControlStructures.ForEachLoopDeclaration' => true])->warnings[0] ?? '',
	);
});


test('the fixers of the indentation and the line ending write those of the configuration object', function () {
	$translation = (new Translator)->translate(['indentation_type' => true, 'statement_indentation' => true, 'line_ending' => true]);
	Assert::same(['indentation.unit' => '4 spaces', 'file.lineEnding' => 'LF'], $translation->decisions);
	Assert::same([], $translation->warnings);

	$translation = (new Translator)->translate(['indentation_type' => true, 'line_ending' => true], "\t", "\r\n");
	Assert::same(['indentation.unit' => 'tab', 'file.lineEnding' => 'CRLF'], $translation->decisions);

	$translation = (new Translator)->translate(['indentation_type' => true], '   ');
	Assert::same([], $translation->decisions);
	Assert::same(
		["The fixers of the indentation indent by the `indent` '   ' of the configuration, which has no equivalent; DressCode indents by a tab, four spaces or two."],
		$translation->warnings,
	);

	$dir = createTempDir('translator');
	file_put_contents(
		"$dir/fixer.php",
		"<?php\nreturn new class {\n\tpublic function getRules(): array { return ['indentation_type' => true]; }\n\tpublic function getIndent(): string { return \"\\t\"; }\n\tpublic function getLineEnding(): string { return \"\\r\\n\"; }\n};\n",
	);
	Assert::same([['indentation_type' => true], "\t", "\r\n"], PhpCsFixer::readConfig("$dir/fixer.php"));
	file_put_contents("$dir/fixer.php", "<?php\nreturn ['indentation_type' => true];\n");
	Assert::same([['indentation_type' => true], '    ', "\n"], PhpCsFixer::readConfig("$dir/fixer.php"));
});


test('a sniff of the indentation translates to the unit it names, in either order', function () {
	$translate = fn(array $rules) => (new Translator)->translate($rules)->decisions;
	Assert::same(['indentation.unit' => 'tab'], $translate(['Generic.WhiteSpace.DisallowSpaceIndent' => true]));
	Assert::same(['indentation.unit' => '4 spaces'], $translate(['Generic.WhiteSpace.DisallowTabIndent' => true]));
	$rules = ['Generic.WhiteSpace.DisallowTabIndent' => true, 'Generic.WhiteSpace.ScopeIndent' => ['indent' => 2]];
	foreach ([$rules, array_reverse($rules, true)] as $order) {
		Assert::same(['indentation.unit' => '2 spaces'], $translate($order));
	}
});


test('a name of another tool stands for the decisions it sets', function () {
	$translator = new Translator;
	Assert::same(['spacing.cast'], $translator->findPaths('cast_spaces'));
	Assert::same(['literals.arraySyntax.array'], $translator->findPaths('array_syntax'));
	Assert::same([], $translator->findPaths('no_such_fixer'));
	Assert::contains('Squiz.Scope.MethodScope', $translator->findForeignNames($translator->findPaths('Squiz.Scope.MethodScope')));
	Assert::contains('cast_spaces', $translator->findForeignNames(['spacing.cast']));
	Assert::same([], $translator->findForeignNames(['no.such.decision']));
});


test('a translator over tables of its own', function () {
	$translator = new Translator(
		[
			'foo_bar' => ['acme.foo' => 'x'],
			'Acme.Bar' => fn(array $options, Translation $t) => $t
				->set('acme.bar', $options['x'] ?? 1)
				->set('acme.foo', 'x'),
		],
		['@Acme' => 'acme/preset'],
	);
	$translation = $translator->translate(['@Acme' => true, 'Acme.Bar' => ['x' => 2], 'cast_spaces' => true]);
	Assert::same(['acme/preset'], $translation->presets);
	Assert::same(['acme.bar' => 2, 'acme.foo' => 'x'], $translation->decisions);
	Assert::same(['No DressCode rule covers `cast_spaces`.'], $translation->warnings);
	Assert::same(['acme.bar', 'acme.foo'], $translator->findPaths('Acme.Bar'));
	Assert::same(['foo_bar', 'Acme.Bar'], $translator->findForeignNames(['acme.foo']));
});


test('a rule switched off makes what only it stands for keep, and leaves what another rule of the tool stands for too', function () {
	$translator = new Translator(
		[
			'a_one' => ['controlFlow.elseif' => 'oneWord'],
			'a_two' => ['controlFlow.elseif' => 'oneWord'],
			'b_only' => ['file.closingTagAtEnd' => 'forbidden'],
			'Acme.Cat.A' => ['controlFlow.elseif' => 'oneWord'],
		],
		['@Acme' => 'acme/preset'],
	);
	$translation = $translator->translate(['@Acme' => true, 'a_one' => false, 'b_only' => false]);
	Assert::same(['file.closingTagAtEnd' => 'keep'], $translation->decisions);
	Assert::same(
		['`a_one` is turned off, but `controlFlow.elseif` also stands for `a_two`, so it stays; set it to `keep` if none of them applies.'],
		$translation->warnings,
	);
	Assert::contains('closingTagAtEnd', $translation->toPhp());
	Assert::notContains('elseif', $translation->toPhp());

	// a sniff shares nothing with a fixer, and what some foreign rule sets stays set in either order
	Assert::same(['controlFlow.elseif' => 'keep'], $translator->translate(['Acme.Cat.A' => false])->decisions);
	Assert::same(['controlFlow.elseif' => 'oneWord'], $translator->translate(['a_one' => true, 'Acme.Cat.A' => false])->decisions);
	Assert::same(['controlFlow.elseif' => 'oneWord'], $translator->translate(['Acme.Cat.A' => false, 'a_one' => true])->decisions);

	// an exclusion of one message of a sniff cannot narrow what the sniff stands for
	$translation = $translator->translate(['Acme.Cat.A.Message' => false]);
	Assert::same([], $translation->decisions);
	Assert::same(
		['`Acme.Cat.A.Message` is excluded, but `controlFlow.elseif` cannot leave out that check alone, so it stays as the rest of the configuration sets it.'],
		$translation->warnings,
	);
});


test('a rule switched off under a set stays off in the configuration the translation writes', function () {
	$resolved = resolveTranslation((new Translator)->translate(['@PSR12' => true, 'no_closing_tag' => false]));
	Assert::true($resolved->decisions['file.closingTagAtEnd']->value->isKept());
	Assert::false(resolveTranslation((new Translator)->translate(['@PSR12' => true]))->decisions['file.closingTagAtEnd']->value->isKept());

	// declare_equal_normalize shares its decision with declare_parentheses, so it is said, not done
	$translation = (new Translator)->translate(['@PSR12' => true, 'declare_equal_normalize' => false]);
	Assert::same([], $translation->decisions);
	Assert::match(
		'`declare_equal_normalize` is turned off, but `spacing.declare` also stands for `declare_parentheses%a%',
		$translation->warnings[0] ?? '',
	);
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

	file_put_contents("$dir/phpcs.xml", '<ruleset><rule ref="Squiz.Scope.MethodScope"><severity>0</severity></rule></ruleset>');
	Assert::same([['Squiz.Scope.MethodScope' => false], []], PhpCodeSniffer::readConfig("$dir/phpcs.xml"));
});


test('a ruleset that is missing or broken is said so', function () {
	$dir = createTempDir('translator');
	Assert::exception(
		fn() => PhpCodeSniffer::readConfig("$dir/phpcs.xml"),
		ConfigurationException::class,
		'File `%a%phpcs.xml` does not exist.',
	);

	file_put_contents("$dir/phpcs.xml", "<ruleset>\n<rule ref=\"PSR12\">\n</ruleset>");
	Assert::exception(
		fn() => PhpCodeSniffer::readConfig("$dir/phpcs.xml"),
		ConfigurationException::class,
		'File `%a%phpcs.xml` is not a PHP_CodeSniffer ruleset: Opening and ending tag mismatch: rule line 2 and ruleset on line 3.',
	);
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
	$uncovered = [
		'Generic.PHP.DisallowAlternativePHPTags',
		'PSR1.Classes.ClassDeclaration',
		'PSR1.Files.SideEffects',
		'PSR12.Namespaces.CompoundNamespaceDepth',
	];
	Assert::same(
		$uncovered,
		array_values(array_filter($sniffs, fn($sniff) => in_array("No DressCode rule covers `$sniff`.", $translation->warnings, true))),
	);

	// what this one checks DressCode does by decisions that say more, so it says why instead
	$explained = ['Squiz.WhiteSpace.ScopeClosingBrace'];
	$translator = new Translator;
	foreach ($sniffs as $sniff) {
		Assert::same(in_array($sniff, [...$uncovered, ...$explained], true), $translator->findPaths($sniff) === [], $sniff);
	}

	Assert::noError(fn() => resolveTranslation($translation));
	Assert::same('required', $translation->decisions['classes.emptyParentheses.instantiation']);
	Assert::false(isset($translation->decisions['classes.emptyParentheses.anonymousClass']));
	Assert::same(['traitUse'], $translation->decisions['classes.members.order']);
	Assert::same('separated', $translation->decisions['classes.members.grouping.traitUse']);
	Assert::same('spaced', $translation->decisions['spacing.concatenation']);
});


test('the properties of the sniffs of PSR12 translate, or say they have no equivalent', function () {
	Assert::same(
		['multiline.operatorPosition.condition' => 'lineStart'],
		(new Translator)->translate(['PSR12.ControlStructures.BooleanOperatorPlacement' => ['allowOnly' => 'first']])->decisions,
	);

	// without `allowOnly` either place is allowed, which a preset requiring one does not override
	$translation = (new Translator)->translate(['PSR12' => true, 'PSR12.ControlStructures.BooleanOperatorPlacement' => true]);
	Assert::same(['multiline.operatorPosition.condition' => 'keep'], $translation->decisions);
	Assert::true(resolveTranslation($translation)->decisions['multiline.operatorPosition.condition']->value->isKept());

	Assert::same(
		['multiline.operatorPosition.condition' => 'lineEnd'],
		(new Translator)->translate(['PSR12.ControlStructures.BooleanOperatorPlacement' => ['allowOnly' => 'last']])->decisions,
	);

	$translation = (new Translator)->translate([
		'PSR2.Methods.FunctionCallSignature' => ['allowMultipleArguments' => true, 'requiredSpacesAfterOpen' => 1],
		'Squiz.ControlStructures.ForLoopDeclaration' => ['requiredSpacesBeforeClose' => 1],
	]);
	Assert::same([
		'`PSR2.Methods.FunctionCallSignature` with spaces inside the parentheses has no equivalent; DressCode writes none.',
		'`Squiz.ControlStructures.ForLoopDeclaration` with spaces inside the parentheses has no equivalent; DressCode writes none.',
	], $translation->warnings);
	Assert::same('fill', $translation->decisions['multiline.items.argument']);

	$translation = (new Translator)->translate(['method_argument_space' => ['on_multiline' => 'ignore']]);
	Assert::same([], $translation->warnings);
	Assert::false(isset($translation->decisions['multiline.items.argument']));
});


test('a message excluded from a sniff turns off its rule, which the sniff covers through that message', function () {
	$translation = (new Translator)->translate(['PSR12' => true, 'PSR2.ControlStructures.SwitchDeclaration.SpaceBeforeColonCASE' => false]);
	Assert::same(['spacing.switchCase' => 'keep'], $translation->decisions);
	Assert::same([], $translation->warnings);

	// the sniff excluded whole turns off what only it and its messages cover, and says what another sniff covers too
	$translation = (new Translator)->translate(['PSR12' => true, 'PSR2.ControlStructures.SwitchDeclaration' => false]);
	Assert::same(
		[
			'upgrading.php.caseSemicolon' => 'keep',
			'spacing.switchCase' => 'keep',
			'controlFlow.switch.fallThroughComment' => 'keep',
			'indentation.switchCase' => 'keep',
		],
		$translation->decisions,
	);
	Assert::match(
		'`PSR2.ControlStructures.SwitchDeclaration` is turned off, but `casing.keyword`, %a% also stand for `Generic.PHP.LowerCaseKeyword`, `Generic.PHP.LowerCaseType`%a%',
		$translation->warnings[0] ?? '',
	);

	// a sniff turned off in the configuration stands for nothing there
	$translation = (new Translator)->translate([
		'PSR12' => true,
		'Generic.WhiteSpace.DisallowTabIndent' => false,
		'Generic.WhiteSpace.DisallowSpaceIndent' => false,
	]);
	Assert::same([
		'`Generic.WhiteSpace.DisallowTabIndent` is turned off, but `indentation.unit` also stands for `Generic.WhiteSpace.ScopeIndent`, so it stays; set it to `keep` if none of them applies.',
		'`Generic.WhiteSpace.DisallowSpaceIndent` is turned off, but `indentation.unit` also stands for `Generic.WhiteSpace.ScopeIndent`, so it stays; set it to `keep` if none of them applies.',
	], $translation->warnings);

	// and without a standard only the sniffs the configuration names run
	$translation = (new Translator)->translate(['Generic.WhiteSpace.DisallowTabIndent' => false, 'Squiz.Scope.MethodScope' => true]);
	Assert::same(['indentation.unit' => 'keep'], array_intersect_key($translation->decisions, ['indentation.unit' => 1]));
	Assert::same([], $translation->warnings);
});


test('a .php-cs-fixer.php that cannot be run is an error of the configuration, not the end of the run', function () {
	$dir = createTempDir('translator');

	// a class it misses is most likely PHP CS Fixer, which only the dresscode of the project sees
	file_put_contents("$dir/.php-cs-fixer.php", "<?php\nreturn (new Acme\\Missing\\Config)->setRules(['@PSR12' => true]);\n");
	Assert::exception(
		fn() => PhpCsFixer::readConfig("$dir/.php-cs-fixer.php"),
		ConfigurationException::class,
		'File `%a%.php-cs-fixer.php` needs the classes of PHP CS Fixer, which this dresscode cannot load; run the `vendor/bin/dresscode` of the project that has PHP CS Fixer installed: Class "Acme\\Missing\\Config" not found.',
	);

	// any other error of the file is said as it is
	file_put_contents("$dir/.php-cs-fixer.php", "<?php\nreturn [\n");
	Assert::exception(
		fn() => PhpCsFixer::readConfig("$dir/.php-cs-fixer.php"),
		ConfigurationException::class,
		"File `%a%.php-cs-fixer.php` cannot be read: Unclosed '[' on line 2.",
	);
});


test('the configuration is written as a dresscode.php, a rule written as its decisions by them', function () {
	$translation = (new Translator)->translate(['@PSR12' => true, 'cast_spaces' => ['space' => 'none'], 'elseif' => true]);
	Assert::same(
		"<?php declare(strict_types=1);\n\n"
		. "use DressCode\\Config;\n\n"
		. "return new Config(\n"
		. "\tuse: ['psr12'],\n"
		. "\tdecisions: [\n"
		. "\t\t'spacing' => [\n"
		. "\t\t\t'cast' => '(int)\$x',\n"
		. "\t\t],\n"
		. "\t\t'controlFlow' => [\n"
		. "\t\t\t'elseif' => 'elseif',\n"
		. "\t\t],\n"
		. "\t],\n"
		. ");\n",
		$translation->toPhp(),
	);

	// a list of plain values stays on its line, an empty map too
	$translation = (new Translator)->translate(['blank_line_before_statement' => ['statements' => ['return']], 'phpdoc_trim' => true]);
	Assert::contains("\t\t\t'beforeStatement' => [\n\t\t\t\t'return' => '1+',\n\t\t\t],\n", $translation->toPhp());
	$translation->set('blankLines.afterStatement', []);
	Assert::contains("\t\t\t'afterStatement' => [],\n", $translation->toPhp());

	// a map with numbers for keys keeps them
	$translation = new Translation;
	$translation->set('acme.map', [2 => 'a', 5 => 'b']);
	Assert::contains("\t\t\t'map' => [\n\t\t\t\t2 => 'a',\n\t\t\t\t5 => 'b',\n\t\t\t],\n", $translation->toPhp());
});


test('two foreign rules writing names merge into the decisions of the qualification they mean together', function () {
	$translation = (new Translator)->translate(['global_namespace_import' => true, 'native_function_invocation' => true]);
	Assert::equal([
		'globalClass' => 'imported',
		'globalFunction' => 'backslashed',
		'globalConstant' => 'backslashed', // the fixer imports no constant by default
		'optimizedFunction' => 'backslashed',
	], $translation->qualification['shape']);
	Assert::equal(['optimizedFunction' => 'qualified', 'function' => 'bare'], $translation->qualification['fallback']);
	Assert::same([], $translation->decisions);

	Assert::same(
		"<?php declare(strict_types=1);\n\n"
		. "use DressCode\\Config;\n\n"
		. "return new Config(\n"
		. "\tdecisions: [\n"
		. "\t\t'qualification' => [\n"
		. "\t\t\t'class' => [\n"
		. "\t\t\t\t'global' => 'imported',\n"
		. "\t\t\t],\n"
		. "\t\t\t'function' => [\n"
		. "\t\t\t\t'global' => 'bare',\n"
		. "\t\t\t\t'optimized' => 'backslashed',\n"
		. "\t\t\t],\n"
		. "\t\t\t'constant' => [\n"
		. "\t\t\t\t'global' => ['backslashed', 'bare'],\n"
		. "\t\t\t\t'optimized' => ['backslashed', 'bare'],\n"
		. "\t\t\t],\n"
		. "\t\t],\n"
		. "\t],\n"
		. ");\n",
		$translation->toPhp(),
	);
	Assert::contains('qualification.function.global', $translation->getPaths());
	Assert::noError(fn() => resolveTranslation($translation));
});


test('the casing of the classes and the functions of PHP says the decision covers those of the project too with the types', function () {
	$translation = (new Translator)->translate(['class_reference_name_casing' => true, 'native_function_casing' => true]);
	Assert::same(['casing.class' => 'declared', 'casing.function' => 'declared'], $translation->decisions);
	Assert::same([
		'`class_reference_name_casing` writes the classes PHP declares, while `casing.class` writes those of the project and its packages too where the configuration sets `typeAnalysis`.',
		'`native_function_casing` writes the functions PHP declares, while `casing.function` writes those of the project and its packages too where the configuration sets `typeAnalysis`.',
	], $translation->warnings);
});


test('fully_qualified_strict_types writes a class of the namespace of the file bare and one of another namespace imported', function () {
	$decisions = resolveTranslation((new Translator)->translate(['fully_qualified_strict_types' => ['import_symbols' => true]]))->decisions;
	Assert::same('imported', $decisions['qualification.class.otherNamespace']->value->toWrittenData());
	Assert::same('bare', $decisions['qualification.class.currentNamespace']->value->toWrittenData());
});


test('native_function_invocation takes the backslash from a function it does not write it before only with strict', function () {
	$qualification = function (array $options): array {
		$decisions = resolveTranslation((new Translator)->translate(
			['native_function_invocation' => $options + ['scope' => 'namespaced']],
		))->decisions;
		return [
			$decisions['qualification.function.global']->value->toWrittenData(),
			$decisions['qualification.function.optimized']->value->toWrittenData(),
		];
	};
	Assert::same(['bare', 'backslashed'], $qualification([]));
	Assert::same(['keep', 'backslashed'], $qualification(['strict' => false]));
	Assert::same(['backslashed', 'backslashed'], $qualification(['include' => ['@all']]));
});


test('a function or a constant the fixers and the sniffs name one by one follows its group, which the translation says', function () {
	$cases = [
		[['native_function_invocation' => ['include' => ['@all'], 'exclude' => ['dump'], 'scope' => 'namespaced']],
			['optimizedFunction' => 'backslashed', 'globalFunction' => 'backslashed'],
			['function' => 'qualified'],
			['`native_function_invocation` names functions one by one (`dump`), which DressCode decides by group; a function named follows `qualification.function.global`, or `qualification.function.optimized` where the compiler optimizes it.']],
		[['native_function_invocation' => [
			'include' => ['@compiler_optimized', 'dump'],
			'exclude' => ['var_dump'],
			'strict' => false,
			'scope' => 'namespaced',
		]],
			['optimizedFunction' => 'backslashed'],
			['optimizedFunction' => 'qualified'],
			['`native_function_invocation` names functions one by one (`dump`, `var_dump`), which DressCode decides by group; a function named follows `qualification.function.global`, or `qualification.function.optimized` where the compiler optimizes it.']],
		// fix_built_in asks for the constants PHP declares, qualified where the compiler computes with them, and strict
		// takes the backslash from the rest
		[['native_constant_invocation' => ['scope' => 'namespaced']],
			['optimizedConstant' => 'backslashed'],
			['optimizedConstant' => 'qualified', 'constant' => 'bare'],
			['`native_constant_invocation` with `fix_built_in` writes the backslash before every constant of PHP; DressCode writes it before those the compiler computes with.']],
		[['native_constant_invocation' => [
			'fix_built_in' => false,
			'include' => ['PHP_EOL'],
			'exclude' => ['DEBUG', 'null'],
			'scope' => 'namespaced',
		]],
			['optimizedConstant' => 'backslashed'],
			['constant' => 'bare'],
			['`native_constant_invocation` names constants one by one (`PHP_EOL`, `DEBUG`), which DressCode decides by group; a constant named follows `qualification.constant.global`, or `qualification.constant.optimized` where the compiler computes with it.']],
		// the special functions join an empty include instead of naming every function
		[['SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalFunctions' => ['includeSpecialFunctions' => true]],
			['optimizedFunction' => 'backslashed'],
			['optimizedFunction' => 'qualified'],
			[]],
		// an include names functions one by one, so nothing is translated but the warning
		[['SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalFunctions' => ['include' => ['dump'], 'exclude' => ['var_dump']]],
			[],
			[],
			['`SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalFunctions` names functions one by one (`dump`, `var_dump`), which DressCode decides by group; a function named follows `qualification.function.global`, or `qualification.function.optimized` where the compiler optimizes it.']],
		[['SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalConstants' => ['include' => ['PHP_EOL'], 'exclude' => ['DEBUG']]],
			[],
			[],
			['`SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalConstants` names constants one by one (`PHP_EOL`, `DEBUG`), which DressCode decides by group; a constant named follows `qualification.constant.global`.']],
		[['SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly' => [
			'allowFullyQualifiedGlobalFunctions' => true,
			'allowFallbackGlobalConstants' => false,
		]],
			[
				'class' => 'imported',
				'globalClass' => 'imported',
				'function' => 'imported',
				'globalFunction' => 'keep',
				'constant' => 'imported',
				'globalConstant' => 'imported',
			],
			['constant' => 'qualified'],
			[]],
	];
	foreach ($cases as [$rules, $notation, $fallback, $warnings]) {
		$translation = (new Translator)->translate($rules);
		Assert::equal($notation, $translation->qualification['shape']);
		Assert::equal($fallback, $translation->qualification['fallback']);
		Assert::same($warnings, $translation->warnings);
		Assert::noError(fn() => resolveTranslation($translation));
	}
});
