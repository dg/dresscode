<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

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
	 *     enable for its properties; every property is read through `??` so that a sniff with none translates too
	 */
	public static function getTranslations(): array
	{
		return [
			'Generic.Arrays.DisallowLongArraySyntax' => 'dresscode/shortArraySyntax',
			'Generic.CodeAnalysis.RequireExplicitBooleanOperatorPrecedence' => 'dresscode/explicitOperatorPrecedence',
			'Generic.CodeAnalysis.UnnecessaryFinalModifier' => 'dresscode/uselessModifier',
			'Generic.ControlStructures.InlineControlStructure' => 'dresscode/controlStructureBraces',
			'Generic.Files.ByteOrderMark' => 'dresscode/noBom',
			'Generic.Files.LineEndings' => 'dresscode/lineEnding',
			'Generic.Files.LineLength' => fn(array $o, Translation $t) => $t->lineLength(($o['absoluteLineLimit'] ?? 100) ?: ($o['lineLimit'] ?? 80))->enable('dresscode/lineLength'),
			'Generic.Formatting.DisallowMultipleStatements' => 'dresscode/singleStatementPerLine',
			'Generic.Functions.FunctionCallArgumentSpacing' => 'dresscode/commaSpacing',
			'Generic.Functions.FunctionCallArgumentSpacing.SpaceBeforeOpenBracket' => 'dresscode/functionNameSpacing',
			'Generic.NamingConventions.CamelCapsFunctionName' => fn(array $o, Translation $t) => $t->enable('dresscode/nameCasing', ['method' => 'camelCase', 'function' => 'camelCase']),
			'Generic.NamingConventions.UpperCaseConstantName' => function (array $o, Translation $t) {
				$t->warn('`UpperCaseConstantName` also checks `define()`, which DressCode does not.');
				$t->enable('dresscode/nameCasing', ['constant' => 'UPPER_CASE']);
			},
			'Generic.PHP.DeprecatedFunctions' => 'dresscode/noDeprecatedFunctions',
			'Generic.PHP.DisallowShortOpenTag' => 'dresscode/fullOpeningTag',
			'Generic.PHP.ForbiddenFunctions' => fn(array $o, Translation $t) => $t->enable(
				'dresscode/forbiddenFunctions',
				array_map(fn($v) => $v === null ? null : "use `$v()`", $o['forbiddenFunctions'] ?? ['sizeof' => 'count', 'delete' => 'unset']),
			),
			'Generic.PHP.LowerCaseConstant' => 'dresscode/trueFalseNullCasing',
			'Generic.PHP.LowerCaseKeyword' => 'dresscode/keywordCasing',
			'Generic.PHP.LowerCaseType' => function (array $o, Translation $t) {
				$t->warn('`LowerCaseType` also checks the case of `int`, `string`, `void` and the like in a declaration, which DressCode does not.');
				$t->enable('dresscode/castCanonicalType')->enable('dresscode/keywordCasing');
			},
			'Generic.PHP.RequireStrictTypes' => 'dresscode/strictTypesRequired',
			'Generic.PHP.SAPIUsage' => fn(array $o, Translation $t) => $t->enable('dresscode/forbiddenFunctions', ['php_sapi_name' => 'use `PHP_SAPI`']),
			'Generic.Strings.UnnecessaryStringConcat' => fn(array $o, Translation $t) => $t->enable('dresscode/uselessStringConcat', ['allowMultiline' => $o['allowMultiline'] ?? false]),
			'Generic.WhiteSpace.DisallowSpaceIndent' => 'dresscode/indentation',
			'Generic.WhiteSpace.DisallowTabIndent' => 'dresscode/indentation',
			'Generic.WhiteSpace.IncrementDecrementSpacing' => 'dresscode/unaryOperatorSpacing',
			'Generic.WhiteSpace.LanguageConstructSpacing' => 'dresscode/constructSpacing',
			'Generic.WhiteSpace.ScopeIndent' => 'dresscode/indentation',
			'PEAR.Commenting.InlineComment' => 'dresscode/noHashComments',
			'PEAR.Functions.ValidDefaultValue' => 'dresscode/uselessParameterDefault',
			'PEAR.WhiteSpace.ObjectOperatorIndent' => function (array $o, Translation $t) {
				if ($o['multilevel'] ?? false) {
					$t->warn('`ObjectOperatorIndent` with `multilevel=true` has no equivalent; DressCode indents every operator of a chain one level deeper than its start.');
				}
				$t->enable('dresscode/indentation');
			},
			'PSR1.Methods.CamelCapsMethodName' => fn(array $o, Translation $t) => $t->enable('dresscode/nameCasing', ['method' => 'camelCase']),
			'PSR12.Classes.AnonClassDeclaration' => function (array $o, Translation $t) {
				$t->warn('`AnonClassDeclaration` also puts each interface of a multi-line `implements` list on its own line, which DressCode does not.');
				$t->enable('dresscode/bracesPosition')->enable('dresscode/classDefinitionSpacing')->enable('dresscode/parenthesesSpacing');
			},
			'PSR12.Classes.ClassInstantiation' => fn(array $o, Translation $t) => $t->enable('dresscode/newArgumentParentheses', ['anonymousClass' => 'keep']),
			'PSR12.Classes.ClosingBrace' => function (array $o, Translation $t) {
				$t->warn('`ClosingBrace` also forbids a comment after the closing brace of a class or a function, which DressCode does not.');
				$t->enable('dresscode/singleMemberPerLine')->enable('dresscode/singleStatementPerLine');
			},
			'PSR12.Classes.OpeningBraceSpace' => fn(array $o, Translation $t) => $t->enable('dresscode/blankLines', ['beforeFirstMember' => 0, 'beforeFirstMethod' => 0]),
			'PSR12.ControlStructures.BooleanOperatorPlacement' => function (array $o, Translation $t) {
				$only = $o['allowOnly'] ?? null;
				if ($only === 'first') {
					$t->enable('dresscode/multilineCondition', ['operatorPosition' => 'start']);
				} elseif ($only === 'last') {
					$t->warn('`BooleanOperatorPlacement` with `allowOnly=last` has no equivalent; DressCode moves a boolean operator only to the start of a line.');
				} else {
					$t->warn('`BooleanOperatorPlacement` forbids a condition mixing boolean operators at the start and at the end of its lines, which DressCode does not check.');
					$t->enable('dresscode/multilineCondition');
				}
			},
			'PSR12.ControlStructures.ControlStructureSpacing' => function (array $o, Translation $t) {
				$t->warn('`ControlStructureSpacing` also puts the parentheses of a multi-line `for`, `foreach`, `switch`, `catch` and `match` on lines of their own, which DressCode does not.');
				$t->enable('dresscode/parenthesesSpacing')->enable('dresscode/multilineCondition')->enable('dresscode/indentation');
			},
			'PSR12.Files.DeclareStatement' => function (array $o, Translation $t) {
				$t->warn('`DeclareStatement` also checks the case of the directive of a `declare`, which DressCode does not.');
				$t->enable('dresscode/declareSpacing')->enable('dresscode/bracesPosition');
			},
			'PSR12.Files.FileHeader' => 'dresscode/blankLines',
			'PSR12.Files.ImportStatement' => 'dresscode/uselessImportBackslash',
			'PSR12.Files.OpenTag' => function (array $o, Translation $t) {
				$t->warn('`OpenTag` leaves the line below the opening tag as it is, where DressCode puts a blank line.');
				$t->enable('dresscode/blankLines', ['afterOpeningTag' => 1]);
			},
			'PSR12.Functions.NullableTypeDeclaration' => 'dresscode/typeHintSpacing',
			'PSR12.Functions.ReturnTypeDeclaration' => 'dresscode/typeHintSpacing',
			'PSR12.Keywords.ShortFormTypeKeywords' => 'dresscode/castCanonicalType',
			'PSR12.Operators.OperatorSpacing' => fn(array $o, Translation $t) => $t
				->enable('dresscode/binaryOperatorSpacing')
				->enable('dresscode/ternaryOperatorSpacing')
				->enable('dresscode/concatSpacing', ['spacing' => 'single']),
			'PSR12.Properties.ConstantVisibility' => 'dresscode/visibilityRequired',
			'PSR12.Traits.UseDeclaration' => function (array $o, Translation $t) {
				$t->warn('`UseDeclaration` also lays out the conflict block of a trait use, its opening brace on the line of `use` and a rule per line, which DressCode does not.');
				$t->enable('dresscode/singleMemberPerDeclaration', ['members' => ['trait']])
					->enable('dresscode/orderedMembers', ['order' => ['traitUse']])
					->enable('dresscode/blankLines', ['beforeFirstMember' => 0, 'betweenTraitUses' => 0, 'afterTraitUses' => 1, 'afterLastMember' => 0])
					->enable('dresscode/constructSpacing')
					->enable('dresscode/semicolonSpacing');
			},
			'PSR2.Classes.ClassDeclaration' => 'dresscode/bracesPosition',
			'PSR2.Classes.ClassDeclaration.SpaceBeforeKeyword' => 'dresscode/classDefinitionSpacing',
			'PSR2.Classes.PropertyDeclaration' => 'dresscode/visibilityRequired',
			'PSR2.ControlStructures.ControlStructureSpacing' => 'dresscode/parenthesesSpacing',
			'PSR2.ControlStructures.ElseIfDeclaration' => 'dresscode/elseifKeyword',
			'PSR2.ControlStructures.SwitchDeclaration' => function (array $o, Translation $t) {
				$t->warn('`SwitchDeclaration` also starts the body of a case on the line below it and forbids braces around it, which DressCode does not.');
				$t->enable('dresscode/switchCaseColon')
					->enable('dresscode/switchCaseSpacing')
					->enable('dresscode/fallThroughComment')
					->enable('dresscode/keywordCasing')
					->enable('dresscode/constructSpacing')
					->enable('dresscode/singleStatementPerLine')
					->enable('dresscode/indentation');
			},
			'PSR2.ControlStructures.SwitchDeclaration.SpaceBeforeColonCASE' => 'dresscode/switchCaseSpacing',
			'PSR2.Files.ClosingTag' => 'dresscode/noClosingTag',
			'PSR2.Files.EndFileNewline' => 'dresscode/eofLineEnding',
			'PSR2.Methods.FunctionCallSignature' => function (array $o, Translation $t) {
				if (($o['requiredSpacesAfterOpen'] ?? 0) > 0 || ($o['requiredSpacesBeforeClose'] ?? 0) > 0) {
					$t->warn('`FunctionCallSignature` with spaces inside the parentheses has no equivalent; DressCode writes none.');
				}
				if ($o['allowMultipleArguments'] ?? false) {
					$t->warn('`FunctionCallSignature` with `allowMultipleArguments=true` has no equivalent; DressCode puts every argument of a multi-line call on its own line.');
				}
				$t->enable('dresscode/functionNameSpacing')
					->enable('dresscode/parenthesesSpacing')
					->enable('dresscode/multilineCall')
					->enable('dresscode/indentation');
			},
			'PSR2.Methods.FunctionClosingBrace' => fn(array $o, Translation $t) => $t->enable('dresscode/blankLines', ['beforeBlockClosingBrace' => 0]),
			'PSR2.Methods.MethodDeclaration' => function (array $o, Translation $t) {
				$t->warn('`MethodDeclaration` also rules on the underscore prefix of method names, which DressCode does not.');
				$t->enable('dresscode/visibilityRequired');
			},
			'PSR2.Namespaces.NamespaceDeclaration' => 'dresscode/indentation',
			'PSR2.Namespaces.UseDeclaration' => 'dresscode/blankLines',
			'SlevomatCodingStandard.Arrays.MultiLineArrayEndBracketPlacement' => 'dresscode/indentation',
			'SlevomatCodingStandard.Arrays.SingleLineArrayWhitespace' => 'dresscode/arraySpacing',
			'SlevomatCodingStandard.Arrays.TrailingArrayComma' => fn(array $o, Translation $t) => $t->enable('dresscode/trailingComma', ['array' => 'required']),
			'SlevomatCodingStandard.Attributes.AttributeAndTargetSpacing' => fn(array $o, Translation $t) => $t->enable('dresscode/blankLines', ['afterPhpdoc' => $o['linesCountBetweenAttributeAndTarget'] ?? 0]),
			'SlevomatCodingStandard.Attributes.DisallowMultipleAttributesPerLine' => 'dresscode/attributePosition',
			'SlevomatCodingStandard.Attributes.RequireAttributeAfterDocComment' => 'dresscode/attributeAfterPhpdoc',
			'SlevomatCodingStandard.Classes.BackedEnumTypeSpacing' => 'dresscode/typeHintSpacing',
			'SlevomatCodingStandard.Classes.ClassConstantVisibility' => 'dresscode/visibilityRequired',
			'SlevomatCodingStandard.Classes.ConstantSpacing' => 'dresscode/blankLines',
			'SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition' => fn(array $o, Translation $t) => $t->enable('dresscode/singleMemberPerDeclaration', ['members' => ['constant']]),
			'SlevomatCodingStandard.Classes.DisallowMultiPropertyDefinition' => fn(array $o, Translation $t) => $t->enable('dresscode/singleMemberPerDeclaration', ['members' => ['property']]),
			'SlevomatCodingStandard.Classes.EmptyLinesAroundClassBraces' => fn(array $o, Translation $t) => $t->enable('dresscode/blankLines', [
				'beforeFirstMember' => $o['linesCountAfterOpeningBrace'] ?? 1,
				'afterLastMember' => $o['linesCountBeforeClosingBrace'] ?? 1,
			]),
			'SlevomatCodingStandard.Classes.ModernClassNameReference' => fn(array $o, Translation $t) => $t->enable('dresscode/getClassNotation', ['onObjects' => $o['enableOnObjects'] ?? false]),
			'SlevomatCodingStandard.Classes.PropertyDeclaration' => 'dresscode/visibilityRequired',
			'SlevomatCodingStandard.Classes.PropertySpacing' => fn(array $o, Translation $t) => $t->enable('dresscode/blankLines', [
				'betweenMembers' => [0, $o['maxLinesCountBeforeWithoutComment'] ?? 1],
				'beforeDocumentedMember' => [0, $o['maxLinesCountBeforeWithComment'] ?? 1],
			]),
			'SlevomatCodingStandard.Classes.RequireMultiLineMethodSignature' => fn(array $o, Translation $t) => $t->lineLength(($o['minLineLength'] ?? 121) - 1)->enable('dresscode/multilineSignature', [
				'promotedProperty' => ($o['withPromotedProperties'] ?? false) ? 'always' : 'keep',
			]),
			'SlevomatCodingStandard.Classes.SuperfluousAbstractClassNaming' => fn(array $o, Translation $t) => $t->enable('dresscode/kindInClassName', ['kind' => 'forbidden']),
			'SlevomatCodingStandard.Classes.SuperfluousErrorNaming' => fn(array $o, Translation $t) => $t->enable('dresscode/kindInClassName', ['kind' => 'forbidden']),
			'SlevomatCodingStandard.Classes.SuperfluousInterfaceNaming' => fn(array $o, Translation $t) => $t->enable('dresscode/kindInClassName', ['kind' => 'forbidden']),
			'SlevomatCodingStandard.Classes.SuperfluousTraitNaming' => fn(array $o, Translation $t) => $t->enable('dresscode/kindInClassName', ['kind' => 'forbidden']),
			'SlevomatCodingStandard.Classes.TraitUseDeclaration' => fn(array $o, Translation $t) => $t->enable('dresscode/singleMemberPerDeclaration', ['members' => ['trait']]),
			'SlevomatCodingStandard.Classes.TraitUseSpacing' => fn(array $o, Translation $t) => $t->enable('dresscode/blankLines', [
				'betweenTraitUses' => $o['linesCountBetweenUses'] ?? 0,
				'afterTraitUses' => $o['linesCountAfterLastUse'] ?? 1,
			]),
			'SlevomatCodingStandard.Classes.UselessLateStaticBinding' => fn(array $o, Translation $t) => $t->enable('dresscode/selfForCurrentClass', ['onStatic' => true]),
			'SlevomatCodingStandard.Commenting.AnnotationName' => 'dresscode/annotationCasing',
			'SlevomatCodingStandard.Commenting.ForbiddenAnnotations' => fn(array $o, Translation $t) => $t->enable('dresscode/forbiddenAnnotations', array_filter([
				'annotations' => $o['forbiddenAnnotations'] ?? null,
			], fn($v) => $v !== null)),
			'SlevomatCodingStandard.Commenting.ForbiddenComments' => fn(array $o, Translation $t) => $t->enable('dresscode/forbiddenPhpdocLines', array_filter([
				'patterns' => $o['forbiddenCommentPatterns'] ?? null,
			], fn($v) => $v !== null)),
			'SlevomatCodingStandard.Commenting.RequireOneLinePropertyDocComment' => 'dresscode/propertyPhpdocSingleline',
			'SlevomatCodingStandard.Commenting.UselessFunctionDocComment' => fn(array $o, Translation $t) => $t->enable('dresscode/uselessFunctionPhpdoc', array_filter([
				'traversableTypeHints' => $o['traversableTypeHints'] ?? null,
			], fn($v) => $v !== null)),
			'SlevomatCodingStandard.Commenting.UselessInheritDocComment' => 'dresscode/uselessInheritdoc',
			'SlevomatCodingStandard.ControlStructures.BlockControlStructureSpacing' => fn(array $o, Translation $t) => $t->enable('dresscode/blankLines', [
				'before' => array_fill_keys($o['controlStructures'] ?? ['if', 'do', 'while', 'for', 'foreach', 'switch', 'try'], $o['linesCountBefore'] ?? 1),
				'after' => array_fill_keys($o['controlStructures'] ?? ['if', 'do', 'while', 'for', 'foreach', 'switch', 'try'], $o['linesCountAfter'] ?? 1),
			]),
			'SlevomatCodingStandard.ControlStructures.DisallowContinueWithoutIntegerOperandInSwitch' => 'dresscode/noContinueInSwitch',
			'SlevomatCodingStandard.ControlStructures.DisallowYodaComparison' => fn(array $o, Translation $t) => $t->enable('dresscode/yoda', ['comparisons' => 'forbidden']),
			'SlevomatCodingStandard.ControlStructures.EarlyExit' => function (array $o, Translation $t) {
				$t->enable('dresscode/earlyExit', ['minStatements' => ($o['ignoreTrailingIfWithOneInstruction'] ?? false) ? 2 : 1]);
				$t->enable('dresscode/uselessElse', ['elseif' => true]);
			},
			'SlevomatCodingStandard.ControlStructures.JumpStatementsSpacing' => fn(array $o, Translation $t) => $t->enable('dresscode/blankLines', [
				'before' => array_fill_keys(array_intersect($o['jumpStatements'] ?? ['break', 'continue', 'return', 'throw', 'yield'], ['break', 'continue', 'return', 'throw', 'yield']), $o['linesCountBefore'] ?? 1),
				'after' => array_fill_keys(array_intersect($o['jumpStatements'] ?? ['break', 'continue', 'return', 'throw', 'yield'], ['break', 'continue', 'return', 'throw', 'yield']), $o['linesCountAfter'] ?? 1),
			]),
			'SlevomatCodingStandard.ControlStructures.LanguageConstructWithParentheses' => 'dresscode/uselessConstructParentheses',
			'SlevomatCodingStandard.ControlStructures.NewWithoutParentheses' => 'dresscode/newArgumentParentheses',
			'SlevomatCodingStandard.ControlStructures.RequireMultiLineCondition' => fn(array $o, Translation $t) => $t->lineLength(($o['minLineLength'] ?? 121) - 1)->enable('dresscode/multilineCondition', [
				'shape' => ($o['alwaysSplitAllConditionParts'] ?? false) ? 'perLine' : 'compact',
			]),
			'SlevomatCodingStandard.ControlStructures.RequireNullCoalesceEqualOperator' => 'dresscode/combinedAssignmentForRepeatedTarget',
			'SlevomatCodingStandard.ControlStructures.RequireNullCoalesceOperator' => 'dresscode/nullCoalescingForNullTernary',
			'SlevomatCodingStandard.ControlStructures.RequireShortTernaryOperator' => 'dresscode/shortTernaryForRepeatedCondition',
			'SlevomatCodingStandard.ControlStructures.RequireTernaryOperator' => 'dresscode/ternaryForIf',
			'SlevomatCodingStandard.ControlStructures.UselessIfConditionWithReturn' => 'dresscode/returnForBooleanIf',
			'SlevomatCodingStandard.ControlStructures.UselessTernaryOperator' => 'dresscode/uselessTernaryOperator',
			'SlevomatCodingStandard.Exceptions.DeadCatch' => 'dresscode/noUnreachableCatches',
			'SlevomatCodingStandard.Exceptions.ReferenceThrowableOnly' => 'dresscode/referenceThrowableOnly',
			'SlevomatCodingStandard.Exceptions.RequireNonCapturingCatch' => 'dresscode/uselessCatchVariable',
			'SlevomatCodingStandard.Files.LineLength' => fn(array $o, Translation $t) => $t->lineLength($o['lineLengthLimit'] ?? 120)->enable('dresscode/lineLength', [
				'ignoreImports' => $o['ignoreImports'] ?? true,
			]),
			'SlevomatCodingStandard.Functions.ArrowFunctionDeclaration' => 'dresscode/constructSpacing',
			'SlevomatCodingStandard.Functions.NamedArgumentSpacing' => 'dresscode/namedArgumentSpacing',
			'SlevomatCodingStandard.Functions.RequireArrowFunction' => fn(array $o, Translation $t) => $t->enable('dresscode/arrowFunction', ['nested' => $o['allowNested'] ?? true]),
			'SlevomatCodingStandard.Functions.RequireTrailingCommaInCall' => fn(array $o, Translation $t) => $t->enable('dresscode/trailingComma', ['argument' => 'required']),
			'SlevomatCodingStandard.Functions.RequireTrailingCommaInDeclaration' => fn(array $o, Translation $t) => $t->enable('dresscode/trailingComma', ['parameter' => 'required']),
			'SlevomatCodingStandard.Functions.StaticClosure' => 'dresscode/staticClosure',
			'SlevomatCodingStandard.Functions.StrictCall' => 'dresscode/strictCall',
			'SlevomatCodingStandard.Functions.UselessParameterDefaultValue' => 'dresscode/uselessParameterDefault',
			'SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses' => fn(array $o, Translation $t) => $t->enable('dresscode/orderedImports', [
				'order' => 'byName',
				'caseSensitive' => $o['caseSensitive'] ?? false,
			]),
			'SlevomatCodingStandard.Namespaces.DisallowGroupUse' => fn(array $o, Translation $t) => $t->enable('dresscode/importNotation', ['group' => 'expand']),
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

				$t->enable('dresscode/nameNotation', ['globalConstant' => $shapes]);
				$t->enable('dresscode/nameFallback', ['constant' => $constants]);
			},
			'SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalFunctions' => function (array $o, Translation $t) {
				// the special functions join the include, and only an include left empty names every function
				$special = $o['includeSpecialFunctions'] ?? false;
				$functions = ($o['include'] ?? []) === [] && !$special ? ['*' => 'qualified'] : [];
				// nameNotation has no key for the special functions, so with them the backslash goes for every function
				$shapes = ($o['include'] ?? []) === [] || $special ? ['*' => 'backslash'] : [];
				foreach ($o['include'] ?? [] as $name) {
					$functions[$name] = 'qualified';
					$shapes[$name] = 'backslash';
				}

				foreach ($o['exclude'] ?? [] as $name) {
					$functions[$name] = $shapes[$name] = 'keep';
				}

				$t->enable('dresscode/nameNotation', ['globalFunction' => $shapes]);
				$t->enable('dresscode/nameFallback', array_filter([
					'function' => $functions ?: null,
					'optimizedFunction' => $special ? 'qualified' : null,
				]));
			},
			'SlevomatCodingStandard.Namespaces.MultipleUsesPerLine' => fn(array $o, Translation $t) => $t->enable('dresscode/importNotation', ['group' => 'keep']),
			'SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly' => function (array $o, Translation $t) {
				// a global name written with the backslash may stay so, and a bare one may stand on the fallback, or else it is imported
				$shape = fn(bool $backslash) => ['*' => $backslash ? 'keep' : 'import'];
				$t->enable('dresscode/nameNotation', [
					'class' => 'import',
					'globalClass' => ($o['allowFullyQualifiedGlobalClasses'] ?? false) ? 'keep' : 'import',
					'function' => 'import',
					'globalFunction' => $shape($o['allowFullyQualifiedGlobalFunctions'] ?? false),
					'constant' => 'import',
					'globalConstant' => $shape($o['allowFullyQualifiedGlobalConstants'] ?? false),
				]);
				$fallback = array_filter([
					'function' => ($o['allowFallbackGlobalFunctions'] ?? true) ? null : ['*' => 'qualified'],
					'constant' => ($o['allowFallbackGlobalConstants'] ?? true) ? null : ['*' => 'qualified'],
				]);
				if ($fallback !== []) {
					$t->enable('dresscode/nameFallback', $fallback);
				}
				if (($o['allowPartialUses'] ?? true) === false) {
					$t->warn('`ReferenceUsedNamesOnly` with `allowPartialUses=false` has no equivalent; DressCode lets a partial name stand.');
				}
			},
			'SlevomatCodingStandard.Namespaces.UnusedUses' => fn(array $o, Translation $t) => $t->enable('dresscode/unusedImports', ['annotations' => $o['searchAnnotations'] ?? false]),
			'SlevomatCodingStandard.Namespaces.UseDoesNotStartWithBackslash' => 'dresscode/uselessImportBackslash',
			'SlevomatCodingStandard.Namespaces.UseFromSameNamespace' => 'dresscode/uselessSameNamespaceImport',
			'SlevomatCodingStandard.Namespaces.UselessAlias' => 'dresscode/uselessAlias',
			'SlevomatCodingStandard.Numbers.RequireNumericLiteralSeparator' => fn(array $o, Translation $t) => $t->enable('dresscode/numericLiteralSeparator', [
				'minIntegerDigits' => $o['minDigitsBeforeDecimalPoint'] ?? 4,
				'minFractionDigits' => $o['minDigitsAfterDecimalPoint'] ?? 4,
			]),
			'SlevomatCodingStandard.Operators.NegationOperatorSpacing' => 'dresscode/unaryOperatorSpacing',
			'SlevomatCodingStandard.Operators.ReferenceSpacing' => 'dresscode/referenceSpacing',
			'SlevomatCodingStandard.Operators.RequireCombinedAssignmentOperator' => 'dresscode/combinedAssignmentForRepeatedTarget',
			'SlevomatCodingStandard.Operators.SpreadOperatorSpacing' => 'dresscode/spreadOperatorSpacing',
			'SlevomatCodingStandard.PHP.DisallowDirectMagicInvokeCall' => 'dresscode/noDirectInvokeCalls',
			'SlevomatCodingStandard.PHP.OptimizedFunctionsWithoutUnpacking' => 'dresscode/optimizedCallNotation',
			'SlevomatCodingStandard.PHP.RequireExplicitAssertion' => 'dresscode/explicitAssertion',
			'SlevomatCodingStandard.PHP.RequireNowdoc' => 'dresscode/nowdocWithoutInterpolation',
			'SlevomatCodingStandard.PHP.ShortList' => 'dresscode/shortArraySyntax',
			'SlevomatCodingStandard.PHP.TypeCast' => 'dresscode/castCanonicalType',
			'SlevomatCodingStandard.PHP.UselessSemicolon' => 'dresscode/noEmptyStatements',
			'SlevomatCodingStandard.TypeHints.DeclareStrictTypes' => function (array $o, Translation $t) {
				if (($o['spacesCountAroundEqualsSign'] ?? 1) !== 0) {
					$t->warn('`DeclareStrictTypes` with spaces around the equals sign has no equivalent; DressCode writes `declare(strict_types=1)` without spaces.');
				}
				$t->enable('dresscode/strictTypesRequired', ['placement' => ($o['declareOnFirstLine'] ?? false) ? 'openingTagLine' : 'ownLine']);
			},
			'SlevomatCodingStandard.TypeHints.DisallowArrayTypeHintSyntax' => fn(array $o, Translation $t) => $t->enable('dresscode/phpdocCanonicalTypes', ['arrayNotation' => 'generic']),
			'SlevomatCodingStandard.TypeHints.DNFTypeHintFormat' => 'dresscode/unionTypeNotation',
			'SlevomatCodingStandard.TypeHints.LongTypeHints' => 'dresscode/phpdocCanonicalTypes',
			'SlevomatCodingStandard.TypeHints.NullTypeHintOnLastPosition' => 'dresscode/phpdocNullPosition',
			'SlevomatCodingStandard.TypeHints.NullableTypeForNullDefaultValue' => 'dresscode/nullableTypeForDefaultNull',
			'SlevomatCodingStandard.TypeHints.ParameterTypeHint' => fn(array $o, Translation $t) => $t->enable('dresscode/typeHintRequired', array_filter([
				'parameter' => true,
				'property' => false,
				'return' => false,
				'traversableTypeHints' => $o['traversableTypeHints'] ?? null,
			], fn($v) => $v !== null)),
			'SlevomatCodingStandard.TypeHints.ParameterTypeHintSpacing' => 'dresscode/typeHintSpacing',
			'SlevomatCodingStandard.TypeHints.PropertyTypeHint' => fn(array $o, Translation $t) => $t->enable('dresscode/typeHintRequired', array_filter([
				'parameter' => false,
				'property' => true,
				'return' => false,
				'traversableTypeHints' => $o['traversableTypeHints'] ?? null,
			], fn($v) => $v !== null)),
			'SlevomatCodingStandard.TypeHints.PropertyTypeHintSpacing' => 'dresscode/typeHintSpacing',
			'SlevomatCodingStandard.TypeHints.ReturnTypeHint' => fn(array $o, Translation $t) => $t->enable('dresscode/typeHintRequired', array_filter([
				'parameter' => false,
				'property' => false,
				'return' => true,
				'traversableTypeHints' => $o['traversableTypeHints'] ?? null,
			], fn($v) => $v !== null)),
			'SlevomatCodingStandard.TypeHints.ReturnTypeHintSpacing' => 'dresscode/typeHintSpacing',
			'SlevomatCodingStandard.TypeHints.UnionTypeHintFormat' => fn(array $o, Translation $t) => $t->enable('dresscode/unionTypeNotation', array_filter([
				'shortNullable' => isset($o['shortNullable']) ? $o['shortNullable'] === 'yes' : null,
				'nullPosition' => $o['nullPosition'] ?? null,
			], fn($v) => $v !== null)),
			'SlevomatCodingStandard.TypeHints.UselessConstantTypeHint' => 'dresscode/uselessConstantVarAnnotation',
			'SlevomatCodingStandard.Variables.DuplicateAssignmentToVariable' => 'dresscode/noDuplicateAssignments',
			'Squiz.Arrays.ArrayBracketSpacing' => 'dresscode/offsetBracketSpacing',
			'Squiz.Classes.SelfMemberReference' => 'dresscode/selfForCurrentClass',
			'Squiz.Classes.ValidClassName' => fn(array $o, Translation $t) => $t->enable('dresscode/nameCasing', ['class' => 'PascalCase']),
			'Squiz.Commenting.DocCommentAlignment' => 'dresscode/phpdocAlignment',
			'Squiz.Commenting.FunctionComment.DuplicateReturn' => 'dresscode/noDuplicateReturnAnnotations',
			'Squiz.Commenting.FunctionComment.ExtraParamComment' => 'dresscode/noUnknownParamAnnotations',
			'Squiz.Commenting.VariableComment' => 'dresscode/propertyPhpdocRequired',
			'Squiz.ControlStructures.ControlSignature' => 'dresscode/bracesPosition',
			'Squiz.ControlStructures.ForEachLoopDeclaration' => function (array $o, Translation $t) {
				if (($o['requiredSpacesAfterOpen'] ?? 0) > 0 || ($o['requiredSpacesBeforeClose'] ?? 0) > 0) {
					$t->warn('`ForEachLoopDeclaration` with spaces inside the parentheses has no equivalent; DressCode writes none.');
				}
				$t->enable('dresscode/parenthesesSpacing')
					->enable('dresscode/constructSpacing')
					->enable('dresscode/binaryOperatorSpacing')
					->enable('dresscode/keywordCasing');
			},
			'Squiz.ControlStructures.ForLoopDeclaration' => function (array $o, Translation $t) {
				if (($o['requiredSpacesAfterOpen'] ?? 0) > 0 || ($o['requiredSpacesBeforeClose'] ?? 0) > 0) {
					$t->warn('`ForLoopDeclaration` with spaces inside the parentheses has no equivalent; DressCode writes none.');
				}
				$t->enable('dresscode/parenthesesSpacing')->enable('dresscode/semicolonSpacing');
			},
			'Squiz.ControlStructures.LowercaseDeclaration' => 'dresscode/keywordCasing',
			'Squiz.Functions.FunctionDeclaration' => fn(array $o, Translation $t) => $t
				->enable('dresscode/constructSpacing')
				->enable('dresscode/functionNameSpacing')
				->enable('dresscode/semicolonSpacing'),
			'Squiz.Functions.FunctionDeclarationArgumentSpacing' => 'dresscode/commaSpacing',
			'Squiz.Functions.LowercaseFunctionKeywords' => 'dresscode/keywordCasing',
			'Squiz.Functions.MultiLineFunctionDeclaration' => 'dresscode/bracesPosition',
			'Squiz.NamingConventions.ValidFunctionName' => fn(array $o, Translation $t) => $t->enable('dresscode/nameCasing', ['method' => 'camelCase', 'function' => 'camelCase']),
			'Squiz.NamingConventions.ValidVariableName' => function (array $o, Translation $t) {
				$t->warn('`ValidVariableName` also rules on the underscore prefix of private properties, which DressCode does not.');
				$t->enable('dresscode/nameCasing', ['property' => 'camelCase', 'variable' => 'camelCase']);
			},
			'Squiz.Operators.ValidLogicalOperators' => 'dresscode/symbolicLogicalOperators',
			'Squiz.PHP.DiscouragedFunctions' => fn(array $o, Translation $t) => $t->enable('dresscode/forbiddenFunctions', ['error_log' => null, 'print_r' => null, 'var_dump' => null]),
			'Squiz.PHP.GlobalKeyword' => 'dresscode/noGlobalStatements',
			'Squiz.PHP.InnerFunctions' => 'dresscode/noInnerFunctions',
			'Squiz.PHP.LowercasePHPFunctions' => 'dresscode/nativeFunctionCasing',
			'Squiz.Scope.MethodScope' => 'dresscode/visibilityRequired',
			'Squiz.Scope.StaticThisUsage' => 'dresscode/noStaticThis',
			'Squiz.Strings.ConcatenationSpacing' => fn(array $o, Translation $t) => $t->enable('dresscode/concatSpacing', ['spacing' => ($o['spacing'] ?? 0) > 0 ? 'single' : 'none']),
			'Squiz.Strings.DoubleQuoteUsage' => fn(array $o, Translation $t) => $t->enable('dresscode/stringQuotes', ['quotes' => 'single']),
			'Squiz.Strings.EchoedStrings' => 'dresscode/uselessConstructParentheses',
			'Squiz.WhiteSpace.CastSpacing' => 'dresscode/castCanonicalType',
			'Squiz.WhiteSpace.ControlStructureSpacing' => fn(array $o, Translation $t) => $t->enable('dresscode/blankLines', ['beforeBlockClosingBrace' => 0]),
			'Squiz.WhiteSpace.FunctionOpeningBraceSpace' => fn(array $o, Translation $t) => $t->enable('dresscode/blankLines', ['afterBlockOpeningBrace' => 0]),
			'Squiz.WhiteSpace.FunctionSpacing' => function (array $o, Translation $t) {
				$t->warn('`FunctionSpacing` leaves the blank lines around classes alone, which DressCode counts with the spacing of functions.');
				$t->enable('dresscode/blankLines', [
					'betweenDeclarations' => $o['spacing'] ?? 2,
					'betweenMethods' => $o['spacing'] ?? 2,
					'beforeFirstMethod' => $o['spacingBeforeFirst'] ?? 2,
					'afterLastMethod' => $o['spacingAfterLast'] ?? 2,
				]);
			},
			'Squiz.WhiteSpace.LogicalOperatorSpacing' => 'dresscode/binaryOperatorSpacing',
			'Squiz.WhiteSpace.ObjectOperatorSpacing' => 'dresscode/objectOperatorSpacing',
			'Squiz.WhiteSpace.OperatorSpacing' => 'dresscode/binaryOperatorSpacing',
			'Squiz.WhiteSpace.OperatorSpacing.Unary' => 'dresscode/unaryOperatorSpacing',
			'Squiz.WhiteSpace.ScopeClosingBrace' => fn(array $o, Translation $t) => $t->enable('dresscode/bracesPosition')->enable('dresscode/indentation'),
			'Squiz.WhiteSpace.ScopeKeywordSpacing' => 'dresscode/doubleColonSpacing',
			'Squiz.WhiteSpace.SemicolonSpacing' => 'dresscode/semicolonSpacing',
			'Squiz.WhiteSpace.SuperfluousWhitespace' => 'dresscode/noTrailingWhitespace',
		];
	}


	/**
	 * Rules of a phpcs.xml ruleset: every <rule ref> with the properties it sets and every <exclude> inside it as
	 * a rule switched off, and what the ruleset says that no rule carries. A value is typed by its text, true, false
	 * and digits, because XML carries every value as a string.
	 * @return array{array<string, bool|array<string, mixed>>, list<string>}
	 * @throws ConfigurationException
	 */
	public static function readConfig(string $file): array
	{
		$xml = is_file($file) ? @simplexml_load_file($file) : false; // @ - reported as exception
		if ($xml === false) {
			throw new ConfigurationException("File `$file` is not a readable phpcs ruleset.");
		}

		$rules = $warnings = [];
		if (isset($xml->{'exclude-pattern'})) {
			$warnings[] = 'The paths the ruleset excludes are not carried over; set them with the key `excludePaths`.';
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
				$warnings[] = "The paths excluded from `$ref` are not carried over; turn its rules off there with an override.";
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
