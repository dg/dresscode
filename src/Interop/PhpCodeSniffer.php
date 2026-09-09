<?php declare(strict_types=1);

namespace DressCode\Interop;

use DressCode\ConfigurationException;


/**
 * What the sniffs of PHP_CodeSniffer mean in DressCode: the standards shipped with it and the sniffs of
 * slevomat/coding-standard, which share the Standard.Category.Name code, configuration by properties and the
 * phpcs.xml ruleset.
 * @internal
 */
final class PhpCodeSniffer
{
	/**
	 * @return array<string, string|\Closure(array<string, mixed>, Translation): mixed>  sniff => rule name, or what to
	 *     enable for its properties; every property is read through ?? so that a sniff with none translates too
	 */
	public static function getTranslations(): array
	{
		return [
			'Generic.Arrays.DisallowLongArraySyntax' => 'dresscode/short-array-syntax',
			'Generic.CodeAnalysis.RequireExplicitBooleanOperatorPrecedence' => 'dresscode/explicit-operator-precedence',
			'Generic.CodeAnalysis.UnnecessaryFinalModifier' => 'dresscode/useless-modifier',
			'Generic.ControlStructures.InlineControlStructure' => 'dresscode/control-structure-braces',
			'Generic.Files.ByteOrderMark' => 'dresscode/no-bom',
			'Generic.Files.LineEndings' => 'dresscode/line-ending',
			'Generic.Files.LineLength' => fn(array $o, Translation $t) => $t->lineLength(($o['absoluteLineLimit'] ?? 100) ?: ($o['lineLimit'] ?? 80))->enable('dresscode/line-length'),
			'Generic.Formatting.DisallowMultipleStatements' => 'dresscode/single-statement-per-line',
			'Generic.Functions.FunctionCallArgumentSpacing' => 'dresscode/comma-spacing',
			'Generic.Functions.FunctionCallArgumentSpacing.SpaceBeforeOpenBracket' => 'dresscode/function-name-spacing',
			'Generic.NamingConventions.CamelCapsFunctionName' => fn(array $o, Translation $t) => $t->enable('dresscode/name-casing', ['methods' => 'camelCase', 'functions' => 'camelCase']),
			'Generic.NamingConventions.UpperCaseConstantName' => function (array $o, Translation $t) {
				$t->warn('UpperCaseConstantName also checks define(), which DressCode does not');
				$t->enable('dresscode/name-casing', ['constants' => 'UPPER_CASE']);
			},
			'Generic.PHP.DeprecatedFunctions' => 'dresscode/no-deprecated-functions',
			'Generic.PHP.DisallowShortOpenTag' => 'dresscode/full-opening-tag',
			'Generic.PHP.ForbiddenFunctions' => fn(array $o, Translation $t) => $t->enable('dresscode/forbidden-functions', [
				'functions' => array_map(fn($v) => $v === null ? null : "$v()", $o['forbiddenFunctions'] ?? ['sizeof' => 'count', 'delete' => 'unset']),
			]),
			'Generic.PHP.LowerCaseConstant' => 'dresscode/constant-casing',
			'Generic.PHP.LowerCaseKeyword' => 'dresscode/keyword-casing',
			'Generic.PHP.RequireStrictTypes' => 'dresscode/strict-types-required',
			'Generic.PHP.SAPIUsage' => fn(array $o, Translation $t) => $t->enable('dresscode/forbidden-functions', ['functions' => ['php_sapi_name' => 'PHP_SAPI']]),
			'Generic.Strings.UnnecessaryStringConcat' => fn(array $o, Translation $t) => $t->enable('dresscode/useless-string-concat', ['allowMultiline' => $o['allowMultiline'] ?? false]),
			'Generic.WhiteSpace.DisallowSpaceIndent' => 'dresscode/indentation',
			'Generic.WhiteSpace.LanguageConstructSpacing' => 'dresscode/construct-spacing',
			'Generic.WhiteSpace.ScopeIndent' => 'dresscode/indentation',
			'PEAR.Commenting.InlineComment' => 'dresscode/no-hash-comment',
			'PEAR.WhiteSpace.ObjectOperatorIndent' => function (array $o, Translation $t) {
				if ($o['multilevel'] ?? false) {
					$t->warn('ObjectOperatorIndent with multilevel=true has no equivalent, DressCode indents every operator of a chain one level deeper than its start');
				}
				$t->enable('dresscode/indentation');
			},
			'PSR1.Methods.CamelCapsMethodName' => fn(array $o, Translation $t) => $t->enable('dresscode/name-casing', ['methods' => 'camelCase']),
			'PSR12.Files.FileHeader' => 'dresscode/blank-lines',
			'PSR2.Classes.ClassDeclaration' => 'dresscode/braces-position',
			'PSR2.Classes.ClassDeclaration.SpaceBeforeKeyword' => 'dresscode/class-definition-spacing',
			'PSR2.Classes.PropertyDeclaration' => 'dresscode/visibility-required',
			'PSR2.ControlStructures.ControlStructureSpacing' => 'dresscode/parentheses-spacing',
			'PSR2.ControlStructures.ElseIfDeclaration' => 'dresscode/elseif-keyword',
			'PSR2.ControlStructures.SwitchDeclaration.SpaceBeforeColonCASE' => 'dresscode/switch-case-spacing',
			'PSR2.Files.ClosingTag' => 'dresscode/no-closing-tag',
			'PSR2.Files.EndFileNewline' => 'dresscode/eof-newline',
			'PSR2.Namespaces.NamespaceDeclaration' => 'dresscode/indentation',
			'PSR2.Namespaces.UseDeclaration' => 'dresscode/blank-lines',
			'SlevomatCodingStandard.Arrays.MultiLineArrayEndBracketPlacement' => 'dresscode/indentation',
			'SlevomatCodingStandard.Arrays.SingleLineArrayWhitespace' => 'dresscode/array-spacing',
			'SlevomatCodingStandard.Arrays.TrailingArrayComma' => fn(array $o, Translation $t) => $t->enable('dresscode/trailing-comma', ['multiLine' => ['arrays'], 'singleLine' => false]),
			'SlevomatCodingStandard.Attributes.AttributeAndTargetSpacing' => fn(array $o, Translation $t) => $t->enable('dresscode/blank-lines', ['afterPhpDoc' => $o['linesCountBetweenAttributeAndTarget'] ?? 0]),
			'SlevomatCodingStandard.Attributes.DisallowMultipleAttributesPerLine' => 'dresscode/attribute-position',
			'SlevomatCodingStandard.Attributes.RequireAttributeAfterDocComment' => 'dresscode/attribute-after-phpdoc',
			'SlevomatCodingStandard.Classes.BackedEnumTypeSpacing' => 'dresscode/type-hint-spacing',
			'SlevomatCodingStandard.Classes.ClassConstantVisibility' => 'dresscode/visibility-required',
			'SlevomatCodingStandard.Classes.ConstantSpacing' => 'dresscode/blank-lines',
			'SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition' => fn(array $o, Translation $t) => $t->enable('dresscode/single-member-per-declaration', ['members' => ['constant']]),
			'SlevomatCodingStandard.Classes.DisallowMultiPropertyDefinition' => fn(array $o, Translation $t) => $t->enable('dresscode/single-member-per-declaration', ['members' => ['property']]),
			'SlevomatCodingStandard.Classes.EmptyLinesAroundClassBraces' => fn(array $o, Translation $t) => $t->enable('dresscode/blank-lines', [
				'afterClassBrace' => $o['linesCountAfterOpeningBrace'] ?? 1,
				'beforeClassBrace' => $o['linesCountBeforeClosingBrace'] ?? 1,
			]),
			'SlevomatCodingStandard.Classes.ModernClassNameReference' => fn(array $o, Translation $t) => $t->enable('dresscode/modern-class-name-reference', ['onObjects' => $o['enableOnObjects'] ?? false]),
			'SlevomatCodingStandard.Classes.PropertyDeclaration' => 'dresscode/visibility-required',
			'SlevomatCodingStandard.Classes.PropertySpacing' => fn(array $o, Translation $t) => $t->enable('dresscode/blank-lines', [
				'betweenMembers' => [0, $o['maxLinesCountBeforeWithoutComment'] ?? 1],
				'beforeDocumentedMember' => [0, $o['maxLinesCountBeforeWithComment'] ?? 1],
			]),
			'SlevomatCodingStandard.Classes.RequireMultiLineMethodSignature' => fn(array $o, Translation $t) => $t->lineLength(($o['minLineLength'] ?? 121) - 1)->enable('dresscode/multi-line-signature', [
				'promotedProperties' => ($o['withPromotedProperties'] ?? false) ? 'ownLines' : 'keep',
			]),
			'SlevomatCodingStandard.Classes.SuperfluousAbstractClassNaming' => fn(array $o, Translation $t) => $t->enable('dresscode/kind-in-class-name', ['kind' => 'forbidden']),
			'SlevomatCodingStandard.Classes.SuperfluousErrorNaming' => fn(array $o, Translation $t) => $t->enable('dresscode/kind-in-class-name', ['kind' => 'forbidden']),
			'SlevomatCodingStandard.Classes.SuperfluousInterfaceNaming' => fn(array $o, Translation $t) => $t->enable('dresscode/kind-in-class-name', ['kind' => 'forbidden']),
			'SlevomatCodingStandard.Classes.SuperfluousTraitNaming' => fn(array $o, Translation $t) => $t->enable('dresscode/kind-in-class-name', ['kind' => 'forbidden']),
			'SlevomatCodingStandard.Classes.TraitUseDeclaration' => fn(array $o, Translation $t) => $t->enable('dresscode/single-member-per-declaration', ['members' => ['trait']]),
			'SlevomatCodingStandard.Classes.TraitUseSpacing' => fn(array $o, Translation $t) => $t->enable('dresscode/blank-lines', [
				'betweenTraitUses' => $o['linesCountBetweenUses'] ?? 0,
				'afterTraitUses' => $o['linesCountAfterLastUse'] ?? 1,
			]),
			'SlevomatCodingStandard.Classes.UselessLateStaticBinding' => fn(array $o, Translation $t) => $t->enable('dresscode/self-for-current-class', ['onStatic' => true]),
			'SlevomatCodingStandard.Commenting.AnnotationName' => 'dresscode/annotation-name',
			'SlevomatCodingStandard.Commenting.ForbiddenAnnotations' => fn(array $o, Translation $t) => $t->enable('dresscode/forbidden-annotations', array_filter([
				'annotations' => $o['forbiddenAnnotations'] ?? null,
			], fn($v) => $v !== null)),
			'SlevomatCodingStandard.Commenting.ForbiddenComments' => fn(array $o, Translation $t) => $t->enable('dresscode/forbidden-phpdoc-lines', array_filter([
				'patterns' => $o['forbiddenCommentPatterns'] ?? null,
			], fn($v) => $v !== null)),
			'SlevomatCodingStandard.Commenting.RequireOneLinePropertyDocComment' => 'dresscode/property-phpdoc-single-line',
			'SlevomatCodingStandard.Commenting.UselessFunctionDocComment' => fn(array $o, Translation $t) => $t->enable('dresscode/useless-function-phpdoc', array_filter([
				'traversableTypeHints' => $o['traversableTypeHints'] ?? null,
			], fn($v) => $v !== null)),
			'SlevomatCodingStandard.Commenting.UselessInheritDocComment' => 'dresscode/useless-inheritdoc',
			'SlevomatCodingStandard.ControlStructures.BlockControlStructureSpacing' => fn(array $o, Translation $t) => $t->enable('dresscode/blank-lines', [
				'before' => array_fill_keys($o['controlStructures'] ?? ['if', 'do', 'while', 'for', 'foreach', 'switch', 'try'], $o['linesCountBefore'] ?? 1),
				'after' => array_fill_keys($o['controlStructures'] ?? ['if', 'do', 'while', 'for', 'foreach', 'switch', 'try'], $o['linesCountAfter'] ?? 1),
			]),
			'SlevomatCodingStandard.ControlStructures.DisallowContinueWithoutIntegerOperandInSwitch' => 'dresscode/no-continue-in-switch',
			'SlevomatCodingStandard.ControlStructures.DisallowYodaComparison' => fn(array $o, Translation $t) => $t->enable('dresscode/yoda', ['comparisons' => 'forbidden']),
			'SlevomatCodingStandard.ControlStructures.EarlyExit' => function (array $o, Translation $t) {
				$t->enable('dresscode/early-exit', ['minStatements' => ($o['ignoreTrailingIfWithOneInstruction'] ?? false) ? 2 : 1]);
				$t->enable('dresscode/useless-else', ['elseif' => true]);
			},
			'SlevomatCodingStandard.ControlStructures.JumpStatementsSpacing' => fn(array $o, Translation $t) => $t->enable('dresscode/blank-lines', [
				'before' => array_fill_keys(array_intersect($o['jumpStatements'] ?? ['break', 'continue', 'return', 'throw', 'yield'], ['break', 'continue', 'return', 'throw', 'yield']), $o['linesCountBefore'] ?? 1),
				'after' => array_fill_keys(array_intersect($o['jumpStatements'] ?? ['break', 'continue', 'return', 'throw', 'yield'], ['break', 'continue', 'return', 'throw', 'yield']), $o['linesCountAfter'] ?? 1),
			]),
			'SlevomatCodingStandard.ControlStructures.LanguageConstructWithParentheses' => 'dresscode/useless-construct-parentheses',
			'SlevomatCodingStandard.ControlStructures.NewWithoutParentheses' => 'dresscode/new-argument-parentheses',
			'SlevomatCodingStandard.ControlStructures.RequireMultiLineCondition' => fn(array $o, Translation $t) => $t->lineLength(($o['minLineLength'] ?? 121) - 1)->enable('dresscode/multi-line-condition', [
				'shape' => ($o['alwaysSplitAllConditionParts'] ?? false) ? 'perLine' : 'compact',
			]),
			'SlevomatCodingStandard.ControlStructures.RequireNullCoalesceEqualOperator' => 'dresscode/combined-assignment-operator',
			'SlevomatCodingStandard.ControlStructures.RequireNullCoalesceOperator' => 'dresscode/null-coalescing-operator',
			'SlevomatCodingStandard.ControlStructures.RequireShortTernaryOperator' => 'dresscode/short-ternary-operator',
			'SlevomatCodingStandard.ControlStructures.RequireTernaryOperator' => 'dresscode/ternary-for-simple-branch',
			'SlevomatCodingStandard.ControlStructures.UselessIfConditionWithReturn' => 'dresscode/useless-if-condition-with-return',
			'SlevomatCodingStandard.ControlStructures.UselessTernaryOperator' => 'dresscode/useless-ternary-operator',
			'SlevomatCodingStandard.Exceptions.DeadCatch' => 'dresscode/no-unreachable-catch',
			'SlevomatCodingStandard.Exceptions.ReferenceThrowableOnly' => 'dresscode/reference-throwable-only',
			'SlevomatCodingStandard.Exceptions.RequireNonCapturingCatch' => 'dresscode/useless-catch-variable',
			'SlevomatCodingStandard.Files.LineLength' => fn(array $o, Translation $t) => $t->lineLength($o['lineLengthLimit'] ?? 120)->enable('dresscode/line-length', [
				'ignoreImports' => $o['ignoreImports'] ?? true,
			]),
			'SlevomatCodingStandard.Functions.ArrowFunctionDeclaration' => 'dresscode/construct-spacing',
			'SlevomatCodingStandard.Functions.NamedArgumentSpacing' => 'dresscode/named-argument-spacing',
			'SlevomatCodingStandard.Functions.RequireArrowFunction' => fn(array $o, Translation $t) => $t->enable('dresscode/arrow-function', ['allowNested' => $o['allowNested'] ?? true]),
			'SlevomatCodingStandard.Functions.RequireTrailingCommaInCall' => fn(array $o, Translation $t) => $t->enable('dresscode/trailing-comma', ['multiLine' => ['arguments'], 'singleLine' => false]),
			'SlevomatCodingStandard.Functions.RequireTrailingCommaInDeclaration' => fn(array $o, Translation $t) => $t->enable('dresscode/trailing-comma', ['multiLine' => ['parameters'], 'singleLine' => false]),
			'SlevomatCodingStandard.Functions.StaticClosure' => 'dresscode/static-closure',
			'SlevomatCodingStandard.Functions.StrictCall' => 'dresscode/strict-call',
			'SlevomatCodingStandard.Functions.UselessParameterDefaultValue' => 'dresscode/useless-parameter-default',
			'SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses' => fn(array $o, Translation $t) => $t->enable('dresscode/ordered-imports', [
				'order' => 'alphabetical',
				'caseSensitive' => $o['caseSensitive'] ?? false,
			]),
			'SlevomatCodingStandard.Namespaces.DisallowGroupUse' => fn(array $o, Translation $t) => $t->enable('dresscode/import-notation', ['groupUse' => 'expand']),
			'SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalConstants' => function (array $o, Translation $t) {
				$constants = ($o['include'] ?? []) === [] ? ['*' => 'qualified'] : [];
				$shapes = ($o['include'] ?? []) === [] ? ['*' => 'backslash'] : [];
				foreach ($o['include'] ?? [] as $name) {
					$constants[$name] = 'qualified';
					$shapes[$name] = 'backslash';
				}

				foreach ($o['exclude'] ?? [] as $name) {
					$constants[$name] = $shapes[$name] = 'keep';
				}

				$t->enable('dresscode/name-notation', ['globalConstants' => $shapes]);
				$t->enable('dresscode/name-fallback', ['constants' => $constants]);
			},
			'SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalFunctions' => function (array $o, Translation $t) {
				// the special functions join the include, and only an include left empty names every function
				$special = $o['includeSpecialFunctions'] ?? false;
				$functions = ($o['include'] ?? []) === [] && !$special ? ['*' => 'qualified'] : [];
				// name-notation has no key for the special functions, so with them the backslash goes for every function
				$shapes = ($o['include'] ?? []) === [] || $special ? ['*' => 'backslash'] : [];
				foreach ($o['include'] ?? [] as $name) {
					$functions[$name] = 'qualified';
					$shapes[$name] = 'backslash';
				}

				foreach ($o['exclude'] ?? [] as $name) {
					$functions[$name] = $shapes[$name] = 'keep';
				}

				$t->enable('dresscode/name-notation', ['globalFunctions' => $shapes]);
				$t->enable('dresscode/name-fallback', array_filter([
					'functions' => $functions ?: null,
					'optimizedFunctions' => $special ? 'qualified' : null,
				]));
			},
			'SlevomatCodingStandard.Namespaces.MultipleUsesPerLine' => fn(array $o, Translation $t) => $t->enable('dresscode/import-notation', ['groupUse' => 'keep']),
			'SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly' => function (array $o, Translation $t) {
				// a global name written with the backslash may stay so, and a bare one may stand on the fallback, or else it is imported
				$shape = fn(bool $backslash) => ['*' => $backslash ? 'keep' : 'import'];
				$t->enable('dresscode/name-notation', [
					'classes' => 'import',
					'globalClasses' => ($o['allowFullyQualifiedGlobalClasses'] ?? false) ? 'keep' : 'import',
					'functions' => 'import',
					'globalFunctions' => $shape($o['allowFullyQualifiedGlobalFunctions'] ?? false),
					'constants' => 'import',
					'globalConstants' => $shape($o['allowFullyQualifiedGlobalConstants'] ?? false),
				]);
				$fallback = array_filter([
					'functions' => ($o['allowFallbackGlobalFunctions'] ?? true) ? null : ['*' => 'qualified'],
					'constants' => ($o['allowFallbackGlobalConstants'] ?? true) ? null : ['*' => 'qualified'],
				]);
				if ($fallback !== []) {
					$t->enable('dresscode/name-fallback', $fallback);
				}
				if (($o['allowPartialUses'] ?? true) === false) {
					$t->warn('ReferenceUsedNamesOnly with allowPartialUses=false has no equivalent, DressCode lets a partial name stand');
				}
			},
			'SlevomatCodingStandard.Namespaces.UnusedUses' => fn(array $o, Translation $t) => $t->enable('dresscode/unused-imports', ['searchAnnotations' => $o['searchAnnotations'] ?? false]),
			'SlevomatCodingStandard.Namespaces.UseDoesNotStartWithBackslash' => 'dresscode/no-leading-backslash-in-import',
			'SlevomatCodingStandard.Namespaces.UseFromSameNamespace' => 'dresscode/use-from-same-namespace',
			'SlevomatCodingStandard.Namespaces.UselessAlias' => 'dresscode/useless-alias',
			'SlevomatCodingStandard.Numbers.RequireNumericLiteralSeparator' => fn(array $o, Translation $t) => $t->enable('dresscode/numeric-literal-separator', [
				'minDigitsBeforeDecimalPoint' => $o['minDigitsBeforeDecimalPoint'] ?? 4,
				'minDigitsAfterDecimalPoint' => $o['minDigitsAfterDecimalPoint'] ?? 4,
			]),
			'SlevomatCodingStandard.Operators.NegationOperatorSpacing' => 'dresscode/unary-operator-spacing',
			'SlevomatCodingStandard.Operators.ReferenceSpacing' => 'dresscode/reference-spacing',
			'SlevomatCodingStandard.Operators.RequireCombinedAssignmentOperator' => 'dresscode/combined-assignment-operator',
			'SlevomatCodingStandard.Operators.SpreadOperatorSpacing' => 'dresscode/spread-operator-spacing',
			'SlevomatCodingStandard.PHP.DisallowDirectMagicInvokeCall' => 'dresscode/no-direct-invoke-call',
			'SlevomatCodingStandard.PHP.OptimizedFunctionsWithoutUnpacking' => 'dresscode/optimized-call-notation',
			'SlevomatCodingStandard.PHP.RequireExplicitAssertion' => 'dresscode/explicit-assertion',
			'SlevomatCodingStandard.PHP.RequireNowdoc' => 'dresscode/nowdoc-without-interpolation',
			'SlevomatCodingStandard.PHP.ShortList' => 'dresscode/short-array-syntax',
			'SlevomatCodingStandard.PHP.TypeCast' => 'dresscode/cast-canonical-type',
			'SlevomatCodingStandard.PHP.UselessSemicolon' => 'dresscode/no-empty-statement',
			'SlevomatCodingStandard.TypeHints.DeclareStrictTypes' => function (array $o, Translation $t) {
				if (($o['spacesCountAroundEqualsSign'] ?? 1) !== 0) {
					$t->warn('DeclareStrictTypes with spaces around the equals sign has no equivalent, DressCode writes declare(strict_types=1) without spaces');
				}
				$t->enable('dresscode/strict-types-required', ['placement' => ($o['declareOnFirstLine'] ?? false) ? 'openingTagLine' : 'ownLine']);
			},
			'SlevomatCodingStandard.TypeHints.DisallowArrayTypeHintSyntax' => fn(array $o, Translation $t) => $t->enable('dresscode/phpdoc-canonical-types', ['arrayNotation' => 'generic']),
			'SlevomatCodingStandard.TypeHints.DNFTypeHintFormat' => 'dresscode/union-type-format',
			'SlevomatCodingStandard.TypeHints.LongTypeHints' => 'dresscode/phpdoc-canonical-types',
			'SlevomatCodingStandard.TypeHints.NullTypeHintOnLastPosition' => 'dresscode/phpdoc-null-last',
			'SlevomatCodingStandard.TypeHints.NullableTypeForNullDefaultValue' => 'dresscode/nullable-type-for-default-null',
			'SlevomatCodingStandard.TypeHints.ParameterTypeHint' => fn(array $o, Translation $t) => $t->enable('dresscode/type-hint-required', array_filter([
				'parameters' => true,
				'properties' => false,
				'returns' => false,
				'traversableTypeHints' => $o['traversableTypeHints'] ?? null,
			], fn($v) => $v !== null)),
			'SlevomatCodingStandard.TypeHints.ParameterTypeHintSpacing' => 'dresscode/type-hint-spacing',
			'SlevomatCodingStandard.TypeHints.PropertyTypeHint' => fn(array $o, Translation $t) => $t->enable('dresscode/type-hint-required', array_filter([
				'parameters' => false,
				'properties' => true,
				'returns' => false,
				'traversableTypeHints' => $o['traversableTypeHints'] ?? null,
			], fn($v) => $v !== null)),
			'SlevomatCodingStandard.TypeHints.PropertyTypeHintSpacing' => 'dresscode/type-hint-spacing',
			'SlevomatCodingStandard.TypeHints.ReturnTypeHint' => fn(array $o, Translation $t) => $t->enable('dresscode/type-hint-required', array_filter([
				'parameters' => false,
				'properties' => false,
				'returns' => true,
				'traversableTypeHints' => $o['traversableTypeHints'] ?? null,
			], fn($v) => $v !== null)),
			'SlevomatCodingStandard.TypeHints.ReturnTypeHintSpacing' => 'dresscode/type-hint-spacing',
			'SlevomatCodingStandard.TypeHints.UnionTypeHintFormat' => fn(array $o, Translation $t) => $t->enable('dresscode/union-type-format', array_filter([
				'shortNullable' => isset($o['shortNullable']) ? $o['shortNullable'] === 'yes' : null,
				'nullPosition' => $o['nullPosition'] ?? null,
			], fn($v) => $v !== null)),
			'SlevomatCodingStandard.TypeHints.UselessConstantTypeHint' => 'dresscode/useless-constant-var-annotation',
			'SlevomatCodingStandard.Variables.DuplicateAssignmentToVariable' => 'dresscode/no-duplicate-assignment',
			'Squiz.Arrays.ArrayBracketSpacing' => 'dresscode/offset-bracket-spacing',
			'Squiz.Classes.SelfMemberReference' => 'dresscode/self-for-current-class',
			'Squiz.Classes.ValidClassName' => fn(array $o, Translation $t) => $t->enable('dresscode/name-casing', ['classes' => 'PascalCase']),
			'Squiz.Commenting.DocCommentAlignment' => 'dresscode/phpdoc-alignment',
			'Squiz.Commenting.FunctionComment.DuplicateReturn' => 'dresscode/no-duplicate-return-annotation',
			'Squiz.Commenting.FunctionComment.ExtraParamComment' => 'dresscode/no-unknown-param-annotation',
			'Squiz.Commenting.VariableComment' => 'dresscode/property-phpdoc-required',
			'Squiz.ControlStructures.ControlSignature' => 'dresscode/braces-position',
			'Squiz.Functions.FunctionDeclarationArgumentSpacing' => 'dresscode/comma-spacing',
			'Squiz.Functions.MultiLineFunctionDeclaration' => 'dresscode/braces-position',
			'Squiz.NamingConventions.ValidFunctionName' => fn(array $o, Translation $t) => $t->enable('dresscode/name-casing', ['methods' => 'camelCase', 'functions' => 'camelCase']),
			'Squiz.NamingConventions.ValidVariableName' => function (array $o, Translation $t) {
				$t->warn('ValidVariableName also rules on the underscore prefix of private properties, which DressCode does not');
				$t->enable('dresscode/name-casing', ['properties' => 'camelCase', 'variables' => 'camelCase']);
			},
			'Squiz.Operators.ValidLogicalOperators' => 'dresscode/symbolic-logical-operators',
			'Squiz.PHP.DiscouragedFunctions' => fn(array $o, Translation $t) => $t->enable('dresscode/forbidden-functions', ['functions' => ['error_log', 'print_r', 'var_dump']]),
			'Squiz.PHP.GlobalKeyword' => 'dresscode/no-global-keyword',
			'Squiz.PHP.InnerFunctions' => 'dresscode/no-inner-functions',
			'Squiz.PHP.LowercasePHPFunctions' => 'dresscode/native-function-casing',
			'Squiz.Scope.MethodScope' => 'dresscode/visibility-required',
			'Squiz.Scope.StaticThisUsage' => 'dresscode/no-this-in-static-context',
			'Squiz.Strings.ConcatenationSpacing' => fn(array $o, Translation $t) => $t->enable('dresscode/concat-spacing', ['spacing' => ($o['spacing'] ?? 0) > 0 ? 'single' : 'none']),
			'Squiz.Strings.DoubleQuoteUsage' => fn(array $o, Translation $t) => $t->enable('dresscode/string-quotes', ['quotes' => 'single']),
			'Squiz.Strings.EchoedStrings' => 'dresscode/useless-construct-parentheses',
			'Squiz.WhiteSpace.CastSpacing' => 'dresscode/cast-canonical-type',
			'Squiz.WhiteSpace.ControlStructureSpacing' => fn(array $o, Translation $t) => $t->enable('dresscode/blank-lines', ['beforeBlockBrace' => 0]),
			'Squiz.WhiteSpace.FunctionOpeningBraceSpace' => fn(array $o, Translation $t) => $t->enable('dresscode/blank-lines', ['afterBlockBrace' => 0]),
			'Squiz.WhiteSpace.FunctionSpacing' => function (array $o, Translation $t) {
				$t->warn('FunctionSpacing leaves the blank lines around classes alone, which DressCode counts with the spacing of functions');
				$t->enable('dresscode/blank-lines', [
					'betweenDeclarations' => $o['spacing'] ?? 2,
					'betweenMethods' => $o['spacing'] ?? 2,
					'beforeFirstMethod' => $o['spacingBeforeFirst'] ?? 2,
					'afterLastMethod' => $o['spacingAfterLast'] ?? 2,
				]);
			},
			'Squiz.WhiteSpace.LogicalOperatorSpacing' => 'dresscode/binary-operator-spacing',
			'Squiz.WhiteSpace.ObjectOperatorSpacing' => 'dresscode/object-operator-spacing',
			'Squiz.WhiteSpace.OperatorSpacing' => 'dresscode/binary-operator-spacing',
			'Squiz.WhiteSpace.OperatorSpacing.Unary' => 'dresscode/unary-operator-spacing',
			'Squiz.WhiteSpace.ScopeKeywordSpacing' => 'dresscode/double-colon-spacing',
			'Squiz.WhiteSpace.SemicolonSpacing' => 'dresscode/semicolon-spacing',
			'Squiz.WhiteSpace.SuperfluousWhitespace' => 'dresscode/no-trailing-whitespace',
		];
	}


	/**
	 * Rules of a phpcs.xml ruleset: every <rule ref> with the properties it sets and every <exclude> inside it as
	 * a rule switched off, and what the ruleset says that no rule carries. A property is typed the way the sniff
	 * declaring it would type it, because XML carries every value as a string.
	 * @return array{array<string, bool|array<string, mixed>>, list<string>}
	 * @throws ConfigurationException
	 */
	public static function readConfig(string $file): array
	{
		$xml = is_file($file) ? @simplexml_load_file($file) : false; // @ - reported as exception
		if ($xml === false) {
			throw new ConfigurationException("File $file is not a readable phpcs ruleset.");
		}

		$rules = $warnings = [];
		if (isset($xml->{'exclude-pattern'})) {
			$warnings[] = 'The paths the ruleset excludes are not carried over; set them with excludePaths.';
		}

		foreach ($xml->rule as $rule) {
			$ref = (string) $rule['ref'];
			$properties = [];
			foreach ($rule->properties->property ?? [] as $property) {
				$properties[(string) $property['name']] = self::readValue($property);
			}

			$rules[$ref] = $properties === [] ? true : $properties;
			foreach ($rule->exclude ?? [] as $exclude) {
				$rules[(string) $exclude['name']] = false;
			}

			if (isset($rule->{'exclude-pattern'})) {
				$warnings[] = "The paths excluded from $ref are not carried over; turn its rules off there with an override.";
			}
		}

		return [$rules, $warnings];
	}


	private static function readValue(\SimpleXMLElement $property): mixed
	{
		if ((string) $property['type'] === 'array') {
			$items = [];
			foreach ($property->element as $element) {
				$key = (string) $element['key'];
				$value = (string) $element['value'];
				if ($key === '') {
					$items[] = $value;
				} else {
					$items[$key] = $value;
				}
			}

			return $items === []
				? array_map(trim(...), explode(',', (string) $property['value']))
				: $items;
		}

		$value = (string) $property['value'];
		return match (true) {
			$value === 'true' => true,
			$value === 'false' => false,
			ctype_digit($value) => (int) $value,
			default => $value,
		};
	}


	/** @return array<string, string>  standard => preset */
	public static function getSets(): array
	{
		return [
			'PSR1' => 'dresscode/psr12',
			'PSR2' => 'dresscode/psr12',
			'PSR12' => 'dresscode/psr12',
		];
	}
}
