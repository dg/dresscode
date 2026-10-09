<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Interop;

use DressCode\ConfigurationException;
use function array_key_exists, count, is_array, is_object;


/**
 * What the rules of PHP CS Fixer mean in DressCode: the fixers of friendsofphp/php-cs-fixer and the custom fixers
 * of kubawerlos, which share the snake_case name, the shape of the options and the .php-cs-fixer.php configuration.
 * @internal
 */
final class PhpCsFixer
{
	/**
	 * @return array<string, array<string, mixed>|\Closure(array<string, mixed>, Translation): mixed>  fixer => its decisions, or what to
	 *     enable for its options; every option is read through `??` so that a fixer enabled with none translates too
	 */
	public static function getTranslations(): array
	{
		// the fixers indent by the indent of the configuration object, which the rules do not carry
		$indentByConfig = function (array $o, Translation $t) {
			$unit = match ($t->indent) {
				"\t" => 'tab',
				'    ' => '4 spaces',
				'  ' => '2 spaces',
				default => null,
			};
			if ($unit === null) {
				$t->warn('The fixers of the indentation indent by the `indent` ' . var_export($t->indent, return: true) . ' of the configuration, which has no equivalent; DressCode indents by a tab, four spaces or two.');
			} else {
				$t->set('indentation.unit', $unit);
			}
		};

		return [
			'PhpCsFixerCustomFixers/declare_after_opening_tag' => fn(array $o, Translation $t) => $t->set('file.strictTypes.position', 'openingTagLine'),
			'PhpCsFixerCustomFixers/comment_surrounded_by_spaces' => ['spacing.comment.marker' => 'spaced'],
			'PhpCsFixerCustomFixers/commented_out_function' => fn(array $o, Translation $t) => $t->setAll([
				'correctness.debugOutput.statement' => 'commentedOut',
				'correctness.debugOutput.functions' => array_values(array_unique($o['functions'] ?? ['print_r', 'var_dump', 'var_export'])),
			]),
			'PhpCsFixerCustomFixers/no_leading_slash_in_global_namespace' => fn(array $o, Translation $t) => $t->setAll(['qualification.uselessBackslash' => 'forbidden', 'qualification.inFileWithoutNamespace' => 'bare']),
			'PhpCsFixerCustomFixers/no_superfluous_concatenation' => [
				'literals.concatenatedLiterals.sameLine' => 'joined',
				'literals.concatenatedLiterals.overLines' => 'keep',
			],
			'PhpCsFixerCustomFixers/no_useless_dirname_call' => ['cleanup.dirnameOfFile' => 'forbidden'],
			'PhpCsFixerCustomFixers/no_useless_strlen' => ['cleanup.strlenEmptyTest' => 'forbidden'],
			'PhpCsFixerCustomFixers/numeric_literal_separator' => [
				'literals.digitGroupsFrom.integer' => 4,
				'literals.digitGroupsFrom.fraction' => 4,
			],
			'PhpCsFixerCustomFixers/phpdoc_array_style' => fn(array $o, Translation $t) => $t->set('phpdoc.types.array', 'generic'),
			'PhpCsFixerCustomFixers/phpdoc_type_list' => function (array $o, Translation $t) {
				$t->warn('`phpdoc_type_list` turns `array<T>` into `list<T>`, a stronger type, which DressCode does not do.');
				$t->set('phpdoc.types.builtin', 'canonical')->set('phpdoc.types.array', 'generic');
			},
			'array_indentation' => $indentByConfig,
			'array_syntax' => fn(array $o, Translation $t) => ($o['syntax'] ?? 'short') === 'short'
				? $t->set('literals.longArraySyntax', 'forbidden')
				: $t->warn('`array_syntax` with `syntax=long` has no equivalent; DressCode writes the short syntax only.'),
			'assign_null_coalescing_to_coalesce_equal' => ['expressions.assignment.repeatingTarget' => 'forbidden'],
			'attribute_block_no_spaces' => ['spacing.attribute' => 'compact'],
			'attribute_empty_parentheses' => fn(array $o, Translation $t) => $t->set('classes.emptyParentheses.attribute', ($o['use_parentheses'] ?? false) ? 'required' : 'forbidden'),
			'backtick_to_shell_exec' => ['expressions.backticks' => 'forbidden'],
			'binary_operator_spaces' => function (array $o, Translation $t) {
				$default = $o['default'] ?? 'single_space';
				if (str_starts_with((string) $default, 'align')) {
					$t->warn('`binary_operator_spaces` aligns operators; DressCode only keeps an alignment that is already there.');
				}
				$t->setAll([
					'spacing.binaryOperator.around' => 'spaced',
					'spacing.binaryOperator.alignment' => $default === 'single_space' ? 'none' : 'spaces',
				]);
			},
			'blank_line_after_namespace' => fn(array $o, Translation $t) => $t->set('blankLines.afterNamespace', 1),
			'blank_line_after_opening_tag' => fn(array $o, Translation $t) => $t->set('blankLines.afterOpeningTag', 1),
			'blank_line_before_statement' => function (array $o, Translation $t) {
				$statements = $o['statements'] ?? ['break', 'continue', 'declare', 'return', 'throw', 'try'];
				$supported = ['break', 'continue', 'do', 'for', 'foreach', 'if', 'return', 'switch', 'throw', 'try', 'while', 'yield'];
				foreach (array_diff($statements, $supported) as $statement) {
					$t->warn("`blank_line_before_statement` with `$statement` has no equivalent; DressCode knows no such statement kind.");
				}
				$t->set('blankLines.beforeStatement', array_fill_keys(array_intersect($statements, $supported), [1, null]));
			},
			'blank_line_between_import_groups' => fn(array $o, Translation $t) => $t->set('blankLines.betweenImportKinds', 1),
			'blank_lines_before_namespace' => fn(array $o, Translation $t) => $t->set('blankLines.beforeNamespace', max(0, ($o['min_line_breaks'] ?? 2) - 1)),
			'braces_position' => function (array $o, Translation $t) {
				// a function goes below its signature unless the signature ends with a line break, which a multi-line one does
				if (($o['functions_opening_brace'] ?? 'next_line_unless_newline_at_signature_end') === 'same_line') {
					$t->warn('`braces_position` with `functions_opening_brace=same_line` has no equivalent; DressCode puts the brace of a function with its parameters on one line on the next line.');
				} else {
					$t->set('braces.position.function', 'nextLine');
				}

				$t->setAll([
					'braces.position.multilineSignature' => 'sameLine',
					'braces.position.class' => ($o['classes_opening_brace'] ?? 'next_line') === 'same_line' ? 'sameLine' : 'nextLine',
					'braces.position.anonymousClass' => ($o['anonymous_classes_opening_brace'] ?? 'same_line') === 'same_line' ? 'sameLine' : 'nextLine',
					'braces.position.closure' => ($o['anonymous_functions_opening_brace'] ?? 'same_line') === 'same_line' ? 'sameLine' : 'nextLine',
					'braces.position.controlStructure' => ($o['control_structures_opening_brace'] ?? 'same_line') === 'same_line' ? 'sameLine' : 'nextLine',
					'braces.singlelineClosure' => ($o['allow_single_line_anonymous_functions'] ?? true) ? 'keep' : 'forbidden',
					'braces.empty.anonymousClass' => ($o['allow_single_line_empty_anonymous_classes'] ?? true) ? 'keep' : 'ownLines',
				]);
			},
			'cast_spaces' => fn(array $o, Translation $t) => $t->set('spacing.cast', ($o['space'] ?? 'single') === 'single' ? 'spaced' : 'compact'),
			'class_definition' => fn(array $o, Translation $t) => $t->set('spacing.anonymousClass', ($o['space_before_parenthesis'] ?? false) ? 'spaced' : 'compact'),
			'class_keyword' => ['literals.classNameInString' => 'forbidden'],
			'class_attributes_separation' => function (array $o, Translation $t) {
				// the blank lines before an element without and with a doc comment or an attribute
				$counts = ['none' => [0, 0], 'one' => [1, 1], 'only_if_meta' => [0, 1]];
				$elements = $o['elements'] ?? ['const' => 'one', 'method' => 'one', 'property' => 'one', 'trait_import' => 'none', 'case' => 'none'];
				if (isset($elements['method'])) {
					[$min, $max] = $counts[$elements['method']] ?? [0, 1];
					$t->setBlankLines('blankLines.betweenMethods', $min, $max)->setBlankLines('blankLines.betweenInterfaceMethods', $min, $max);
				}

				if (isset($elements['trait_import'])) {
					[$min, $max] = $counts[$elements['trait_import']] ?? [0, 1];
					$t->setBlankLines('blankLines.betweenTraitUses', $min, $max);
				}

				$members = array_intersect_key($elements, array_flip(['case', 'const', 'property']));
				ksort($members);
				if ($members !== []) {
					$plain = array_map(fn(string $value) => ($counts[$value] ?? [0, 1])[0], $members);
					$documented = array_map(fn(string $value) => ($counts[$value] ?? [0, 1])[1], $members);
					// only a separation the configuration chose is worth the warning, not the one of the defaults
					if (count(array_unique($members)) > 1 && $members !== ['case' => 'none', 'const' => 'one', 'property' => 'one']) {
						$t->warn('`class_attributes_separation` separates constants, properties and enum cases differently, while `blankLines` counts the lines between them together; the translation takes the range of them all.');
					}

					$t->setBlankLines('blankLines.betweenMembers', min($plain), max($plain));
					$t->setBlankLines('blankLines.beforeDocumentedMember', min($documented), max($documented));
				}
			},
			'class_reference_name_casing' => fn(array $o, Translation $t) => $t->set('builtin.casing.class', 'declared'),
			'combine_consecutive_issets' => ['expressions.separate.isset' => 'forbidden'],
			'combine_nested_dirname' => ['cleanup.dirnameOfFile' => 'forbidden'],
			'combine_consecutive_unsets' => ['expressions.separate.unset' => 'forbidden'],
			'compact_nullable_type_declaration' => ['spacing.typeDeclaration' => 'compact'],
			'concat_space' => fn(array $o, Translation $t) => $t->set('spacing.concatenation', ($o['spacing'] ?? 'none') === 'one' ? 'spaced' : 'compact'),
			'constant_case' => fn(array $o, Translation $t) => $t->set('builtin.casing.trueFalseNull', ($o['case'] ?? 'lower') === 'lower' ? 'lowercase' : 'uppercase'),
			'control_structure_braces' => ['braces.bracelessBody' => 'forbidden'],
			'control_structure_continuation_position' => fn(array $o, Translation $t) => $t->set('braces.position.continuingKeyword', ($o['position'] ?? 'same_line') === 'next_line' ? 'nextLine' : 'sameLine'),
			'declare_equal_normalize' => fn(array $o, Translation $t) => ($o['space'] ?? 'none') === 'none'
				? $t->set('spacing.declare', 'compact')
				: $t->warn('`declare_equal_normalize` with `space=single` has no equivalent; DressCode writes `declare(strict_types=1)` without spaces.'),
			'declare_parentheses' => ['spacing.declare' => 'compact'],
			'declare_strict_types' => fn(array $o, Translation $t) => ($o['strategy'] ?? 'enforce') === 'remove'
				? $t->warn('`declare_strict_types` with `strategy=remove` has no equivalent; DressCode requires the declaration.')
				: $t->setAll(['file.strictTypes.declaration' => 'required', 'file.strictTypes.position' => 'ownLine']),
			'dir_constant' => ['cleanup.dirnameOfFile' => 'forbidden'],
			'elseif' => ['controlFlow.elseif' => 'oneWord'],
			'encoding' => ['file.bom' => 'forbidden'],
			'escape_implicit_backslashes' => ['literals.backslashes' => 'escaped'],
			'final_internal_class' => fn(array $o, Translation $t) => $t->setAll(array_filter([
				'classes.markedInternal.class' => 'final',
				'classes.markedInternal.annotations' => $o['annotation_include'] ?? null,
				'classes.markedInternal.except' => $o['annotation_exclude'] ?? null,
			], fn($v) => $v !== null)),
			'full_opening_tag' => ['file.openingTag' => 'full'],
			'fully_qualified_strict_types' => function (array $o, Translation $t) {
				if (!($o['import_symbols'] ?? false)) {
					$t->warn('`fully_qualified_strict_types` without `import_symbols` shortens a name only to an import the file has; DressCode adds the import it lacks.');
				}

				$t->qualifyShape(['class' => 'imported', 'globalClass' => 'keep']);
			},
			'function_declaration' => function (array $o, Translation $t) {
				if (($o['closure_function_spacing'] ?? 'one') === 'none') {
					$t->warn('`function_declaration` with `closure_function_spacing=none` has no equivalent; DressCode writes a space after `function`.');
				} else {
					$t->set('spacing.functionKeyword', 'spaced');
				}

				// the comma of parameters on one line is removed by every value but `keep`, a value another fixer gave staying
				if ($o['trailing_comma_single_line'] ?? false) {
					$t->warn('`function_declaration` with `trailing_comma_single_line=true` has no equivalent; DressCode removes the trailing comma of parameters on one line.');
				} else {
					$t->prefer('multiline.trailingComma.parameter', 'optional');
				}

				$t->setAll([
					'spacing.call' => 'compact',
					'spacing.parentheses' => 'compact',
					'spacing.fnKeyword' => ($o['closure_fn_spacing'] ?? 'one') === 'one' ? 'spaced' : 'compact',
				]);
			},
			'global_namespace_import' => function (array $o, Translation $t) {
				// true imports a name written with the backslash, false writes the backslash, null leaves the name alone, and
				// a bare function or constant stands either way
				$shape = fn(?bool $import) => match ($import) {
					true => 'imported',
					false => 'backslashed',
					null => 'keep',
				};
				$t->qualifyShape([
					'globalClass' => $shape(array_key_exists('import_classes', $o) ? $o['import_classes'] : true),
					'globalFunction' => $shape(array_key_exists('import_functions', $o) ? $o['import_functions'] : false),
					'globalConstant' => $shape(array_key_exists('import_constants', $o) ? $o['import_constants'] : false),
				]);
			},
			'group_import' => fn(array $o, Translation $t) => $t->set('imports.groupUse', 'required'),
			'heredoc_indentation' => fn(array $o, Translation $t) => $t->set('indentation.heredoc', ($o['indentation'] ?? 'start_plus_one') === 'start_plus_one' ? 'startPlusOne' : 'sameAsStart'),
			'heredoc_to_nowdoc' => ['literals.heredocWithoutInterpolation' => 'forbidden'],
			'include' => ['expressions.parenthesesAfterConstruct' => 'forbidden'],
			'indentation_type' => $indentByConfig,
			'is_null' => ['cleanup.is_null' => 'forbidden'],
			'line_ending' => fn(array $o, Translation $t) => match ($t->lineEnding) {
				"\n" => $t->set('file.lineEnding', 'LF'),
				"\r\n" => $t->set('file.lineEnding', 'CRLF'),
				default => $t->warn('`line_ending` writes the `lineEnding` ' . var_export($t->lineEnding, return: true) . ' of the configuration, which has no equivalent; DressCode ends a line with LF or CRLF.'),
			},
			'list_syntax' => fn(array $o, Translation $t) => ($o['syntax'] ?? 'short') === 'short'
				? $t->set('literals.longArraySyntax', 'forbidden')
				: $t->warn('`list_syntax` with `syntax=long` has no equivalent; DressCode writes the short syntax only.'),
			'lowercase_cast' => ['builtin.castType' => 'short'],
			'lowercase_keywords' => ['builtin.casing.keyword' => 'lowercase'],
			'lowercase_static_reference' => ['builtin.casing.keyword' => 'lowercase'],
			'magic_constant_casing' => ['builtin.casing.magicConstant' => 'uppercase'],
			'method_chaining_indentation' => ['indentation.chain' => 'flat'],
			'method_argument_space' => fn(array $o, Translation $t) => match ($o['on_multiline'] ?? 'ensure_fully_multiline') {
				'ensure_fully_multiline' => $t->set('multiline.shape.call', 'perLine'),
				'ignore' => $t,
				default => $t->warn("`method_argument_space` with `on_multiline={$o['on_multiline']}` has no equivalent; DressCode never joins a multi-line call into one line."),
			},
			'modernize_strpos' => function (array $o, Translation $t) {
				if ($o['modernize_stripos'] ?? false) {
					$t->warn('`modernize_strpos` with `modernize_stripos` rewrites `stripos()` too, which DressCode leaves alone, because PHP has no case-insensitive `str_contains()`.');
				}

				$t->set('upgrading.functions.substringFunctions', 'adopted');
			},
			'modernize_types_casting' => ['cleanup.conversionFunctions' => 'forbidden'],
			'modifier_keywords' => [
				'classes.visibility.member' => 'required',
				'classes.modifiers.order' => 'canonical',
				'classes.visibility.interfaceMethod' => 'required',
			],
			'multiline_promoted_properties' => fn(array $o, Translation $t) => $t->set('multiline.split.promotedProperty', 'always'),
			'native_function_casing' => ['builtin.casing.function' => 'declared'],
			'native_type_declaration_casing' => ['builtin.casing.type' => 'lowercase'],
			'new_expression_parentheses' => ['upgrading.syntax.newWithoutWrapping' => 'adopted'],
			'new_with_braces' => fn(array $o, Translation $t) => $t->setAll([
				'classes.emptyParentheses.instantiation' => ($o['named_class'] ?? true) ? 'required' : 'forbidden',
				'classes.emptyParentheses.anonymousClass' => ($o['anonymous_class'] ?? true) ? 'required' : 'forbidden',
			]),
			'new_with_parentheses' => fn(array $o, Translation $t) => $t->setAll([
				'classes.emptyParentheses.instantiation' => ($o['named_class'] ?? true) ? 'required' : 'forbidden',
				'classes.emptyParentheses.anonymousClass' => ($o['anonymous_class'] ?? true) ? 'required' : 'forbidden',
			]),
			'native_function_invocation' => function (array $o, Translation $t) {
				if (($o['scope'] ?? 'all') === 'all') {
					$t->warn('`native_function_invocation` with `scope=all` writes the backslash in the global namespace too, where DressCode writes none.');
				}

				$fallback = [];
				$named = [];
				foreach ($o['include'] ?? ['@compiler_optimized'] as $name) {
					if ($name === '@compiler_optimized') {
						$fallback['optimizedFunction'] = 'qualified';

					} elseif ($name === '@internal' || $name === '@all') {
						if ($name === '@internal') {
							$t->warn('`native_function_invocation` with `@internal` writes the backslash before a global function of the project too; DressCode has no set of builtin functions.');
						}

						$fallback['function'] = 'qualified';

					} else {
						$named[] = $name;
					}
				}

				// strict takes the backslash from every function the include does not reach
				if ($o['strict'] ?? true) {
					$fallback['function'] ??= 'bare';
				}

				$named = [...$named, ...$o['exclude'] ?? []];
				if ($named !== []) {
					$t->warn('`native_function_invocation` names functions one by one (`' . implode('`, `', $named) . '`), which DressCode decides by group; a function named follows `qualification.global.function`, or `qualification.optimized.function` where the compiler optimizes it.');
				}

				// the fixer qualifies with the backslash; a function neither the include nor strict reaches stays as it is
				$shape = ['optimizedFunction' => 'backslashed'];
				if (($fallback['function'] ?? null) === 'qualified') {
					$shape['globalFunction'] = 'backslashed';
				}

				$t->qualifyShape($shape);
				$t->qualifyFallback($fallback);
			},
			'native_constant_invocation' => function (array $o, Translation $t) {
				if (($o['scope'] ?? 'all') === 'all') {
					$t->warn('`native_constant_invocation` with `scope=all` writes the backslash in the global namespace too, where DressCode writes none.');
				}

				$fallback = [];
				if ($o['fix_built_in'] ?? true) {
					$t->warn('`native_constant_invocation` with `fix_built_in` writes the backslash before every constant of PHP; DressCode writes it before those the compiler computes with.');
					$fallback['optimizedConstant'] = 'qualified';
				}

				// strict takes the backslash from every constant the fixer does not escape
				if ($o['strict'] ?? true) {
					$fallback['constant'] = 'bare';
				}

				// true, false and null are left alone by the rule, so the default exclusion names nothing
				$named = [...$o['include'] ?? [], ...array_diff($o['exclude'] ?? [], ['null', 'false', 'true'])];
				if ($named !== []) {
					$t->warn('`native_constant_invocation` names constants one by one (`' . implode('`, `', $named) . '`), which DressCode decides by group; a constant named follows `qualification.global.constant`, or `qualification.optimized.constant` where the compiler computes with it.');
				}

				// the fixer qualifies with the backslash
				$t->qualifyShape(['optimizedConstant' => 'backslashed']);
				$t->qualifyFallback($fallback);
			},
			'no_alias_functions' => function (array $o, Translation $t) {
				$sets = $o['sets'] ?? null;
				foreach (array_intersect($sets ?? [], ['@mbreg', '@exif']) as $set) {
					$t->warn("`no_alias_functions` with `$set` has no equivalent; PHP 8 has none of the aliases it replaces.");
				}

				if (in_array('@snmp', $sets ?? [], true)) {
					$t->warn('`no_alias_functions` with `@snmp` has no equivalent; DressCode leaves the aliases of the SNMP functions alone.');
				}

				$sets = array_map(fn(string $set) => strtolower(ltrim($set, '@')), array_values(array_diff($sets ?? [], ['@mbreg', '@exif', '@snmp'])));
				$t->set('cleanup.aliasFunctions', ($o['sets'] ?? null) === null ? ['internal', 'imap', 'pg'] : $sets);
			},
			'no_alternative_syntax' => ['braces.alternativeSyntax' => 'forbidden'],
			'no_blank_lines_after_class_opening' => ['blankLines.beforeFirstMember' => 0, 'blankLines.beforeFirstMethod' => 0],
			'no_blank_lines_after_phpdoc' => fn(array $o, Translation $t) => $t->set('blankLines.afterPhpdoc', 0),
			'no_break_comment' => fn(array $o, Translation $t) => $t->set('controlFlow.switch.fallThroughComment', $o['comment_text'] ?? 'no break'),
			'no_closing_tag' => ['file.closingTagAtEnd' => 'forbidden'],
			'no_empty_comment' => ['comments.empty' => 'forbidden'],
			'no_empty_phpdoc' => ['phpdoc.empty' => 'forbidden'],
			'no_empty_statement' => ['controlFlow.emptyStatement' => 'forbidden'],
			'no_extra_blank_lines' => function (array $o, Translation $t) {
				$tokens = $o['tokens'] ?? ['extra'];
				$jumps = array_values(array_intersect($tokens, ['break', 'continue', 'return', 'throw']));
				$unsupported = [];
				foreach ($tokens as $token) {
					match ($token) {
						'curly_brace_block' => $t->setAll(['blankLines.afterBlockOpeningBrace' => 0, 'blankLines.beforeBlockClosingBrace' => 0]),
						'break', 'continue', 'return', 'throw' => $t->set('blankLines.afterStatement', [$token => 0]),
						'attribute' => $t->warn('`no_extra_blank_lines` with `attribute` removes the blank lines below an attribute, while `blankLines.afterPhpdoc` counts those below a doc comment too.')
							->set('blankLines.afterPhpdoc', 0),
						'use_trait' => $t->set('blankLines.betweenTraitUses', 0),
						// imports of one kind never have a blank line between them, those of different kinds keep what another fixer gives them
						'use' => $t->prefer('blankLines.betweenImportKinds', 0),
						default => $unsupported[] = $token,
					};
				}

				if ($jumps) {
					$t->warn('`no_extra_blank_lines` with `' . implode('`, `', $jumps) . '` removes the blank lines before the closing brace or the next `case` too, while `blankLines.afterStatement` counts only those before the next statement; set `blankLines.beforeBlockClosingBrace` and `blankLines.betweenCases` for those.');
				}

				if ($unsupported) {
					$t->warn('`no_extra_blank_lines` with `' . implode('`, `', $unsupported) . '` has no equivalent; DressCode counts the blank lines place by place in `blankLines`, with no limit of their own for these.');
				}
			},
			'no_leading_import_slash' => fn(array $o, Translation $t) => $t->set('qualification.uselessBackslash', 'forbidden'),
			'no_leading_namespace_whitespace' => $indentByConfig,
			'no_multiple_statements_per_line' => ['file.statementsPerLine' => 1],
			'no_null_property_initialization' => ['classes.untypedPropertyNullInitialization' => 'forbidden'],
			'no_redundant_readonly_property' => ['classes.modifiers.implied' => 'forbidden'],
			'no_short_bool_cast' => ['expressions.doubleNegation' => 'forbidden'],
			'no_singleline_whitespace_before_semicolons' => ['spacing.semicolon.before' => 'compact'],
			'no_space_around_double_colon' => ['spacing.doubleColon' => 'compact'],
			'no_spaces_after_function_name' => ['spacing.call' => 'compact'],
			'no_spaces_around_offset' => ['spacing.offsetBrackets' => 'compact'],
			'no_spaces_inside_parenthesis' => ['spacing.parentheses' => 'compact'],
			'no_trailing_comma_in_singleline' => function (array $o, Translation $t) {
				// a place another fixer asked to be required stays so, which removes the one-line comma as well
				$places = [
					'arguments' => 'multiline.trailingComma.argument',
					'array' => 'multiline.trailingComma.array',
					'array_destructuring' => 'multiline.trailingComma.list',
					'group_import' => 'multiline.trailingComma.import',
				];
				foreach ($o['elements'] ?? array_keys($places) as $element) {
					if (isset($places[$element])) {
						$t->prefer($places[$element], 'optional');
					}
				}
			},
			'no_trailing_whitespace' => ['file.trailingWhitespace' => 'forbidden'],
			'no_trailing_whitespace_in_comment' => ['file.trailingWhitespace' => 'forbidden'],
			'no_trailing_whitespace_in_string' => ['correctness.trailingWhitespaceInString' => 'forbidden'],
			'no_unneeded_braces' => function (array $o, Translation $t) {
				if ($o['namespaces'] ?? false) {
					$t->warn('`no_unneeded_braces` with `namespaces=true` also unwraps a braced namespace, which DressCode leaves alone.');
				}
				$t->set('braces.bareStatementGroup', 'forbidden');
			},
			'no_unneeded_control_parentheses' => ['expressions.parenthesesAfterConstruct' => 'forbidden'],
			'no_unneeded_final_method' => function (array $o, Translation $t) {
				if (($o['private_methods'] ?? true) === false) {
					$t->warn('`no_unneeded_final_method` with `private_methods=false` keeps `final` on a private method, which DressCode removes anyway.');
				}

				$t->set('classes.modifiers.implied', 'forbidden');
			},
			'no_unneeded_curly_braces' => function (array $o, Translation $t) {
				if ($o['namespaces'] ?? false) {
					$t->warn('`no_unneeded_curly_braces` with `namespaces=true` also unwraps a braced namespace, which DressCode leaves alone.');
				}
				$t->set('braces.bareStatementGroup', 'forbidden');
			},
			'no_unreachable_default_argument_value' => ['functions.uselessParameterDefault' => 'forbidden'],
			'no_unused_imports' => ['imports.unused' => 'forbidden', 'phpdoc.namesUseImports' => true],
			'no_superfluous_elseif' => fn(array $o, Translation $t) => $t->set('controlFlow.afterExit.elseif', 'forbidden'),
			'no_useless_else' => ['controlFlow.afterExit.else' => 'forbidden'],
			'no_useless_return' => ['functions.trailingBareReturn' => 'forbidden'],
			'no_whitespace_before_comma_in_array' => fn(array $o, Translation $t) => $t->set('spacing.comma.around', 'spaced')->prefer('spacing.comma.alignment', 'any'),
			'no_whitespace_in_blank_line' => ['file.trailingWhitespace' => 'forbidden'],
			'non_printable_character' => function (array $o, Translation $t) {
				if (!($o['use_escape_sequences_in_strings'] ?? true)) {
					$t->warn('`non_printable_character` without escape sequences has no equivalent; DressCode writes the character as an escape sequence to keep the value.');
				}
				$t->set('correctness.invisibleCharacters', 'forbidden');
			},
			'numeric_literal_separator' => function (array $o, Translation $t) {
				if (($o['strategy'] ?? 'use_separator') === 'no_separator') {
					$t->warn('`numeric_literal_separator` with `strategy=no_separator` has no equivalent; DressCode adds the separator.');
				} else {
					$t->setAll(['literals.digitGroupsFrom.integer' => 4, 'literals.digitGroupsFrom.fraction' => 4]);
				}
			},
			'nullable_type_declaration_for_default_null_value' => fn(array $o, Translation $t) => ($o['use_nullable_type_declaration'] ?? true)
				? $t->set('upgrading.php.implicitNullable', 'forbidden')
				: $t->warn('`nullable_type_declaration_for_default_null_value` with `use_nullable_type_declaration=false` has no equivalent; DressCode adds the `?` instead of removing it.'),
			'object_operator_without_whitespace' => ['spacing.objectOperator' => 'compact'],
			'octal_notation' => ['upgrading.syntax.octalPrefix' => 'adopted'],
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
					'magic' => 'magicMethod', 'case' => 'enumCase',
				];
				$order = null;
				if (isset($o['order'])) {
					$order = array_values(array_filter(array_map(fn($kind) => $kinds[$kind] ?? null, $o['order'])));
					$unknown = array_diff($o['order'], array_keys($kinds));
					if ($unknown) {
						$t->warn('The kinds `' . implode('`, `', $unknown) . '` of `ordered_class_elements` have no equivalent in `classes.members.order` and were left out.');
					}
				}

				$t->setOrder('classes.members.order', $order ?: [
					'traitUse', 'constant', 'publicConstant', 'protectedConstant', 'privateConstant',
					'publicProperty', 'protectedProperty', 'privateProperty',
				]);
			},
			'ordered_types' => fn(array $o, Translation $t) => $t->setAll([
				'types.unionOrder' => ($o['sort_algorithm'] ?? 'alpha') === 'alpha' ? 'byName' : 'keep',
				'types.nullPosition' => match ($o['null_adjustment'] ?? 'always_first') {
					'always_last' => 'last',
					'none' => 'keep',
					default => 'first',
				},
			]),
			'ordered_imports' => function (array $o, Translation $t) {
				$sort = $o['sort_algorithm'] ?? 'alpha';
				if ($sort === 'length') {
					$t->warn('`ordered_imports` with `sort_algorithm=length` has no equivalent; DressCode sorts alphabetically.');
				}
				$t->setAll([
					'imports.order.withinKind' => $sort === 'none' ? 'asWritten' : 'alphabetical',
					'imports.order.caseSensitive' => $o['case_sensitive'] ?? false,
				]);
			},
			'phpdoc_array_type' => fn(array $o, Translation $t) => $t->set('phpdoc.types.array', 'generic'),
			'phpdoc_list_type' => function (array $o, Translation $t) {
				$t->warn('`phpdoc_list_type` turns `array<T>` into `list<T>`, a stronger type, which DressCode does not do.');
				$t->set('phpdoc.types.builtin', 'canonical')->set('phpdoc.types.array', 'generic');
			},
			'phpdoc_readonly_class_comment_to_keyword' => ['upgrading.phpdoc.readonly' => 'adopted'],
			'phpdoc_scalar' => fn(array $o, Translation $t) => $t->set('phpdoc.types.builtin', 'canonical'),
			'phpdoc_trim' => ['phpdoc.blankLines' => 'trimmed'],
			'phpdoc_trim_consecutive_blank_line_separation' => ['phpdoc.blankLines' => 'trimmed'],
			'phpdoc_types' => fn(array $o, Translation $t) => $t->set('phpdoc.types.builtin', 'canonical'),
			'phpdoc_types_no_duplicates' => fn(array $o, Translation $t) => $t->set('phpdoc.types.builtin', 'canonical'),
			'phpdoc_types_order' => function (array $o, Translation $t) {
				if ($o['case_sensitive'] ?? false) {
					$t->warn('`phpdoc_types_order` with `case_sensitive=true` has no equivalent; DressCode sorts case-insensitively.');
				}
				$t->setAll([
					'phpdoc.types.unionOrder' => ($o['sort_algorithm'] ?? 'alpha') === 'alpha' ? 'byName' : 'keep',
					'phpdoc.types.nullPosition' => match ($o['null_adjustment'] ?? 'always_first') {
						'always_last' => 'last',
						'none' => 'keep',
						default => 'first',
					},
				]);
			},
			'regular_callable_call' => ['cleanup.call_user_func' => 'forbidden'],
			'return_type_declaration' => fn(array $o, Translation $t) => ($o['space_before'] ?? 'none') === 'none'
				? $t->set('spacing.typeDeclaration', 'compact')
				: $t->warn('`return_type_declaration` with `space_before=one` has no equivalent; DressCode writes no space before the colon.'),
			'self_accessor' => ['qualification.currentClass' => 'self'],
			'self_static_accessor' => fn(array $o, Translation $t) => $t->set('qualification.staticInFinalClass', 'self'),
			'set_type_to_cast' => ['cleanup.settype' => 'forbidden'],
			'short_scalar_cast' => ['builtin.castType' => 'short'],
			'simple_to_complex_string_variable' => ['upgrading.php.dollarBraceInterpolation' => 'forbidden'],
			'single_blank_line_at_eof' => ['file.finalLineEndings' => 1],
			'single_class_element_per_statement' => fn(array $o, Translation $t) => $t->setAllowed('classes.members.groupable', array_values(array_diff(['constant', 'property', 'traitUse'], array_map(fn(string $e) => match ($e) {
				'const' => 'constant',
				'trait' => 'traitUse',
				default => $e,
			}, $o['elements'] ?? ['const', 'property'])))),
			'single_import_per_statement' => function (array $o, Translation $t) {
				$t->setAll(['imports.statement.class' => 'separate', 'imports.statement.function' => 'separate', 'imports.statement.constant' => 'separate']);
				if ($o['group_to_single_imports'] ?? true) {
					$t->set('imports.groupUse', 'forbidden');
				} else {
					$t->keep('imports.groupUse');
				}
			},
			'single_line_after_imports' => ['blankLines.afterImports' => 1],
			'single_line_comment_spacing' => ['spacing.comment.marker' => 'spaced'],
			'single_line_comment_style' => fn(array $o, Translation $t) => in_array('hash', $o['comment_types'] ?? ['asterisk', 'hash'], true)
				? $t->set('comments.singleline', 'slashes')
				: $t->warn('`single_line_comment_style` without `hash` has no equivalent; DressCode only rewrites the hash comment.'),
			'single_quote' => fn(array $o, Translation $t) => $t->set('literals.quotes', 'single'),
			'single_space_around_construct' => function (array $o, Translation $t) {
				$groups = [
					'spacing.controlKeyword' => ['catch', 'do', 'elseif', 'for', 'foreach', 'if', 'match', 'switch', 'try', 'while'],
					'spacing.connectingKeyword' => ['as', 'catch', 'else', 'elseif', 'finally', 'insteadof', 'use_lambda'],
					'spacing.modifier' => ['abstract', 'final', 'private', 'private_set', 'protected', 'protected_set', 'public', 'public_set', 'readonly', 'static', 'var'],
					'spacing.functionKeyword' => ['function'],
					'spacing.classHead' => ['class', 'enum', 'extends', 'implements', 'interface', 'trait'],
					'spacing.languageConstruct' => [
						'break', 'case', 'clone', 'const', 'const_import', 'continue', 'echo', 'function_import', 'global', 'goto', 'include',
						'include_once', 'namespace', 'new', 'print', 'require', 'require_once', 'return', 'throw', 'use', 'use_trait', 'yield', 'yield_from',
					],
				];
				$constructs = [
					...$o['constructs_followed_by_a_single_space'] ?? array_merge(...array_values($groups)),
					...$o['constructs_preceded_by_a_single_space'] ?? ['as', 'use_lambda'],
				];
				foreach ($groups as $path => $group) {
					if (array_intersect($constructs, $group)) {
						$t->set($path, 'spaced');
					}
				}
			},
			'single_trait_insert_per_statement' => fn(array $o, Translation $t) => $t->setAllowed('classes.members.groupable', ['constant', 'property']),
			'space_after_semicolon' => function (array $o, Translation $t) {
				if ($o['remove_in_empty_for_expressions'] ?? false) {
					$t->warn('`space_after_semicolon` with `remove_in_empty_for_expressions=true` removes the space in an empty expression of a `for`, which DressCode does not tell apart.');
				}
				$t->set('spacing.semicolon.after', 'spaced');
			},
			'spaces_inside_parentheses' => fn(array $o, Translation $t) => ($o['space'] ?? 'none') === 'none'
				? $t->set('spacing.parentheses', 'compact')
				: $t->warn('`spaces_inside_parentheses` with `space=single` has no equivalent; DressCode writes no space inside parentheses.'),
			'standardize_increment' => ['expressions.assignment.addingOne' => 'forbidden'],
			'standardize_not_equals' => ['expressions.comparison.notEquals' => 'exclamation'],
			'statement_indentation' => $indentByConfig,
			'static_lambda' => ['functions.staticWithoutThis.closure' => 'required'],
			'static_private_method' => ['functions.staticWithoutThis.method' => 'required'],
			'strict_comparison' => ['expressions.comparison.equality' => 'strict'],
			'strict_param' => ['correctness.strictComparisonArgument' => 'required'],
			'string_implicit_backslashes' => function (array $o, Translation $t) {
				$modes = [$o['double_quoted'] ?? 'escape', $o['heredoc'] ?? 'escape', $o['single_quoted'] ?? 'unescape'];
				if (in_array('unescape', $modes, true)) {
					$t->warn('`string_implicit_backslashes` unescapes some strings; DressCode always escapes the backslash.');
				}
				$t->set('literals.backslashes', 'escaped');
			},
			'string_length_to_empty' => ['cleanup.strlenEmptyTest' => 'forbidden'],
			'switch_case_semicolon_to_colon' => ['controlFlow.switch.caseTerminator' => 'colon'],
			'switch_case_space' => ['spacing.switchCase' => 'compact'],
			'switch_continue_to_break' => ['correctness.continueInSwitch' => 'forbidden'],
			'ternary_operator_spaces' => [
				'spacing.ternary.around' => 'spaced',
				'spacing.ternary.alignment' => 'any',
			],
			'ternary_to_elvis_operator' => ['expressions.ternary.returningItsCondition' => 'forbidden'],
			'ternary_to_null_coalescing' => ['expressions.ternary.testingNull' => 'forbidden'],
			'trailing_comma_in_multiline' => function (array $o, Translation $t) {
				$elements = $o['elements'] ?? ['arrays'];
				if (in_array('array_destructuring', $elements, true)) {
					$t->warn(in_array('arrays', $elements, true)
						? '`trailing_comma_in_multiline` with `array_destructuring` is translated only for the short `[...]`, which `trailingComma` sets under the key `array`; a multi-line `list()` keeps its comma as written.'
						: '`trailing_comma_in_multiline` with `array_destructuring` and without `arrays` has no equivalent; DressCode decides the comma of a short `[...]` together with that of an array, under the key `array` of `trailingComma`, and a multi-line `list()` keeps its comma as written.');
				}
				$places = [
					'arrays' => 'multiline.trailingComma.array',
					'arguments' => 'multiline.trailingComma.argument',
					'parameters' => 'multiline.trailingComma.parameter',
					'match' => 'multiline.trailingComma.matchArm',
				];
				$t->setAll(array_fill_keys(array_values(array_intersect_key($places, array_flip($elements))), 'required'));
			},
			'trim_array_spaces' => ['spacing.arrayBrackets' => 'compact'],
			'unary_operator_spaces' => fn(array $o, Translation $t) => $t->setAll(['spacing.unaryOperator.after' => 'compact'] + (($o['only_dec_inc'] ?? false) ? ['spacing.unaryOperator.withSpace' => ['!', '-', '+', '~', '@']] : [])),
			'visibility_required' => [
				'classes.visibility.member' => 'required',
				'classes.modifiers.order' => 'canonical',
				'classes.visibility.interfaceMethod' => 'required',
			],
			'whitespace_after_comma_in_array' => fn(array $o, Translation $t) => $t->setAll([
				'spacing.comma.around' => 'spaced',
				'spacing.comma.alignment' => ($o['ensure_single_space'] ?? false) ? 'none' : 'any',
			]),
		];
	}


	/**
	 * Rules of a .php-cs-fixer.php, which returns a configuration object, or of a file returning the rules
	 * themselves, with the indent and the line ending the fixers of whitespace write, those of PHP CS Fixer where the
	 * object does not say. The file is executed, so php-cs-fixer has to be installed; during a migration from it, it is.
	 * @return array{array<string, bool|array<string, mixed>>, string, string}
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
			throw new ConfigurationException(
				(preg_match('~^Class ".+" not found$~', $e->getMessage())
					? "File `$file` needs the classes of PHP CS Fixer, which this dresscode cannot load; run the `vendor/bin/dresscode` of the project that has PHP CS Fixer installed: "
					: "File `$file` cannot be read: ")
				. rtrim($e->getMessage(), '.') . '.',
				previous: $e,
			);
		}

		if (is_array($config)) {
			return [$config, '    ', "\n"];
		}
		if (!is_object($config) || !method_exists($config, 'getRules')) {
			throw new ConfigurationException("File `$file` returns neither rules nor a PHP CS Fixer configuration.");
		}

		return [
			$config->getRules(),
			method_exists($config, 'getIndent') ? $config->getIndent() : '    ',
			method_exists($config, 'getLineEnding') ? $config->getLineEnding() : "\n",
		];
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
