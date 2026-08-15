<?php declare(strict_types=1);

namespace DressCode\Interop;

use DressCode\ConfigurationException;


/**
 * What the rules of PHP CS Fixer mean in DressCode: the fixers of friendsofphp/php-cs-fixer, the custom fixers
 * of kubawerlos and the fixers of nette/coding-standard, which share the snake_case name, the shape of the
 * options and the .php-cs-fixer.php configuration.
 */
final class PhpCsFixer
{
	/**
	 * @return array<string, string|\Closure(array<string, mixed>, Translation): mixed>  fixer => rule name, or what to
	 *     enable for its options; every option is read through ?? so that a fixer enabled with none translates too
	 */
	public static function getTranslations(): array
	{
		return [
			'Nette/braces_position' => 'dresscode/braces-position',
			'Nette/class_and_trait_visibility_required' => 'dresscode/visibility-required',
			'Nette/method_argument_space' => 'dresscode/multi-line-call',
			'Nette/no_leading_slash_in_global_namespace' => 'dresscode/no-leading-backslash-in-global-namespace',
			'Nette/optimize_global_calls' => 'dresscode/global-imports',
			'Nette/ordered_imports' => 'dresscode/ordered-imports',
			'Nette/statement_indentation' => 'dresscode/statement-indentation',
			'PhpCsFixerCustomFixers/declare_after_opening_tag' => fn(array $o, Translation $t) => $t->enable('dresscode/strict-types-required', ['placement' => 'openingTagLine']),
			'PhpCsFixerCustomFixers/comment_surrounded_by_spaces' => 'dresscode/comment-spacing',
			'PhpCsFixerCustomFixers/commented_out_function' => fn(array $o, Translation $t) => $t->enable('dresscode/commented-out-function', array_filter(['functions' => $o['functions'] ?? null], fn($v) => $v !== null)),
			'PhpCsFixerCustomFixers/no_leading_slash_in_global_namespace' => 'dresscode/no-leading-backslash-in-global-namespace',
			'PhpCsFixerCustomFixers/no_superfluous_concatenation' => 'dresscode/useless-string-concat',
			'PhpCsFixerCustomFixers/no_useless_dirname_call' => 'dresscode/no-dirname-of-file',
			'PhpCsFixerCustomFixers/numeric_literal_separator' => 'dresscode/numeric-literal-separator',
			'PhpCsFixerCustomFixers/phpdoc_array_style' => fn(array $o, Translation $t) => $t->enable('dresscode/phpdoc-canonical-types', ['arrayNotation' => 'generic']),
			'PhpCsFixerCustomFixers/phpdoc_type_list' => function (array $o, Translation $t) {
				$t->warn('phpdoc_type_list turns array<T> into list<T>, a stronger type, which DressCode does not do');
				$t->enable('dresscode/phpdoc-canonical-types');
			},
			'array_indentation' => 'dresscode/array-indentation',
			'array_syntax' => fn(array $o, Translation $t) => ($o['syntax'] ?? 'short') === 'short'
				? $t->enable('dresscode/short-array-syntax')
				: $t->warn('array_syntax with syntax=long has no equivalent, DressCode writes the short syntax only'),
			'assign_null_coalescing_to_coalesce_equal' => 'dresscode/combined-assignment-operator',
			'attribute_block_no_spaces' => 'dresscode/attribute-spacing',
			'attribute_empty_parentheses' => fn(array $o, Translation $t) => ($o['use_parentheses'] ?? false)
				? $t->warn('attribute_empty_parentheses with use_parentheses=true has no equivalent, DressCode removes empty parentheses')
				: $t->enable('dresscode/attribute-spacing'),
			'backtick_to_shell_exec' => 'dresscode/no-backtick-operator',
			'binary_operator_spaces' => function (array $o, Translation $t) {
				$default = $o['default'] ?? 'single_space';
				if (str_starts_with((string) $default, 'align')) {
					$t->warn('binary_operator_spaces aligns operators, DressCode only keeps an alignment that is already there');
				}
				$t->enable('dresscode/binary-operator-spacing', [
					'spacing' => $default === 'single_space' ? 'single' : 'atLeastSingle',
				]);
			},
			'blank_line_after_namespace' => fn(array $o, Translation $t) => $t->enable('dresscode/header-blank-lines', ['afterNamespace' => 1]),
			'blank_line_after_opening_tag' => fn(array $o, Translation $t) => $t->enable('dresscode/header-blank-lines', ['afterOpeningTag' => 1]),
			'blank_line_before_statement' => function (array $o, Translation $t) {
				$statements = $o['statements'] ?? ['break', 'continue', 'declare', 'return', 'throw', 'try'];
				$supported = ['break', 'continue', 'do', 'for', 'foreach', 'if', 'return', 'switch', 'throw', 'try', 'while', 'yield'];
				foreach (array_diff($statements, $supported) as $statement) {
					$t->warn("blank_line_before_statement with $statement has no equivalent, DressCode knows no such statement kind");
				}
				$t->enable('dresscode/statement-blank-lines', ['before' => array_fill_keys(array_intersect($statements, $supported), [1, null])]);
			},
			'blank_line_between_import_groups' => fn(array $o, Translation $t) => $t->enable('dresscode/header-blank-lines', ['betweenImportGroups' => 1]),
			'blank_lines_before_namespace' => fn(array $o, Translation $t) => $t->enable('dresscode/header-blank-lines', [
				'beforeNamespace' => max(0, ($o['min_line_breaks'] ?? 2) - 1),
			]),
			'braces_position' => fn(array $o, Translation $t) => $t->enable('dresscode/braces-position', [
				'classes' => ($o['classes_opening_brace'] ?? 'next_line') === 'same_line' ? 'sameLine' : 'nextLine',
				'anonymousClasses' => ($o['anonymous_classes_opening_brace'] ?? 'same_line') === 'same_line' ? 'sameLine' : 'nextLine',
				'anonymousFunctions' => ($o['anonymous_functions_opening_brace'] ?? 'same_line') === 'same_line' ? 'sameLine' : 'nextLine',
				'controlStructures' => ($o['control_structures_opening_brace'] ?? 'same_line') === 'same_line' ? 'sameLine' : 'nextLine',
				'allowSingleLineAnonymousFunctions' => $o['allow_single_line_anonymous_functions'] ?? true,
				'emptyAnonymousClasses' => ($o['allow_single_line_empty_anonymous_classes'] ?? true) ? 'sameLine' : 'ownLine',
			]),
			'cast_spaces' => fn(array $o, Translation $t) => $t->enable('dresscode/cast-spacing', ['spacing' => $o['space'] ?? 'single']),
			'class_definition' => fn(array $o, Translation $t) => $t->enable('dresscode/class-definition-spacing', [
				'spaceBeforeParenthesis' => $o['space_before_parenthesis'] ?? false,
			]),
			'class_reference_name_casing' => 'dresscode/class-reference-name-casing',
			'combine_consecutive_issets' => 'dresscode/combined-issets',
			'combine_nested_dirname' => 'dresscode/no-dirname-of-file',
			'combine_consecutive_unsets' => 'dresscode/combined-unsets',
			'compact_nullable_type_declaration' => 'dresscode/type-hint-spacing',
			'concat_space' => fn(array $o, Translation $t) => $t->enable('dresscode/concat-spacing', ['spacing' => ($o['spacing'] ?? 'none') === 'one' ? 'single' : 'none']),
			'constant_case' => fn(array $o, Translation $t) => ($o['case'] ?? 'lower') === 'lower'
				? $t->enable('dresscode/constant-casing')
				: $t->warn('constant_case with case=upper has no equivalent, DressCode writes true, false and null in lower case'),
			'control_structure_braces' => 'dresscode/control-structure-braces',
			'control_structure_continuation_position' => 'dresscode/continuation-position',
			'declare_equal_normalize' => fn(array $o, Translation $t) => ($o['space'] ?? 'none') === 'none'
				? $t->enable('dresscode/declare-spacing')
				: $t->warn('declare_equal_normalize with space=single has no equivalent, DressCode writes declare(strict_types=1) without spaces'),
			'declare_parentheses' => 'dresscode/declare-spacing',
			'declare_strict_types' => fn(array $o, Translation $t) => ($o['strategy'] ?? 'enforce') === 'remove'
				? $t->warn('declare_strict_types with strategy=remove has no equivalent, DressCode requires the declaration')
				: $t->enable('dresscode/strict-types-required'),
			'dir_constant' => 'dresscode/no-dirname-of-file',
			'elseif' => 'dresscode/elseif-keyword',
			'encoding' => 'dresscode/no-byte-order-mark',
			'escape_implicit_backslashes' => 'dresscode/no-implicit-backslash',
			'final_internal_class' => fn(array $o, Translation $t) => $t->enable('dresscode/final-internal-class', array_filter([
				'include' => $o['annotation_include'] ?? null,
				'exclude' => $o['annotation_exclude'] ?? null,
			], fn($v) => $v !== null)),
			'full_opening_tag' => 'dresscode/full-opening-tag',
			'function_declaration' => 'dresscode/construct-spacing',
			'heredoc_indentation' => fn(array $o, Translation $t) => $t->enable('dresscode/heredoc-indentation', [
				'indentation' => ($o['indentation'] ?? 'start_plus_one') === 'start_plus_one' ? 'startPlusOne' : 'sameAsStart',
			]),
			'heredoc_to_nowdoc' => 'dresscode/nowdoc-without-interpolation',
			'include' => 'dresscode/useless-construct-parentheses',
			'indentation_type' => 'dresscode/statement-indentation',
			'is_null' => 'dresscode/no-is-null',
			'line_ending' => 'dresscode/line-ending',
			'list_syntax' => fn(array $o, Translation $t) => ($o['syntax'] ?? 'short') === 'short'
				? $t->enable('dresscode/short-list-syntax')
				: $t->warn('list_syntax with syntax=long has no equivalent, DressCode writes the short syntax only'),
			'lowercase_cast' => 'dresscode/cast-canonical-type',
			'lowercase_keywords' => 'dresscode/keyword-casing',
			'lowercase_static_reference' => 'dresscode/keyword-casing',
			'magic_constant_casing' => 'dresscode/magic-constant-casing',
			'method_chaining_indentation' => 'dresscode/chain-indentation',
			'method_argument_space' => fn(array $o, Translation $t) => ($o['on_multiline'] ?? 'ensure_fully_multiline') === 'ensure_fully_multiline'
				? $t->enable('dresscode/multi-line-call')
				: $t->warn("method_argument_space with on_multiline={$o['on_multiline']} has no equivalent, DressCode always puts each argument on its own line"),
			'modernize_types_casting' => 'dresscode/no-conversion-functions',
			'modifier_keywords' => 'dresscode/visibility-required',
			'multiline_promoted_properties' => fn(array $o, Translation $t) => $t->enable('dresscode/multi-line-signature', ['promotedProperties' => true]),
			'native_function_casing' => 'dresscode/native-function-casing',
			'new_expression_parentheses' => 'dresscode/useless-parentheses-around-new',
			'new_with_braces' => fn(array $o, Translation $t) => $t->enable('dresscode/new-argument-parentheses', [
				'namedClasses' => ($o['named_class'] ?? true) ? 'required' : 'forbidden',
				'anonymousClasses' => ($o['anonymous_class'] ?? true) ? 'required' : 'forbidden',
			]),
			'new_with_parentheses' => fn(array $o, Translation $t) => $t->enable('dresscode/new-argument-parentheses', [
				'namedClasses' => ($o['named_class'] ?? true) ? 'required' : 'forbidden',
				'anonymousClasses' => ($o['anonymous_class'] ?? true) ? 'required' : 'forbidden',
			]),
			'no_alias_functions' => fn(array $o, Translation $t) => $t->enable('dresscode/no-alias-functions', array_filter(['sets' => $o['sets'] ?? null], fn($v) => $v !== null)),
			'no_alternative_syntax' => 'dresscode/no-alternative-syntax',
			'no_blank_lines_after_class_opening' => 'dresscode/declaration-blank-lines',
			'no_blank_lines_after_phpdoc' => fn(array $o, Translation $t) => $t->enable('dresscode/declaration-blank-lines', ['afterPhpdoc' => 0]),
			'no_break_comment' => fn(array $o, Translation $t) => $t->enable('dresscode/fall-through-comment', array_filter(['comment' => $o['comment_text'] ?? null], fn($v) => $v !== null)),
			'no_closing_tag' => 'dresscode/no-closing-tag',
			'no_empty_comment' => 'dresscode/no-empty-comment',
			'no_empty_phpdoc' => 'dresscode/no-empty-phpdoc',
			'no_empty_statement' => 'dresscode/no-empty-statement',
			'no_extra_blank_lines' => 'dresscode/header-blank-lines',
			'no_leading_import_slash' => 'dresscode/no-leading-backslash-in-import',
			'no_leading_namespace_whitespace' => 'dresscode/statement-indentation',
			'no_multiple_statements_per_line' => 'dresscode/single-statement-per-line',
			'no_null_property_initialization' => 'dresscode/useless-null-property-initialization',
			'no_redundant_readonly_property' => 'dresscode/useless-modifier',
			'no_short_bool_cast' => 'dresscode/no-short-bool-cast',
			'no_singleline_whitespace_before_semicolons' => 'dresscode/semicolon-spacing',
			'no_space_around_double_colon' => 'dresscode/double-colon-spacing',
			'no_spaces_after_function_name' => 'dresscode/function-name-spacing',
			'no_spaces_around_offset' => 'dresscode/offset-bracket-spacing',
			'no_spaces_inside_parenthesis' => 'dresscode/parentheses-spacing',
			'no_trailing_comma_in_singleline' => fn(array $o, Translation $t) => $t->enable('dresscode/trailing-comma', ['multiLine' => [], 'singleLine' => true]),
			'no_trailing_whitespace' => 'dresscode/no-trailing-whitespace',
			'no_trailing_whitespace_in_comment' => 'dresscode/no-trailing-whitespace',
			'no_trailing_whitespace_in_string' => 'dresscode/no-trailing-whitespace-in-string',
			'no_unneeded_braces' => function (array $o, Translation $t) {
				if ($o['namespaces'] ?? false) {
					$t->warn('no_unneeded_braces with namespaces=true also unwraps a braced namespace, which DressCode leaves alone');
				}
				$t->enable('dresscode/useless-braces');
			},
			'no_unneeded_control_parentheses' => 'dresscode/useless-construct-parentheses',
			'no_unneeded_final_method' => function (array $o, Translation $t) {
				if ($o['private_methods'] ?? true) {
					$t->warn('no_unneeded_final_method also strips final from private methods, which PHP itself warns about; DressCode leaves them alone');
				}
				$t->enable('dresscode/useless-modifier');
			},
			'no_unneeded_curly_braces' => function (array $o, Translation $t) {
				if ($o['namespaces'] ?? false) {
					$t->warn('no_unneeded_curly_braces with namespaces=true also unwraps a braced namespace, which DressCode leaves alone');
				}
				$t->enable('dresscode/useless-braces');
			},
			'no_unreachable_default_argument_value' => 'dresscode/useless-parameter-default',
			'no_unset_on_property' => 'dresscode/no-unset-on-property',
			'no_unused_imports' => 'dresscode/unused-imports',
			'no_superfluous_elseif' => fn(array $o, Translation $t) => $t->enable('dresscode/useless-else', ['elseif' => true]),
			'no_useless_else' => 'dresscode/useless-else',
			'no_useless_return' => 'dresscode/useless-return',
			'no_whitespace_before_comma_in_array' => 'dresscode/comma-spacing',
			'no_whitespace_in_blank_line' => 'dresscode/no-trailing-whitespace',
			'non_printable_character' => function (array $o, Translation $t) {
				if (!($o['use_escape_sequences_in_strings'] ?? true)) {
					$t->warn('non_printable_character without escape sequences has no equivalent, DressCode writes the character as an escape sequence to keep the value');
				}
				$t->enable('dresscode/no-invisible-characters');
			},
			'numeric_literal_separator' => function (array $o, Translation $t) {
				if (($o['strategy'] ?? 'use_separator') === 'no_separator') {
					$t->warn('numeric_literal_separator with strategy=no_separator has no equivalent, DressCode adds the separator');
				}
				$t->enable('dresscode/numeric-literal-separator');
			},
			'nullable_type_declaration_for_default_null_value' => fn(array $o, Translation $t) => ($o['use_nullable_type_declaration'] ?? true)
				? $t->enable('dresscode/nullable-type-for-default-null')
				: $t->warn('nullable_type_declaration_for_default_null_value with use_nullable_type_declaration=false has no equivalent, DressCode adds the ? instead of removing it'),
			'object_operator_without_whitespace' => 'dresscode/object-operator-spacing',
			'octal_notation' => 'dresscode/octal-notation',
			'ordered_class_elements' => function (array $o, Translation $t) {
				if (($o['sort_algorithm'] ?? 'none') !== 'none') {
					$t->warn('ordered_class_elements sorts members by name, DressCode orders them by kind only');
				}
				$t->enable('dresscode/ordered-members', array_filter(['order' => $o['order'] ?? null], fn($v) => $v !== null));
			},
			'ordered_types' => function (array $o, Translation $t) {
				if (($o['null_adjustment'] ?? 'always_first') === 'none') {
					$t->warn('ordered_types with null_adjustment=none has no equivalent, DressCode always puts null first or last');
				}
				$t->enable('dresscode/union-type-format', [
					'alphabetically' => ($o['sort_algorithm'] ?? 'alpha') === 'alpha',
					'nullPosition' => ($o['null_adjustment'] ?? 'always_first') === 'always_last' ? 'last' : 'first',
				]);
			},
			'ordered_imports' => function (array $o, Translation $t) {
				$sort = $o['sort_algorithm'] ?? 'alpha';
				if ($sort === 'length') {
					$t->warn('ordered_imports with sort_algorithm=length has no equivalent, DressCode sorts alphabetically');
				}
				$t->enable('dresscode/ordered-imports', [
					'alphabetically' => $sort !== 'none',
					'caseSensitive' => $o['case_sensitive'] ?? false,
				]);
			},
			'phpdoc_array_type' => fn(array $o, Translation $t) => $t->enable('dresscode/phpdoc-canonical-types', ['arrayNotation' => 'generic']),
			'phpdoc_list_type' => function (array $o, Translation $t) {
				$t->warn('phpdoc_list_type turns array<T> into list<T>, a stronger type, which DressCode does not do');
				$t->enable('dresscode/phpdoc-canonical-types');
			},
			'phpdoc_scalar' => 'dresscode/phpdoc-canonical-types',
			'phpdoc_trim' => 'dresscode/phpdoc-trim',
			'phpdoc_trim_consecutive_blank_line_separation' => 'dresscode/phpdoc-trim',
			'phpdoc_types' => 'dresscode/phpdoc-canonical-types',
			'phpdoc_types_no_duplicates' => 'dresscode/phpdoc-canonical-types',
			'return_type_declaration' => fn(array $o, Translation $t) => ($o['space_before'] ?? 'none') === 'none'
				? $t->enable('dresscode/type-hint-spacing')
				: $t->warn('return_type_declaration with space_before=one has no equivalent, DressCode writes no space before the colon'),
			'self_accessor' => 'dresscode/self-for-current-class',
			'set_type_to_cast' => 'dresscode/no-settype',
			'short_scalar_cast' => 'dresscode/cast-canonical-type',
			'simple_to_complex_string_variable' => 'dresscode/complex-string-variable',
			'single_blank_line_at_eof' => 'dresscode/eof-newline',
			'single_class_element_per_statement' => fn(array $o, Translation $t) => $t->enable('dresscode/single-member-per-declaration', [
				'members' => array_values(array_map(fn(string $e) => $e === 'const' ? 'constant' : $e, $o['elements'] ?? ['const', 'property'])),
			]),
			'single_import_per_statement' => fn(array $o, Translation $t) => $t->enable('dresscode/import-notation', ($o['group_to_single_imports'] ?? true) ? [] : ['groupUse' => 'keep']),
			'single_line_after_imports' => 'dresscode/header-blank-lines',
			'single_line_comment_spacing' => 'dresscode/comment-spacing',
			'single_line_comment_style' => fn(array $o, Translation $t) => in_array('hash', $o['comment_types'] ?? ['asterisk', 'hash'], true)
				? $t->enable('dresscode/no-hash-comment')
				: $t->warn('single_line_comment_style without hash has no equivalent, DressCode only rewrites the hash comment'),
			'single_quote' => 'dresscode/single-quoted-strings',
			'single_space_around_construct' => 'dresscode/construct-spacing',
			'single_trait_insert_per_statement' => fn(array $o, Translation $t) => $t->enable('dresscode/single-member-per-declaration', ['members' => ['trait']]),
			'space_after_semicolon' => 'dresscode/semicolon-spacing',
			'spaces_inside_parentheses' => fn(array $o, Translation $t) => ($o['space'] ?? 'none') === 'none'
				? $t->enable('dresscode/parentheses-spacing')
				: $t->warn('spaces_inside_parentheses with space=single has no equivalent, DressCode writes no space inside parentheses'),
			'standardize_increment' => 'dresscode/increment-operator',
			'standardize_not_equals' => 'dresscode/not-equals-operator',
			'statement_indentation' => 'dresscode/statement-indentation',
			'static_lambda' => 'dresscode/static-closure',
			'strict_comparison' => 'dresscode/strict-comparison',
			'strict_param' => 'dresscode/strict-call',
			'string_implicit_backslashes' => function (array $o, Translation $t) {
				$modes = [$o['double_quoted'] ?? 'escape', $o['heredoc'] ?? 'escape', $o['single_quoted'] ?? 'unescape'];
				if (in_array('unescape', $modes, true)) {
					$t->warn('string_implicit_backslashes unescapes some strings, DressCode always escapes the backslash');
				}
				$t->enable('dresscode/no-implicit-backslash');
			},
			'switch_case_semicolon_to_colon' => 'dresscode/switch-case-colon',
			'switch_case_space' => 'dresscode/switch-case-spacing',
			'switch_continue_to_break' => 'dresscode/no-continue-in-switch',
			'ternary_operator_spaces' => 'dresscode/ternary-operator-spacing',
			'ternary_to_elvis_operator' => 'dresscode/short-ternary-operator',
			'ternary_to_null_coalescing' => 'dresscode/null-coalescing-operator',
			'trailing_comma_in_multiline' => fn(array $o, Translation $t) => $t->enable('dresscode/trailing-comma', [
				'multiLine' => $o['elements'] ?? ['arrays'],
				'singleLine' => false,
			]),
			'trim_array_spaces' => 'dresscode/array-spacing',
			'unary_operator_spaces' => function (array $o, Translation $t) {
				if ($o['only_dec_inc'] ?? false) {
					$t->warn('unary_operator_spaces with only_dec_inc=true is narrower than dresscode/unary-operator-spacing, which covers every unary operator');
				}
				$t->enable('dresscode/unary-operator-spacing');
			},
			'visibility_required' => 'dresscode/visibility-required',
			'whitespace_after_comma_in_array' => 'dresscode/comma-spacing',
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
			throw new ConfigurationException("File $file does not exist.");
		}

		$config = require $file;
		if (is_array($config)) {
			return $config;
		}
		if (!is_object($config) || !method_exists($config, 'getRules')) {
			throw new ConfigurationException("File $file returns neither rules nor a PHP CS Fixer configuration.");
		}

		return $config->getRules();
	}


	/** @return array<string, string>  rule set => preset */
	public static function getSets(): array
	{
		return [
			'@PER' => 'dresscode/per',
			'@PER-CS' => 'dresscode/per',
			'@PER-CS1.0' => 'dresscode/per',
			'@PER-CS2.0' => 'dresscode/per',
			'@PER-CS3.0' => 'dresscode/per',
			'@PSR1' => 'dresscode/psr12',
			'@PSR2' => 'dresscode/psr12',
			'@PSR12' => 'dresscode/psr12',
		];
	}
}
