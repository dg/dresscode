<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Interop;

use DressCode\ConfigurationException;
use function array_key_exists, in_array, is_array, is_object;


/**
 * What the rules of PHP CS Fixer mean in DressCode: the fixers of friendsofphp/php-cs-fixer and the custom fixers
 * of kubawerlos, which share the snake_case name, the shape of the options and the .php-cs-fixer.php configuration.
 * @internal
 */
final class PhpCsFixer
{
	/**
	 * @return array<string, string|\Closure(array<string, mixed>, Translation): mixed>  fixer => rule name, or what to
	 *     enable for its options; every option is read through `??` so that a fixer enabled with none translates too
	 */
	public static function getTranslations(): array
	{
		return [
			'PhpCsFixerCustomFixers/declare_after_opening_tag' => fn(array $o, Translation $t) => $t->enable('dresscode/strictTypesRequired', ['placement' => 'openingTagLine']),
			'PhpCsFixerCustomFixers/comment_surrounded_by_spaces' => 'dresscode/commentSpacing',
			'PhpCsFixerCustomFixers/commented_out_function' => fn(array $o, Translation $t) => $t->enable('dresscode/commentedOutFunction', array_filter(['functions' => $o['functions'] ?? null], fn($v) => $v !== null)),
			'PhpCsFixerCustomFixers/no_leading_slash_in_global_namespace' => 'dresscode/uselessBackslashInGlobalNamespace',
			'PhpCsFixerCustomFixers/no_superfluous_concatenation' => 'dresscode/uselessStringConcat',
			'PhpCsFixerCustomFixers/no_useless_dirname_call' => 'dresscode/noDirnameOfFile',
			'PhpCsFixerCustomFixers/no_useless_strlen' => 'dresscode/noManualEmptyStringTests',
			'PhpCsFixerCustomFixers/numeric_literal_separator' => 'dresscode/numericLiteralSeparator',
			'PhpCsFixerCustomFixers/phpdoc_array_style' => fn(array $o, Translation $t) => $t->enable('dresscode/phpdocCanonicalTypes', ['arrayNotation' => 'generic']),
			'PhpCsFixerCustomFixers/phpdoc_type_list' => function (array $o, Translation $t) {
				$t->warn('`phpdoc_type_list` turns `array<T>` into `list<T>`, a stronger type, which DressCode does not do.');
				$t->enable('dresscode/phpdocCanonicalTypes');
			},
			'array_indentation' => 'dresscode/indentation',
			'array_syntax' => fn(array $o, Translation $t) => ($o['syntax'] ?? 'short') === 'short'
				? $t->enable('dresscode/shortArraySyntax')
				: $t->warn('`array_syntax` with `syntax=long` has no equivalent; DressCode writes the short syntax only.'),
			'assign_null_coalescing_to_coalesce_equal' => 'dresscode/combinedAssignmentForRepeatedTarget',
			'attribute_block_no_spaces' => 'dresscode/attributeSpacing',
			'attribute_empty_parentheses' => fn(array $o, Translation $t) => ($o['use_parentheses'] ?? false)
				? $t->warn('`attribute_empty_parentheses` with `use_parentheses=true` has no equivalent; DressCode removes empty parentheses.')
				: $t->enable('dresscode/uselessAttributeParentheses'),
			'backtick_to_shell_exec' => 'dresscode/noBacktickOperators',
			'binary_operator_spaces' => function (array $o, Translation $t) {
				$default = $o['default'] ?? 'single_space';
				if (str_starts_with((string) $default, 'align')) {
					$t->warn('`binary_operator_spaces` aligns operators; DressCode only keeps an alignment that is already there.');
				}
				$t->enable('dresscode/binaryOperatorSpacing', [
					'alignment' => $default === 'single_space' ? 'none' : 'spaces',
				]);
			},
			'blank_line_after_namespace' => fn(array $o, Translation $t) => $t->enable('dresscode/blankLines', ['afterNamespace' => 1]),
			'blank_line_after_opening_tag' => fn(array $o, Translation $t) => $t->enable('dresscode/blankLines', ['afterOpeningTag' => 1]),
			'blank_line_before_statement' => function (array $o, Translation $t) {
				$statements = $o['statements'] ?? ['break', 'continue', 'declare', 'return', 'throw', 'try'];
				$supported = ['break', 'continue', 'do', 'for', 'foreach', 'if', 'return', 'switch', 'throw', 'try', 'while', 'yield'];
				foreach (array_diff($statements, $supported) as $statement) {
					$t->warn("`blank_line_before_statement` with `$statement` has no equivalent; DressCode knows no such statement kind.");
				}
				$t->enable('dresscode/blankLines', ['before' => array_fill_keys(array_intersect($statements, $supported), [1, null])]);
			},
			'blank_line_between_import_groups' => fn(array $o, Translation $t) => $t->enable('dresscode/blankLines', ['betweenImportGroups' => 1]),
			'blank_lines_before_namespace' => fn(array $o, Translation $t) => $t->enable('dresscode/blankLines', [
				'beforeNamespace' => max(0, ($o['min_line_breaks'] ?? 2) - 1),
			]),
			'braces_position' => fn(array $o, Translation $t) => $t->enable('dresscode/bracesPosition', [
				'class' => ($o['classes_opening_brace'] ?? 'next_line') === 'same_line' ? 'sameLine' : 'nextLine',
				'anonymousClass' => ($o['anonymous_classes_opening_brace'] ?? 'same_line') === 'same_line' ? 'sameLine' : 'nextLine',
				'anonymousFunction' => ($o['anonymous_functions_opening_brace'] ?? 'same_line') === 'same_line' ? 'sameLine' : 'nextLine',
				'controlStructure' => ($o['control_structures_opening_brace'] ?? 'same_line') === 'same_line' ? 'sameLine' : 'nextLine',
				'singlelineAnonymousFunction' => ($o['allow_single_line_anonymous_functions'] ?? true) ? 'keep' : 'always',
				'emptyAnonymousClass' => ($o['allow_single_line_empty_anonymous_classes'] ?? true) ? 'sameLine' : 'ownLine',
			]),
			'cast_spaces' => fn(array $o, Translation $t) => $t->enable('dresscode/castSpacing', ['spacing' => $o['space'] ?? 'single']),
			'class_definition' => fn(array $o, Translation $t) => $t->enable('dresscode/classDefinitionSpacing', [
				'beforeParenthesis' => ($o['space_before_parenthesis'] ?? false) ? 'single' : 'none',
			]),
			'class_keyword' => 'dresscode/classNameReferenceForString',
			'class_reference_name_casing' => 'dresscode/nativeClassCasing',
			'combine_consecutive_issets' => 'dresscode/combinedIssets',
			'combine_nested_dirname' => 'dresscode/noDirnameOfFile',
			'combine_consecutive_unsets' => 'dresscode/combinedUnsets',
			'compact_nullable_type_declaration' => 'dresscode/typeHintSpacing',
			'concat_space' => fn(array $o, Translation $t) => $t->enable('dresscode/concatSpacing', ['spacing' => ($o['spacing'] ?? 'none') === 'one' ? 'single' : 'none']),
			'constant_case' => fn(array $o, Translation $t) => ($o['case'] ?? 'lower') === 'lower'
				? $t->enable('dresscode/trueFalseNullCasing')
				: $t->warn('`constant_case` with `case=upper` has no equivalent; DressCode writes `true`, `false` and `null` in lower case.'),
			'control_structure_braces' => 'dresscode/controlStructureBraces',
			'control_structure_continuation_position' => fn(array $o, Translation $t) => $t->enable('dresscode/bracesPosition', [
				'continuation' => ($o['position'] ?? 'same_line') === 'next_line' ? 'nextLine' : 'sameLine',
			]),
			'declare_equal_normalize' => fn(array $o, Translation $t) => ($o['space'] ?? 'none') === 'none'
				? $t->enable('dresscode/declareSpacing')
				: $t->warn('`declare_equal_normalize` with `space=single` has no equivalent; DressCode writes `declare(strict_types=1)` without spaces.'),
			'declare_parentheses' => 'dresscode/declareSpacing',
			'declare_strict_types' => fn(array $o, Translation $t) => ($o['strategy'] ?? 'enforce') === 'remove'
				? $t->warn('`declare_strict_types` with `strategy=remove` has no equivalent; DressCode requires the declaration.')
				: $t->enable('dresscode/strictTypesRequired'),
			'dir_constant' => 'dresscode/noDirnameOfFile',
			'elseif' => 'dresscode/elseifKeyword',
			'encoding' => 'dresscode/noBom',
			'escape_implicit_backslashes' => 'dresscode/noImplicitBackslashes',
			'final_internal_class' => fn(array $o, Translation $t) => $t->enable('dresscode/finalInternalClass', array_filter([
				'requiredAnnotations' => $o['annotation_include'] ?? null,
				'exemptAnnotations' => $o['annotation_exclude'] ?? null,
			], fn($v) => $v !== null)),
			'full_opening_tag' => 'dresscode/fullOpeningTag',
			'fully_qualified_strict_types' => function (array $o, Translation $t) {
				if (!($o['import_symbols'] ?? false)) {
					$t->warn('`fully_qualified_strict_types` without `import_symbols` shortens a name only to an import the file has; DressCode adds the import it lacks.');
				}

				$t->enable('dresscode/nameNotation', ['class' => 'import', 'globalClass' => 'keep']);
			},
			'function_declaration' => 'dresscode/constructSpacing',
			'global_namespace_import' => function (array $o, Translation $t) {
				// true imports a name written with the backslash, false writes the backslash, null leaves the name alone, and
				// a bare function or constant stands either way; a function or a constant is written as a map, so that it
				// merges with the fixers naming them one by one
				$shape = fn(?bool $import) => match ($import) {
					true => 'import',
					false => 'backslash',
					null => 'keep',
				};
				$t->enable('dresscode/nameNotation', [
					'globalClass' => $shape(array_key_exists('import_classes', $o) ? $o['import_classes'] : true),
					'globalFunction' => ['*' => $shape(array_key_exists('import_functions', $o) ? $o['import_functions'] : false)],
					'globalConstant' => ['*' => $shape(array_key_exists('import_constants', $o) ? $o['import_constants'] : false)],
				]);
			},
			// the fixer groups whatever a namespace has, so two names are enough, and it keeps the group it made
			'group_import' => fn(array $o, Translation $t) => $t->enable('dresscode/groupImport', ['minImports' => 2])
				->enable('dresscode/importNotation', ['group' => 'keep']),
			'heredoc_indentation' => fn(array $o, Translation $t) => $t->enable('dresscode/heredocIndentation', [
				'indentation' => ($o['indentation'] ?? 'start_plus_one') === 'start_plus_one' ? 'startPlusOne' : 'sameAsStart',
			]),
			'heredoc_to_nowdoc' => 'dresscode/nowdocWithoutInterpolation',
			'include' => 'dresscode/uselessConstructParentheses',
			'indentation_type' => 'dresscode/indentation',
			'is_null' => 'dresscode/noIsNull',
			'line_ending' => 'dresscode/lineEnding',
			'list_syntax' => fn(array $o, Translation $t) => ($o['syntax'] ?? 'short') === 'short'
				? $t->enable('dresscode/shortArraySyntax')
				: $t->warn('`list_syntax` with `syntax=long` has no equivalent; DressCode writes the short syntax only.'),
			'lowercase_cast' => 'dresscode/castCanonicalType',
			'lowercase_keywords' => 'dresscode/keywordCasing',
			'lowercase_static_reference' => 'dresscode/keywordCasing',
			'magic_constant_casing' => 'dresscode/magicConstantCasing',
			'method_chaining_indentation' => 'dresscode/indentation',
			'method_argument_space' => fn(array $o, Translation $t) => ($o['on_multiline'] ?? 'ensure_fully_multiline') === 'ensure_fully_multiline'
				? $t->enable('dresscode/multilineCall')
				: $t->warn("`method_argument_space` with `on_multiline={$o['on_multiline']}` has no equivalent; DressCode always puts each argument on its own line."),
			'modernize_strpos' => function (array $o, Translation $t) {
				if ($o['modernize_stripos'] ?? false) {
					$t->warn('`modernize_strpos` with `modernize_stripos` rewrites `stripos()` too, which DressCode leaves alone, PHP having no case-insensitive `str_contains()`.');
				}

				$t->enable('dresscode/noManualSubstringTests');
			},
			'modernize_types_casting' => 'dresscode/noConversionFunctions',
			'modifier_keywords' => 'dresscode/visibilityRequired',
			'multiline_promoted_properties' => fn(array $o, Translation $t) => $t->enable('dresscode/multilineSignature', ['promotedProperty' => 'always']),
			'native_function_casing' => 'dresscode/nativeFunctionCasing',
			'new_expression_parentheses' => 'dresscode/uselessParenthesesAroundNew',
			'new_with_braces' => fn(array $o, Translation $t) => $t->enable('dresscode/newArgumentParentheses', [
				'namedClass' => ($o['named_class'] ?? true) ? 'required' : 'forbidden',
				'anonymousClass' => ($o['anonymous_class'] ?? true) ? 'required' : 'forbidden',
			]),
			'new_with_parentheses' => fn(array $o, Translation $t) => $t->enable('dresscode/newArgumentParentheses', [
				'namedClass' => ($o['named_class'] ?? true) ? 'required' : 'forbidden',
				'anonymousClass' => ($o['anonymous_class'] ?? true) ? 'required' : 'forbidden',
			]),
			'native_function_invocation' => function (array $o, Translation $t) {
				if (($o['scope'] ?? 'all') === 'all') {
					$t->warn('`native_function_invocation` with `scope=all` writes the backslash in the global namespace too, where DressCode writes none.');
				}

				$options = [];
				$functions = [];
				foreach ($o['include'] ?? ['@compiler_optimized'] as $name) {
					if ($name === '@compiler_optimized') {
						$options['optimizedFunction'] = 'qualified';

					} elseif ($name === '@internal' || $name === '@all') {
						if ($name === '@internal') {
							$t->warn('`native_function_invocation` with `@internal` writes the backslash before a global function of the project too; DressCode has no set of internal functions.');
						}

						$functions['*'] = 'qualified';

					} else {
						$functions[$name] = 'qualified';
					}
				}

				// strict takes the backslash from every function the include does not reach, an excluded one among them
				$strict = $o['strict'] ?? true;
				foreach ($o['exclude'] ?? [] as $name) {
					$functions[$name] = $strict ? 'fallback' : 'keep';
				}

				if ($strict) {
					$functions['*'] ??= 'fallback';
				}

				// the fixer qualifies with the backslash
				$t->enable('dresscode/nameNotation', ['globalFunction' => ['*' => 'backslash']]);
				$t->enable('dresscode/nameFallback', $options + ($functions === [] ? [] : ['function' => $functions]));
			},
			'native_constant_invocation' => function (array $o, Translation $t) {
				if (($o['scope'] ?? 'all') === 'all') {
					$t->warn('`native_constant_invocation` with `scope=all` writes the backslash in the global namespace too, where DressCode writes none.');
				}

				// true, false and null are left alone by the rule, so the default exclusion needs no entry
				$options = ($o['fix_built_in'] ?? true) ? ['optimizedConstant' => 'qualified'] : [];
				$constants = [];
				foreach ($o['include'] ?? [] as $name) {
					$constants[$name] = 'qualified';
				}

				// strict takes the backslash from every constant the fixer does not escape, an excluded one among them
				$strict = $o['strict'] ?? true;
				foreach (array_diff($o['exclude'] ?? [], ['null', 'false', 'true']) as $name) {
					$constants[$name] = $strict ? 'fallback' : 'keep';
				}

				if ($strict) {
					$constants['*'] = 'fallback';
				}

				// the fixer qualifies with the backslash
				$t->enable('dresscode/nameNotation', ['globalConstant' => ['*' => 'backslash']]);
				if ($options !== [] || $constants !== []) {
					$t->enable('dresscode/nameFallback', $options + ($constants === [] ? [] : ['constant' => $constants]));
				}
			},
			'no_alias_functions' => function (array $o, Translation $t) {
				$sets = $o['sets'] ?? null;
				foreach (array_intersect($sets ?? [], ['@mbreg', '@exif']) as $set) {
					$t->warn("`no_alias_functions` with `$set` has no equivalent; PHP 8 has none of the aliases it replaces.");
				}

				$sets = array_map(fn(string $set) => strtolower(ltrim($set, '@')), array_values(array_diff($sets ?? [], ['@mbreg', '@exif'])));
				$t->enable('dresscode/noAliasFunctions', ($o['sets'] ?? null) === null ? [] : ['sets' => $sets]);
			},
			'no_alternative_syntax' => 'dresscode/noAlternativeSyntax',
			'no_blank_lines_after_class_opening' => 'dresscode/blankLines',
			'no_blank_lines_after_phpdoc' => fn(array $o, Translation $t) => $t->enable('dresscode/blankLines', ['afterPhpdoc' => 0]),
			'no_break_comment' => fn(array $o, Translation $t) => $t->enable('dresscode/fallThroughComment', array_filter(['comment' => $o['comment_text'] ?? null], fn($v) => $v !== null)),
			'no_closing_tag' => 'dresscode/noClosingTag',
			'no_empty_comment' => 'dresscode/noEmptyComments',
			'no_empty_phpdoc' => 'dresscode/noEmptyPhpdocs',
			'no_empty_statement' => 'dresscode/noEmptyStatements',
			'no_extra_blank_lines' => 'dresscode/blankLines',
			'no_leading_import_slash' => 'dresscode/uselessImportBackslash',
			'no_leading_namespace_whitespace' => 'dresscode/indentation',
			'no_multiple_statements_per_line' => 'dresscode/singleStatementPerLine',
			'no_null_property_initialization' => 'dresscode/uselessNullInitialization',
			'no_redundant_readonly_property' => 'dresscode/uselessModifier',
			'no_short_bool_cast' => 'dresscode/noShortBoolCasts',
			'no_singleline_whitespace_before_semicolons' => fn(array $o, Translation $t) => $t->enable('dresscode/semicolonSpacing', ['allowOwnLine' => true]),
			'no_space_around_double_colon' => 'dresscode/doubleColonSpacing',
			'no_spaces_after_function_name' => 'dresscode/functionNameSpacing',
			'no_spaces_around_offset' => 'dresscode/offsetBracketSpacing',
			'no_spaces_inside_parenthesis' => 'dresscode/parenthesesSpacing',
			'no_trailing_comma_in_singleline' => function (array $o, Translation $t) {
				// a place another fixer asked to be required stays so, which removes the one-line comma as well
				$current = $t->rules['dresscode/trailingComma'] ?? null;
				$places = ['arguments' => 'argument', 'array' => 'array', 'array_destructuring' => 'list', 'group_import' => 'import'];
				$options = [];
				foreach ($o['elements'] ?? array_keys($places) as $element) {
					if (isset($places[$element])) {
						$options[$places[$element]] = is_array($current) ? $current[$places[$element]] ?? 'optional' : 'optional';
					}
				}

				$t->enable('dresscode/trailingComma', $options);
			},
			'no_trailing_whitespace' => 'dresscode/noTrailingWhitespace',
			'no_trailing_whitespace_in_comment' => 'dresscode/noTrailingWhitespace',
			'no_trailing_whitespace_in_string' => 'dresscode/noTrailingWhitespaceInString',
			'no_unneeded_braces' => function (array $o, Translation $t) {
				if ($o['namespaces'] ?? false) {
					$t->warn('`no_unneeded_braces` with `namespaces=true` also unwraps a braced namespace, which DressCode leaves alone.');
				}
				$t->enable('dresscode/uselessBraces');
			},
			'no_unneeded_control_parentheses' => 'dresscode/uselessConstructParentheses',
			'no_unneeded_final_method' => function (array $o, Translation $t) {
				if (($o['private_methods'] ?? true) === false) {
					$t->warn('`no_unneeded_final_method` with `private_methods=false` keeps `final` on a private method, which DressCode removes anyway.');
				}

				$t->enable('dresscode/uselessModifier');
			},
			'no_unneeded_curly_braces' => function (array $o, Translation $t) {
				if ($o['namespaces'] ?? false) {
					$t->warn('`no_unneeded_curly_braces` with `namespaces=true` also unwraps a braced namespace, which DressCode leaves alone.');
				}
				$t->enable('dresscode/uselessBraces');
			},
			'no_unreachable_default_argument_value' => 'dresscode/uselessParameterDefault',
			'no_unused_imports' => 'dresscode/unusedImports',
			'no_superfluous_elseif' => fn(array $o, Translation $t) => $t->enable('dresscode/uselessElse', ['elseif' => true]),
			'no_useless_else' => 'dresscode/uselessElse',
			'no_useless_return' => 'dresscode/uselessReturn',
			'no_whitespace_before_comma_in_array' => 'dresscode/commaSpacing',
			'no_whitespace_in_blank_line' => 'dresscode/noTrailingWhitespace',
			'non_printable_character' => function (array $o, Translation $t) {
				if (!($o['use_escape_sequences_in_strings'] ?? true)) {
					$t->warn('`non_printable_character` without escape sequences has no equivalent; DressCode writes the character as an escape sequence to keep the value.');
				}
				$t->enable('dresscode/noInvisibleCharacters');
			},
			'numeric_literal_separator' => function (array $o, Translation $t) {
				if (($o['strategy'] ?? 'use_separator') === 'no_separator') {
					$t->warn('`numeric_literal_separator` with `strategy=no_separator` has no equivalent; DressCode adds the separator.');
				}
				$t->enable('dresscode/numericLiteralSeparator');
			},
			'nullable_type_declaration_for_default_null_value' => fn(array $o, Translation $t) => ($o['use_nullable_type_declaration'] ?? true)
				? $t->enable('dresscode/nullableTypeForDefaultNull')
				: $t->warn('`nullable_type_declaration_for_default_null_value` with `use_nullable_type_declaration=false` has no equivalent; DressCode adds the `?` instead of removing it.'),
			'object_operator_without_whitespace' => 'dresscode/objectOperatorSpacing',
			'octal_notation' => 'dresscode/octalNotation',
			'ordered_class_elements' => function (array $o, Translation $t) {
				if (($o['sort_algorithm'] ?? 'none') !== 'none') {
					$t->warn('`ordered_class_elements` sorts members by name; DressCode orders them by kind only.');
				}
				$kinds = [
					'use_trait' => 'traitUse', 'constant_public' => 'publicConstant', 'constant_protected' => 'protectedConstant',
					'constant_private' => 'privateConstant', 'property_public' => 'publicProperty', 'property_protected' => 'protectedProperty',
					'property_private' => 'privateProperty', 'property_public_static' => 'publicStaticProperty',
					'property_protected_static' => 'protectedStaticProperty', 'property_private_static' => 'privateStaticProperty',
					'method_public' => 'publicMethod', 'method_protected' => 'protectedMethod', 'method_private' => 'privateMethod',
					'method_public_static' => 'publicStaticMethod', 'method_protected_static' => 'protectedStaticMethod',
					'method_private_static' => 'privateStaticMethod', 'construct' => 'constructor', 'destruct' => 'destructor',
					'magic' => 'magicMethod', 'case' => 'case',
				];
				$order = null;
				if (isset($o['order'])) {
					$order = array_values(array_filter(array_map(fn($kind) => $kinds[$kind] ?? null, $o['order'])));
					$unknown = array_diff($o['order'], array_keys($kinds));
					if ($unknown) {
						$t->warn('`ordered_class_elements` kinds without an equivalent in `dresscode/orderedMembers` were left out: `' . implode('`, `', $unknown) . '`.');
					}
				}

				$t->enable('dresscode/orderedMembers', $order ? ['order' => $order] : []);
			},
			'ordered_types' => function (array $o, Translation $t) {
				if (($o['null_adjustment'] ?? 'always_first') === 'none') {
					$t->warn('`ordered_types` with `null_adjustment=none` has no equivalent; DressCode always puts `null` first or last.');
				}
				$t->enable('dresscode/unionTypeNotation', [
					'order' => ($o['sort_algorithm'] ?? 'alpha') === 'alpha' ? 'byName' : 'keep',
					'nullPosition' => ($o['null_adjustment'] ?? 'always_first') === 'always_last' ? 'last' : 'first',
				]);
			},
			'ordered_imports' => function (array $o, Translation $t) {
				$sort = $o['sort_algorithm'] ?? 'alpha';
				if ($sort === 'length') {
					$t->warn('`ordered_imports` with `sort_algorithm=length` has no equivalent; DressCode sorts alphabetically.');
				}
				$t->enable('dresscode/orderedImports', [
					'order' => $sort === 'none' ? 'byKind' : 'byName',
					'caseSensitive' => $o['case_sensitive'] ?? false,
				]);
			},
			'phpdoc_array_type' => fn(array $o, Translation $t) => $t->enable('dresscode/phpdocCanonicalTypes', ['arrayNotation' => 'generic']),
			'phpdoc_list_type' => function (array $o, Translation $t) {
				$t->warn('`phpdoc_list_type` turns `array<T>` into `list<T>`, a stronger type, which DressCode does not do.');
				$t->enable('dresscode/phpdocCanonicalTypes');
			},
			'phpdoc_readonly_class_comment_to_keyword' => 'dresscode/readonlyForAnnotation',
			'phpdoc_scalar' => 'dresscode/phpdocCanonicalTypes',
			'phpdoc_trim' => 'dresscode/phpdocTrim',
			'phpdoc_trim_consecutive_blank_line_separation' => 'dresscode/phpdocTrim',
			'phpdoc_types' => 'dresscode/phpdocCanonicalTypes',
			'phpdoc_types_no_duplicates' => 'dresscode/phpdocCanonicalTypes',
			'regular_callable_call' => 'dresscode/noCallUserFunc',
			'return_type_declaration' => fn(array $o, Translation $t) => ($o['space_before'] ?? 'none') === 'none'
				? $t->enable('dresscode/typeHintSpacing')
				: $t->warn('`return_type_declaration` with `space_before=one` has no equivalent; DressCode writes no space before the colon.'),
			'self_accessor' => 'dresscode/selfForCurrentClass',
			'self_static_accessor' => fn(array $o, Translation $t) => $t->enable('dresscode/selfForCurrentClass', ['onStatic' => true]),
			'set_type_to_cast' => 'dresscode/noSettype',
			'short_scalar_cast' => 'dresscode/castCanonicalType',
			'simple_to_complex_string_variable' => 'dresscode/complexStringVariable',
			'single_blank_line_at_eof' => 'dresscode/eofLineEnding',
			'single_class_element_per_statement' => fn(array $o, Translation $t) => $t->enable('dresscode/singleMemberPerDeclaration', [
				'members' => array_values(array_map(fn(string $e) => $e === 'const' ? 'constant' : $e, $o['elements'] ?? ['const', 'property'])),
			]),
			'single_import_per_statement' => fn(array $o, Translation $t) => $t->enable('dresscode/importNotation', ($o['group_to_single_imports'] ?? true) ? [] : ['group' => 'keep']),
			'single_line_after_imports' => 'dresscode/blankLines',
			'single_line_comment_spacing' => 'dresscode/commentSpacing',
			'single_line_comment_style' => fn(array $o, Translation $t) => in_array('hash', $o['comment_types'] ?? ['asterisk', 'hash'], true)
				? $t->enable('dresscode/noHashComments')
				: $t->warn('`single_line_comment_style` without `hash` has no equivalent; DressCode only rewrites the hash comment.'),
			'single_quote' => fn(array $o, Translation $t) => $t->enable('dresscode/stringQuotes', ['quotes' => 'single']),
			'single_space_around_construct' => fn(array $o, Translation $t) => $t->enable('dresscode/constructSpacing', ['allowMultilineExpression' => true]),
			'single_trait_insert_per_statement' => fn(array $o, Translation $t) => $t->enable('dresscode/singleMemberPerDeclaration', ['members' => ['trait']]),
			'space_after_semicolon' => 'dresscode/semicolonSpacing',
			'spaces_inside_parentheses' => fn(array $o, Translation $t) => ($o['space'] ?? 'none') === 'none'
				? $t->enable('dresscode/parenthesesSpacing')
				: $t->warn('`spaces_inside_parentheses` with `space=single` has no equivalent; DressCode writes no space inside parentheses.'),
			'standardize_increment' => 'dresscode/incrementForAddOne',
			'standardize_not_equals' => 'dresscode/notEqualsNotation',
			'statement_indentation' => 'dresscode/indentation',
			'static_lambda' => 'dresscode/staticClosure',
			'static_private_method' => 'dresscode/staticForMethodWithoutThis',
			'strict_comparison' => 'dresscode/strictComparison',
			'strict_param' => 'dresscode/strictCall',
			'string_implicit_backslashes' => function (array $o, Translation $t) {
				$modes = [$o['double_quoted'] ?? 'escape', $o['heredoc'] ?? 'escape', $o['single_quoted'] ?? 'unescape'];
				if (in_array('unescape', $modes, true)) {
					$t->warn('`string_implicit_backslashes` unescapes some strings; DressCode always escapes the backslash.');
				}
				$t->enable('dresscode/noImplicitBackslashes');
			},
			'string_length_to_empty' => 'dresscode/noManualEmptyStringTests',
			'switch_case_semicolon_to_colon' => 'dresscode/switchCaseColon',
			'switch_case_space' => 'dresscode/switchCaseSpacing',
			'switch_continue_to_break' => 'dresscode/noContinueInSwitch',
			'ternary_operator_spaces' => 'dresscode/ternaryOperatorSpacing',
			'ternary_to_elvis_operator' => 'dresscode/shortTernaryForRepeatedCondition',
			'ternary_to_null_coalescing' => 'dresscode/nullCoalescingForNullTernary',
			'trailing_comma_in_multiline' => function (array $o, Translation $t) {
				$elements = $o['elements'] ?? ['arrays'];
				if (in_array('array_destructuring', $elements, true)) {
					$t->warn('`trailing_comma_in_multiline` with `array_destructuring` is translated only for `[...]`, which follows the place `array` of `dresscode/trailingComma`; a multi-line `list()` keeps its comma as written.');
				}
				$places = ['arrays' => 'array', 'arguments' => 'argument', 'parameters' => 'parameter', 'match' => 'matchArm'];
				$t->enable('dresscode/trailingComma', array_fill_keys(
					array_values(array_intersect_key($places, array_flip($elements))),
					'required',
				));
			},
			'trim_array_spaces' => 'dresscode/arraySpacing',
			'unary_operator_spaces' => function (array $o, Translation $t) {
				if ($o['only_dec_inc'] ?? false) {
					$t->warn('`unary_operator_spaces` with `only_dec_inc=true` is narrower than `dresscode/unaryOperatorSpacing`, which covers every unary operator.');
				}
				$t->enable('dresscode/unaryOperatorSpacing');
			},
			'visibility_required' => 'dresscode/visibilityRequired',
			'whitespace_after_comma_in_array' => fn(array $o, Translation $t) => $t->enable('dresscode/commaSpacing', [
				'alignment' => ($o['ensure_single_space'] ?? false) ? 'none' : 'keep',
			]),
		];
	}


	/**
	 * Rules of a .php-cs-fixer.php, which returns a configuration object, or of a file returning the rules
	 * themselves. The file is executed, so php-cs-fixer has to be installed; during a migration from it, it is.
	 * @return array<string, bool|array<string, mixed>>
	 * @throws ConfigurationException
	 */
	public static function readConfig(string $file): array
	{
		if (!is_file($file)) {
			throw new ConfigurationException("File `$file` does not exist.");
		}

		try {
			$config = require $file;
		} catch (\Error $e) {
			$hint = preg_match('~^Class ".+" not found$~', $e->getMessage())
				? '; run the dresscode installed in the project beside PHP CS Fixer, which loads its classes'
				: '';
			throw new ConfigurationException("File `$file` cannot be read: {$e->getMessage()}$hint.", previous: $e);
		}

		if (is_array($config)) {
			return $config;
		}
		if (!is_object($config) || !method_exists($config, 'getRules')) {
			throw new ConfigurationException("File `$file` returns neither rules nor a PHP CS Fixer configuration.");
		}

		return $config->getRules();
	}


	/** @return array<string, string>  rule set => preset */
	public static function getSets(): array
	{
		return [
			'@PER' => 'dresscode/perCs',
			'@PER-CS' => 'dresscode/perCs',
			'@PER-CS1.0' => 'dresscode/perCs',
			'@PER-CS2.0' => 'dresscode/perCs',
			'@PER-CS3.0' => 'dresscode/perCs',
			'@PSR1' => 'dresscode/psr12',
			'@PSR2' => 'dresscode/psr12',
			'@PSR12' => 'dresscode/psr12',
			'@Symfony' => 'dresscode/symfony',
		];
	}
}
