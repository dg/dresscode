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
	 * @return array<string, array<string, mixed>|\Closure(array<string, mixed>, Translation): mixed>  sniff => its decisions, or what to
	 *     set for its properties; every property is read through `??` so that a sniff with none translates too
	 */
	public static function getTranslations(): array
	{
		// the blank lines before a property or a constant, `blankLines` counting them for every member together
		$memberSpacing = fn(array $o, Translation $t) => $t
			->setBlankLines('blankLines.betweenMembers', $o['minLinesCountBeforeWithoutComment'] ?? 0, $o['maxLinesCountBeforeWithoutComment'] ?? 1)
			->setBlankLines('blankLines.beforeDocumentedMember', $o['minLinesCountBeforeWithComment'] ?? 1, $o['maxLinesCountBeforeWithComment'] ?? 1);

		// the nullable and the place of null in a type, which the sniffs check only where their properties name them
		$typeFormat = fn(string $sniff) => function (array $o, Translation $t) use ($sniff) {
			if (!isset($o['shortNullable']) && !isset($o['nullPosition'])) {
				$t->warn("`$sniff` without `shortNullable` or `nullPosition` checks nothing DressCode decides.");
			}

			$t->setAll(array_filter([
				'types.nullable' => isset($o['shortNullable']) ? ($o['shortNullable'] === 'yes' ? 'questionMark' : 'keep') : null,
				'types.nullPosition' => $o['nullPosition'] ?? null,
			], fn($v) => $v !== null));
		};

		return [
			'Generic.Arrays.DisallowLongArraySyntax' => ['literals.longArraySyntax' => 'forbidden'],
			'Generic.CodeAnalysis.RequireExplicitBooleanOperatorPrecedence' => ['expressions.explicitPrecedence' => 'required'],
			'Generic.CodeAnalysis.UnnecessaryFinalModifier' => ['classes.impliedModifiers' => 'forbidden'],
			'Generic.ControlStructures.InlineControlStructure' => ['braces.bracelessBody' => 'forbidden'],
			'Generic.Files.ByteOrderMark' => ['file.bom' => 'forbidden'],
			'Generic.Files.LineEndings' => ['file.lineEnding' => 'majority'],
			'Generic.Files.LineLength' => fn(array $o, Translation $t) => $t->setLineLength(($o['absoluteLineLimit'] ?? 100) ?: ($o['lineLimit'] ?? 80))->setAll(['file.longLines' => 'forbidden', 'file.longLinesExcept' => ['imports']]),
			'Generic.Formatting.DisallowMultipleStatements' => ['file.statementsPerLine' => 1],
			'Generic.Functions.FunctionCallArgumentSpacing' => ['spacing.comma' => 'spaced', 'spacing.commaAlignment' => 'tabs'],
			'Generic.Functions.FunctionCallArgumentSpacing.SpaceBeforeOpenBracket' => ['spacing.call' => 'compact'],
			'Generic.NamingConventions.CamelCapsFunctionName' => fn(array $o, Translation $t) => $t->setAll(['naming.method' => 'camelCase', 'naming.function' => 'camelCase']),
			'Generic.NamingConventions.UpperCaseConstantName' => function (array $o, Translation $t) {
				$t->warn('`Generic.NamingConventions.UpperCaseConstantName` also checks `define()`, which DressCode does not.');
				$t->set('naming.constant', 'UPPER_CASE');
			},
			'Generic.PHP.DeprecatedFunctions' => ['upgrading.php.deprecatedCall' => 'forbidden'],
			'Generic.PHP.DisallowShortOpenTag' => ['file.openingTag' => 'full'],
			'Generic.PHP.ForbiddenFunctions' => fn(array $o, Translation $t) => $t->set(
				'upgrading.libraries.forbiddenFunctions',
				// the sniff reads the value `null` of a ruleset as no replacement
				array_map(fn($v) => $v === null || $v === 'null' ? null : "use `$v()`", $o['forbiddenFunctions'] ?? ['sizeof' => 'count', 'delete' => 'unset']),
			),
			'Generic.PHP.LowerCaseConstant' => ['builtin.trueFalseNull' => 'lowercase'],
			'Generic.PHP.LowerCaseKeyword' => ['builtin.keyword' => 'lowercase'],
			// `array`, `self` and the like are keywords of a declaration
			'Generic.PHP.LowerCaseType' => ['builtin.castType' => 'short', 'builtin.type' => 'lowercase', 'builtin.keyword' => 'lowercase'],
			'Generic.PHP.RequireStrictTypes' => ['file.strictTypes' => 'required', 'file.strictTypesPosition' => 'ownLine'],
			'Generic.PHP.SAPIUsage' => fn(array $o, Translation $t) => $t->set('upgrading.libraries.forbiddenFunctions', ['php_sapi_name' => 'use `PHP_SAPI`']),
			'Generic.Strings.UnnecessaryStringConcat' => fn(array $o, Translation $t) => $t->setAll([
				'literals.concatenatedLiterals' => 'joined',
				'literals.concatenatedLiteralsOverLines' => ($o['allowMultiline'] ?? false) ? 'keep' : 'joined',
			]),
			'Generic.WhiteSpace.DisallowSpaceIndent' => ['indentation.unit' => 'tab'],
			// spaces of a width the sniff does not say, four unless a sniff naming the width decides it, in either order
			'Generic.WhiteSpace.DisallowTabIndent' => fn(array $o, Translation $t) => $t->prefer('indentation.unit', '4 spaces'),
			'Generic.WhiteSpace.IncrementDecrementSpacing' => ['spacing.unaryOperator' => 'compact', 'spacing.unaryOperatorsWithSpace' => []],
			'Generic.WhiteSpace.LanguageConstructSpacing' => ['spacing.languageConstruct' => 'spaced'],
			'Generic.WhiteSpace.ScopeIndent' => function (array $o, Translation $t) {
				$unit = ($o['tabIndent'] ?? false) ? 'tab' : match ($o['indent'] ?? 4) {
					4 => '4 spaces',
					2 => '2 spaces',
					default => null,
				};
				if ($unit === null) {
					$t->warn("`Generic.WhiteSpace.ScopeIndent` with `indent={$o['indent']}` has no equivalent; DressCode indents by a tab, four spaces or two.");
				} else {
					$t->set('indentation.unit', $unit);
				}
			},
			'PEAR.Commenting.InlineComment' => ['comments.singleline' => 'slashes'],
			'PEAR.Functions.ValidDefaultValue' => ['functions.uselessParameterDefault' => 'forbidden'],
			'PEAR.WhiteSpace.ObjectOperatorIndent' => function (array $o, Translation $t) {
				if ($o['multilevel'] ?? false) {
					$t->warn('`PEAR.WhiteSpace.ObjectOperatorIndent` with `multilevel=true` has no equivalent; DressCode indents every operator of a chain one level deeper than its start.');
				}
				$t->set('indentation.chain', 'flat');
			},
			'PSR1.Methods.CamelCapsMethodName' => fn(array $o, Translation $t) => $t->set('naming.method', 'camelCase'),
			'PSR12.Classes.AnonClassDeclaration' => function (array $o, Translation $t) {
				$t->warn('`PSR12.Classes.AnonClassDeclaration` also puts each interface of a multi-line `implements` list on its own line, which DressCode does not.');
				$t->setAll([
					'braces.anonymousClass' => 'sameLine',
					'spacing.classHead' => 'spaced',
					'spacing.anonymousClass' => 'spaced',
					'spacing.parentheses' => 'compact',
				]);
			},
			'PSR12.Classes.ClassInstantiation' => ['classes.newParentheses' => 'required'],
			'PSR12.Classes.ClosingBrace' => function (array $o, Translation $t) {
				$t->warn('`PSR12.Classes.ClosingBrace` also forbids a comment after the closing brace of a class or a function, which DressCode does not.');
				$t->setAll(['classes.membersPerLine' => 1, 'file.statementsPerLine' => 1]);
			},
			'PSR12.Classes.OpeningBraceSpace' => fn(array $o, Translation $t) => $t->setAll(['blankLines.beforeFirstMember' => 0, 'blankLines.beforeFirstMethod' => 0]),
			'PSR12.ControlStructures.BooleanOperatorPlacement' => function (array $o, Translation $t) {
				$only = $o['allowOnly'] ?? null;
				if ($only === 'first') {
					$t->set('multiline.operatorPosition.condition', 'lineStart');
				} elseif ($only === 'last') {
					$t->warn('`PSR12.ControlStructures.BooleanOperatorPlacement` with `allowOnly=last` has no equivalent; DressCode moves a boolean operator only to the start of a line.');
				} else {
					$t->warn('`PSR12.ControlStructures.BooleanOperatorPlacement` forbids a condition mixing boolean operators at the start and at the end of its lines, which DressCode does not check.');
					$t->keep('multiline.operatorPosition.condition');
				}
			},
			'PSR12.ControlStructures.ControlStructureSpacing' => function (array $o, Translation $t) {
				$t->warn('`PSR12.ControlStructures.ControlStructureSpacing` also puts the parentheses of a multi-line `for`, `foreach`, `switch`, `catch` and `match` on lines of their own, which DressCode does not.');
				$t->setAll(['spacing.parentheses' => 'compact', 'multiline.condition' => 'perLine']);
			},
			'PSR12.Files.DeclareStatement' => function (array $o, Translation $t) {
				$t->warn('`PSR12.Files.DeclareStatement` also checks the case of the directive of a `declare`, which DressCode does not.');
				$t->setAll(['spacing.declare' => 'compact', 'braces.controlStructure' => 'sameLine']);
			},
			'PSR12.Files.FileHeader' => [
				'blankLines.afterOpeningTag' => 1,
				'blankLines.beforeNamespace' => 1,
				'blankLines.afterNamespace' => 1,
				'blankLines.afterImports' => 1,
				'blankLines.betweenImportKinds' => 1,
			],
			'PSR12.Files.ImportStatement' => fn(array $o, Translation $t) => $t->set('qualification.uselessBackslash', 'forbidden'),
			'PSR12.Files.OpenTag' => function (array $o, Translation $t) {
				$t->warn('`PSR12.Files.OpenTag` leaves the line below the opening tag as it is, where DressCode puts a blank line.');
				$t->set('blankLines.afterOpeningTag', 1);
			},
			'PSR12.Functions.NullableTypeDeclaration' => ['spacing.typeDeclaration' => 'compact'],
			'PSR12.Functions.ReturnTypeDeclaration' => ['spacing.typeDeclaration' => 'compact'],
			'PSR12.Keywords.ShortFormTypeKeywords' => ['builtin.castType' => 'short'],
			'PSR12.Operators.OperatorSpacing' => fn(array $o, Translation $t) => $t->setAll([
				'spacing.binaryOperator' => 'spaced',
				'spacing.binaryOperatorAlignment' => 'spaces',
				'spacing.ternary' => 'spaced',
				'spacing.ternaryAlignment' => 'any',
				'spacing.concatenation' => 'spaced',
			]),
			'PSR12.Properties.ConstantVisibility' => [
				'classes.memberVisibility' => 'required',
			],
			'PSR12.Traits.UseDeclaration' => function (array $o, Translation $t) {
				$t->warn('`PSR12.Traits.UseDeclaration` also lays out the conflict block of a trait use, its opening brace on the line of `use` and a rule per line, which DressCode does not.');
				$t->setAll([
					'blankLines.beforeFirstMember' => 0,
					'blankLines.betweenTraitUses' => 0,
					'blankLines.afterTraitUses' => 1,
					'blankLines.afterLastMember' => 0,
					// the space after `use`, around `as` and `insteadof`, and none before the semicolon
					'spacing.languageConstruct' => 'spaced',
					'spacing.connectingKeyword' => 'spaced',
					'spacing.beforeSemicolon' => 'compact',
				]);
				$t->setAllowed('classes.groupedDeclarationAllowedFor', ['constant', 'property']);
				// a full order another rule sets puts the trait uses where it says
				$t->prefer('classes.memberOrder', ['traitUse']);
			},
			'PSR2.Classes.ClassDeclaration' => ['braces.class' => 'nextLine', 'spacing.classHead' => 'spaced'],
			'PSR2.Classes.ClassDeclaration.SpaceBeforeKeyword' => ['spacing.classHead' => 'spaced'],
			'PSR2.Classes.PropertyDeclaration' => [
				'classes.memberVisibility' => 'required',
				'classes.modifierOrder' => 'canonical',
			],
			'PSR2.ControlStructures.ControlStructureSpacing' => ['spacing.parentheses' => 'compact'],
			'PSR2.ControlStructures.ElseIfDeclaration' => ['controlFlow.elseif' => 'oneWord'],
			'PSR2.ControlStructures.SwitchDeclaration' => function (array $o, Translation $t) {
				$t->warn('`PSR2.ControlStructures.SwitchDeclaration` also starts the body of a case on the line below it and forbids braces around it, which DressCode does not.');
				$t->setAll([
					'controlFlow.switchCaseTerminator' => 'colon',
					'spacing.switchCase' => 'compact',
					'controlFlow.switchFallThrough' => 'no break',
					'builtin.keyword' => 'lowercase',
					'spacing.languageConstruct' => 'spaced',
					'file.statementsPerLine' => 1,
					'indentation.switchCase' => 1,
				]);
			},
			'PSR2.ControlStructures.SwitchDeclaration.SpaceBeforeColonCASE' => ['spacing.switchCase' => 'compact'],
			'PSR2.Files.ClosingTag' => ['file.closingTagAtEnd' => 'forbidden'],
			'PSR2.Files.EndFileNewline' => ['file.finalLineEndings' => 1],
			'PSR2.Methods.FunctionCallSignature' => function (array $o, Translation $t) {
				if (($o['requiredSpacesAfterOpen'] ?? 0) > 0 || ($o['requiredSpacesBeforeClose'] ?? 0) > 0) {
					$t->warn('`PSR2.Methods.FunctionCallSignature` with spaces inside the parentheses has no equivalent; DressCode writes none.');
				}
				$t->setAll([
					'spacing.call' => 'compact',
					'spacing.parentheses' => 'compact',
					'multiline.call' => ($o['allowMultipleArguments'] ?? false) ? 'frame' : 'perLine',
				]);
			},
			'PSR2.Methods.FunctionClosingBrace' => fn(array $o, Translation $t) => $t->set('blankLines.beforeBlockClosingBrace', 0),
			'PSR2.Methods.MethodDeclaration' => function (array $o, Translation $t) {
				$t->warn('`PSR2.Methods.MethodDeclaration` also rules on the underscore prefix of method names, which DressCode does not.');
				$t->set('classes.modifierOrder', 'canonical');
			},
			'PSR2.Namespaces.NamespaceDeclaration' => ['blankLines.afterNamespace' => 1],
			'PSR2.Namespaces.UseDeclaration' => [
				'blankLines.afterImports' => 1,
				'imports.class' => 'separate',
				'imports.function' => 'separate',
				'imports.constant' => 'separate',
				'imports.groupUse' => 'forbidden',
			],
			'SlevomatCodingStandard.Arrays.MultiLineArrayEndBracketPlacement' => fn(array $o, Translation $t) => $t->warn('`SlevomatCodingStandard.Arrays.MultiLineArrayEndBracketPlacement` puts the closing bracket of a multi-line array where its opening bracket stands, which DressCode decides only together with its items, by `multiline.array`.'),
			'SlevomatCodingStandard.Arrays.SingleLineArrayWhitespace' => ['spacing.arrayBrackets' => 'compact'],
			'SlevomatCodingStandard.Arrays.TrailingArrayComma' => fn(array $o, Translation $t) => $t->set('multiline.trailingComma.array', 'required'),
			'SlevomatCodingStandard.Attributes.AttributeAndTargetSpacing' => fn(array $o, Translation $t) => $t->set('blankLines.afterPhpdoc', $o['linesCountBetweenAttributeAndTarget'] ?? 0),
			'SlevomatCodingStandard.Attributes.DisallowMultipleAttributesPerLine' => [
				'multiline.attributes' => 'ownLines',
				'multiline.parameterAttributes' => 'ownLines',
			],
			'SlevomatCodingStandard.Attributes.RequireAttributeAfterDocComment' => ['phpdoc.aboveAttributes' => 'required'],
			'SlevomatCodingStandard.Classes.BackedEnumTypeSpacing' => [
				'spacing.typeDeclaration' => 'compact',
			],
			'SlevomatCodingStandard.Classes.ClassConstantVisibility' => [
				'classes.memberVisibility' => 'required',
			],
			'SlevomatCodingStandard.Classes.ConstantSpacing' => $memberSpacing,
			'SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition' => fn(array $o, Translation $t) => $t->setAllowed('classes.groupedDeclarationAllowedFor', ['property', 'traitUse']),
			'SlevomatCodingStandard.Classes.DisallowMultiPropertyDefinition' => fn(array $o, Translation $t) => $t->setAllowed('classes.groupedDeclarationAllowedFor', ['constant', 'traitUse']),
			'SlevomatCodingStandard.Classes.EmptyLinesAroundClassBraces' => fn(array $o, Translation $t) => $t->setAll([
				'blankLines.beforeFirstMember' => $o['linesCountAfterOpeningBrace'] ?? 1,
				'blankLines.beforeFirstMethod' => $o['linesCountAfterOpeningBrace'] ?? 1,
				'blankLines.afterLastMember' => $o['linesCountBeforeClosingBrace'] ?? 1,
				'blankLines.afterLastMethod' => $o['linesCountBeforeClosingBrace'] ?? 1,
			]),
			'SlevomatCodingStandard.Classes.MethodSpacing' => fn(array $o, Translation $t) => $t
				->setBlankLines('blankLines.betweenMethods', $o['minLinesCount'] ?? 1, $o['maxLinesCount'] ?? 1)
				->setBlankLines('blankLines.betweenInterfaceMethods', $o['minLinesCount'] ?? 1, $o['maxLinesCount'] ?? 1),
			'SlevomatCodingStandard.Classes.ModernClassNameReference' => fn(array $o, Translation $t) => $t->setAll([
				'cleanup.classNameNotation' => 'classKeyword',
				'cleanup.get_class' => ($o['enableOnObjects'] ?? false) ? 'forbidden' : 'keep',
			]),
			'SlevomatCodingStandard.Classes.PropertyDeclaration' => [
				'classes.modifierOrder' => 'canonical',
			],
			'SlevomatCodingStandard.Classes.PropertySpacing' => $memberSpacing,
			'SlevomatCodingStandard.Classes.RequireMultiLineMethodSignature' => function (array $o, Translation $t) {
				if (($min = $o['minLineLength'] ?? 121) > 1) {
					$t->setLineLength($min - 1);
				} else {
					$t->warn("`SlevomatCodingStandard.Classes.RequireMultiLineMethodSignature` with `minLineLength=$min` has no equivalent; DressCode spreads over lines only a signature longer than the line.");
				}

				$t->setAll([
					'multiline.signatureOverMaxLength' => 'split',
					'multiline.signatureWithPromotedProperties' => ($o['withPromotedProperties'] ?? false) ? 'split' : 'asSignature',
					'multiline.signature' => 'perLine',
				]);
			},
			'SlevomatCodingStandard.Classes.SuperfluousAbstractClassNaming' => fn(array $o, Translation $t) => $t->set('naming.classKindInName', 'forbidden'),
			'SlevomatCodingStandard.Classes.SuperfluousErrorNaming' => fn(array $o, Translation $t) => $t->set('naming.classKindInName', 'forbidden'),
			'SlevomatCodingStandard.Classes.SuperfluousInterfaceNaming' => fn(array $o, Translation $t) => $t->set('naming.classKindInName', 'forbidden'),
			'SlevomatCodingStandard.Classes.SuperfluousTraitNaming' => fn(array $o, Translation $t) => $t->set('naming.classKindInName', 'forbidden'),
			'SlevomatCodingStandard.Classes.TraitUseDeclaration' => fn(array $o, Translation $t) => $t->setAllowed('classes.groupedDeclarationAllowedFor', ['constant', 'property']),
			'SlevomatCodingStandard.Classes.TraitUseSpacing' => fn(array $o, Translation $t) => $t->setAll([
				'blankLines.betweenTraitUses' => $o['linesCountBetweenUses'] ?? 0,
				'blankLines.afterTraitUses' => $o['linesCountAfterLastUse'] ?? 1,
			]),
			'SlevomatCodingStandard.Classes.UselessLateStaticBinding' => fn(array $o, Translation $t) => $t->setAll(['qualification.currentClass' => 'self', 'qualification.staticInFinalClass' => 'self']),
			'SlevomatCodingStandard.Commenting.AnnotationName' => ['phpdoc.annotations' => 'canonicalCase'],
			'SlevomatCodingStandard.Commenting.ForbiddenAnnotations' => fn(array $o, Translation $t) => $t->set('phpdoc.forbiddenAnnotations', $o['forbiddenAnnotations'] ?? []),
			'SlevomatCodingStandard.Commenting.ForbiddenComments' => fn(array $o, Translation $t) => $t->set('phpdoc.forbiddenLines', $o['forbiddenCommentPatterns'] ?? []),
			'SlevomatCodingStandard.Commenting.RequireOneLinePropertyDocComment' => ['phpdoc.singlelineProperty' => 'singleline'],
			'SlevomatCodingStandard.Commenting.UselessFunctionDocComment' => fn(array $o, Translation $t) => $t->set('phpdoc.repeatingNativeTypes', 'forbidden'),
			'SlevomatCodingStandard.Commenting.UselessInheritDocComment' => ['phpdoc.inheritdocOnly' => 'forbidden'],
			'SlevomatCodingStandard.ControlStructures.BlockControlStructureSpacing' => function (array $o, Translation $t) {
				$supported = ['if', 'do', 'while', 'for', 'foreach', 'switch', 'try'];
				$unsupported = array_diff($o['controlStructures'] ?? [], $supported);
				if ($unsupported) {
					$t->warn('`SlevomatCodingStandard.ControlStructures.BlockControlStructureSpacing` with `' . implode('`, `', $unsupported) . '` has no equivalent; DressCode counts the blank lines before a `case` in `blankLines.betweenCases`.');
				}

				$statements = array_values(array_intersect($o['controlStructures'] ?? $supported, $supported));
				if ($statements) {
					$t->setAll([
						'blankLines.beforeStatement' => array_fill_keys($statements, $o['linesCountBefore'] ?? 1),
						'blankLines.afterStatement' => array_fill_keys($statements, $o['linesCountAfter'] ?? 1),
					]);
				}
			},
			'SlevomatCodingStandard.ControlStructures.DisallowContinueWithoutIntegerOperandInSwitch' => ['correctness.continueInSwitch' => 'forbidden'],
			'SlevomatCodingStandard.ControlStructures.DisallowYodaComparison' => fn(array $o, Translation $t) => $t->set('expressions.yoda', 'forbidden'),
			'SlevomatCodingStandard.ControlStructures.EarlyExit' => function (array $o, Translation $t) {
				$t->setAll([
					'controlFlow.trailingIf' => 'forbidden',
					'controlFlow.trailingIfMinStatements' => ($o['ignoreTrailingIfWithOneInstruction'] ?? false) ? 2 : 1,
					'controlFlow.elseAfterExit' => 'forbidden',
					'controlFlow.elseifAfterExit' => 'forbidden',
				]);
			},
			'SlevomatCodingStandard.ControlStructures.JumpStatementsSpacing' => fn(array $o, Translation $t) => $t->setAll([
				'blankLines.beforeStatement' => array_fill_keys(array_intersect($o['jumpStatements'] ?? ['break', 'continue', 'return', 'throw', 'yield'], ['break', 'continue', 'return', 'throw', 'yield']), $o['linesCountBefore'] ?? 1),
				'blankLines.afterStatement' => array_fill_keys(array_intersect($o['jumpStatements'] ?? ['break', 'continue', 'return', 'throw', 'yield'], ['break', 'continue', 'return', 'throw', 'yield']), $o['linesCountAfter'] ?? 1),
			]),
			'SlevomatCodingStandard.ControlStructures.LanguageConstructWithParentheses' => ['expressions.parenthesesAfterConstruct' => 'forbidden'],
			'SlevomatCodingStandard.ControlStructures.NewWithoutParentheses' => ['classes.newParentheses' => 'forbidden'],
			'SlevomatCodingStandard.ControlStructures.RequireMultiLineCondition' => function (array $o, Translation $t) {
				if (($min = $o['minLineLength'] ?? 121) > 1) {
					$t->setLineLength($min - 1);
				} else {
					$t->warn("`SlevomatCodingStandard.ControlStructures.RequireMultiLineCondition` with `minLineLength=$min` has no equivalent; DressCode spreads over lines only a condition longer than the line.");
				}

				$t->set('multiline.condition', ($o['alwaysSplitAllConditionParts'] ?? false) ? 'perLine' : 'compact');
			},
			'SlevomatCodingStandard.ControlStructures.RequireNullCoalesceEqualOperator' => ['expressions.assignmentRepeatingTarget' => 'forbidden'],
			'SlevomatCodingStandard.ControlStructures.RequireNullCoalesceOperator' => ['expressions.ternaryTestingNull' => 'forbidden'],
			'SlevomatCodingStandard.ControlStructures.RequireShortTernaryOperator' => ['expressions.ternaryReturningItsCondition' => 'forbidden'],
			'SlevomatCodingStandard.ControlStructures.RequireTernaryOperator' => ['controlFlow.ifReturningOneOfTwoValues' => 'forbidden'],
			'SlevomatCodingStandard.ControlStructures.UselessIfConditionWithReturn' => ['controlFlow.ifReturningBoolean' => 'forbidden'],
			'SlevomatCodingStandard.ControlStructures.UselessTernaryOperator' => ['expressions.ternaryOfTrueAndFalse' => 'forbidden'],
			'SlevomatCodingStandard.Exceptions.DeadCatch' => ['correctness.unreachableCatch' => 'forbidden'],
			'SlevomatCodingStandard.Exceptions.ReferenceThrowableOnly' => ['correctness.exceptionWhereThrowableBelongs' => 'forbidden'],
			'SlevomatCodingStandard.Exceptions.RequireNonCapturingCatch' => ['cleanup.catchWithoutVariable' => 'required'],
			'SlevomatCodingStandard.Files.LineLength' => fn(array $o, Translation $t) => $t->setLineLength($o['lineLengthLimit'] ?? 120)->setAll([
				'file.longLines' => 'forbidden',
				'file.longLinesExcept' => ($o['ignoreImports'] ?? true) ? ['imports'] : [],
			]),
			'SlevomatCodingStandard.Functions.ArrowFunctionDeclaration' => fn(array $o, Translation $t) => match ($o['spacesCountAfterKeyword'] ?? 1) {
				0 => $t->set('spacing.fnKeyword', 'compact'),
				1 => $t->set('spacing.fnKeyword', 'spaced'),
				default => $t->warn("`SlevomatCodingStandard.Functions.ArrowFunctionDeclaration` with `spacesCountAfterKeyword={$o['spacesCountAfterKeyword']}` has no equivalent; DressCode writes a single space after `fn` or none."),
			},
			'SlevomatCodingStandard.Functions.NamedArgumentSpacing' => ['spacing.namedArgument' => 'spacedAfter'],
			'SlevomatCodingStandard.Functions.RequireArrowFunction' => fn(array $o, Translation $t) => $t->setAll([
				'functions.closureReturningOneExpression' => 'forbidden',
				'functions.closureReturningOneExpressionNested' => $o['allowNested'] ?? true,
			]),
			'SlevomatCodingStandard.Functions.RequireTrailingCommaInCall' => fn(array $o, Translation $t) => $t->set('multiline.trailingComma.argument', 'required'),
			'SlevomatCodingStandard.Functions.RequireTrailingCommaInDeclaration' => fn(array $o, Translation $t) => $t->set('multiline.trailingComma.parameter', 'required'),
			'SlevomatCodingStandard.Functions.StaticClosure' => ['functions.staticClosureWithoutThis' => 'required'],
			'SlevomatCodingStandard.Functions.StrictCall' => ['correctness.strictComparisonArgument' => 'required'],
			'SlevomatCodingStandard.Functions.UselessParameterDefaultValue' => ['functions.uselessParameterDefault' => 'forbidden'],
			'SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses' => fn(array $o, Translation $t) => $t->setAll([
				'imports.order' => 'alphabetical',
				'imports.orderCaseSensitive' => $o['caseSensitive'] ?? false,
			]),
			'SlevomatCodingStandard.Namespaces.DisallowGroupUse' => fn(array $o, Translation $t) => $t->set('imports.groupUse', 'forbidden'),
			'SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalConstants' => function (array $o, Translation $t) {
				$named = [...$o['include'] ?? [], ...$o['exclude'] ?? []];
				if ($named !== []) {
					$t->warn('`SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalConstants` names constants one by one (`' . implode('`, `', $named) . '`), which DressCode decides by group; a constant named follows `qualification.globalConstant`.');
				}

				// only an include left empty names every constant
				if (($o['include'] ?? []) === []) {
					$t->qualifyShape(['globalConstant' => 'backslashed']);
					$t->qualifyFallback(['constant' => 'qualified']);
				}
			},
			'SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalFunctions' => function (array $o, Translation $t) {
				$named = [...$o['include'] ?? [], ...$o['exclude'] ?? []];
				if ($named !== []) {
					$t->warn('`SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalFunctions` names functions one by one (`' . implode('`, `', $named) . '`), which DressCode decides by group; a function named follows `qualification.globalFunction`, or `qualification.optimizedFunction` where the compiler optimizes it.');
				}

				// the special functions join the include, and only an include left empty names every function
				if ($o['includeSpecialFunctions'] ?? false) {
					$t->qualifyShape(['optimizedFunction' => 'backslashed']);
					$t->qualifyFallback(['optimizedFunction' => 'qualified']);
				} elseif (($o['include'] ?? []) === []) {
					$t->qualifyShape(['globalFunction' => 'backslashed']);
					$t->qualifyFallback(['function' => 'qualified']);
				}
			},
			// a comma between the names of a group use is one too
			'SlevomatCodingStandard.Namespaces.MultipleUsesPerLine' => [
				'imports.class' => 'separate',
				'imports.function' => 'separate',
				'imports.constant' => 'separate',
				'imports.groupUse' => 'forbidden',
			],
			'SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly' => function (array $o, Translation $t) {
				// a global name written with the backslash may stay so, and a bare one may stand on the fallback, or else it is imported
				$shape = fn(bool $backslash) => $backslash ? 'keep' : 'imported';
				$t->qualifyShape([
					'class' => 'imported',
					'globalClass' => ($o['allowFullyQualifiedGlobalClasses'] ?? false) ? 'keep' : 'imported',
					'function' => 'imported',
					'globalFunction' => $shape($o['allowFullyQualifiedGlobalFunctions'] ?? false),
					'constant' => 'imported',
					'globalConstant' => $shape($o['allowFullyQualifiedGlobalConstants'] ?? false),
				]);
				$fallback = array_filter([
					'function' => ($o['allowFallbackGlobalFunctions'] ?? true) ? null : 'qualified',
					'constant' => ($o['allowFallbackGlobalConstants'] ?? true) ? null : 'qualified',
				]);
				$t->qualifyFallback($fallback);
				if (($o['allowPartialUses'] ?? true) === false) {
					$t->warn('`SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly` with `allowPartialUses=false` has no equivalent; DressCode lets a partial name stand.');
				}
			},
			'SlevomatCodingStandard.Namespaces.UnusedUses' => fn(array $o, Translation $t) => $t->setAll([
				'imports.unused' => 'forbidden',
				'phpdoc.namesUseImports' => $o['searchAnnotations'] ?? false,
			]),
			'SlevomatCodingStandard.Namespaces.UseDoesNotStartWithBackslash' => fn(array $o, Translation $t) => $t->set('qualification.uselessBackslash', 'forbidden'),
			'SlevomatCodingStandard.Namespaces.UseFromSameNamespace' => ['imports.ofCurrentNamespace' => 'forbidden'],
			'SlevomatCodingStandard.Namespaces.UselessAlias' => ['imports.aliasEqualToName' => 'forbidden'],
			'SlevomatCodingStandard.Numbers.RequireNumericLiteralSeparator' => fn(array $o, Translation $t) => $t->setAll([
				'literals.digitGroupsFrom' => $o['minDigitsBeforeDecimalPoint'] ?? 4,
				'literals.fractionDigitGroupsFrom' => $o['minDigitsAfterDecimalPoint'] ?? 4,
			]),
			'SlevomatCodingStandard.Operators.NegationOperatorSpacing' => [
				'spacing.unaryOperator' => 'compact',
				'spacing.unaryOperatorsWithSpace' => [],
			],
			'SlevomatCodingStandard.Operators.ReferenceSpacing' => ['spacing.reference' => 'compact'],
			'SlevomatCodingStandard.Operators.RequireCombinedAssignmentOperator' => ['expressions.assignmentRepeatingTarget' => 'forbidden'],
			'SlevomatCodingStandard.Operators.SpreadOperatorSpacing' => ['spacing.spread' => 'compact'],
			'SlevomatCodingStandard.PHP.DisallowDirectMagicInvokeCall' => ['cleanup.__invoke' => 'forbidden'],
			'SlevomatCodingStandard.PHP.OptimizedFunctionsWithoutUnpacking' => fn(array $o, Translation $t) => $t->optimizeCalls(),
			'SlevomatCodingStandard.PHP.RequireExplicitAssertion' => ['types.inlineVarAnnotation' => 'forbidden'],
			'SlevomatCodingStandard.PHP.RequireNowdoc' => ['literals.heredocWithoutInterpolation' => 'forbidden'],
			'SlevomatCodingStandard.PHP.ShortList' => ['literals.longArraySyntax' => 'forbidden'],
			'SlevomatCodingStandard.PHP.TypeCast' => ['builtin.castType' => 'short'],
			'SlevomatCodingStandard.PHP.UselessSemicolon' => ['controlFlow.emptyStatement' => 'forbidden'],
			'SlevomatCodingStandard.TypeHints.DeclareStrictTypes' => function (array $o, Translation $t) {
				if (($o['spacesCountAroundEqualsSign'] ?? 1) !== 0) {
					$t->warn('`SlevomatCodingStandard.TypeHints.DeclareStrictTypes` with spaces around the equals sign has no equivalent; DressCode writes `declare(strict_types=1)` without spaces.');
				}
				$t->setAll([
					'file.strictTypes' => 'required',
					'file.strictTypesPosition' => ($o['declareOnFirstLine'] ?? false) ? 'openingTagLine' : 'ownLine',
				]);
			},
			'SlevomatCodingStandard.TypeHints.DisallowArrayTypeHintSyntax' => fn(array $o, Translation $t) => $t->set('phpdoc.types.array', 'generic'),
			'SlevomatCodingStandard.TypeHints.DNFTypeHintFormat' => $typeFormat('SlevomatCodingStandard.TypeHints.DNFTypeHintFormat'),
			'SlevomatCodingStandard.TypeHints.LongTypeHints' => fn(array $o, Translation $t) => $t->set('phpdoc.types.builtin', 'canonical'),
			'SlevomatCodingStandard.TypeHints.NullTypeHintOnLastPosition' => fn(array $o, Translation $t) => $t->set('phpdoc.types.nullPosition', 'last'),
			'SlevomatCodingStandard.TypeHints.NullableTypeForNullDefaultValue' => ['upgrading.php.implicitNullable' => 'forbidden'],
			'SlevomatCodingStandard.TypeHints.ParameterTypeHint' => fn(array $o, Translation $t) => $t
				->setAll(array_filter([
					'types.parameter' => 'required',
					'types.traversableClasses' => $o['traversableTypeHints'] ?? null,
				], fn($v) => $v !== null)),
			'SlevomatCodingStandard.TypeHints.ParameterTypeHintSpacing' => [
				'spacing.typeDeclaration' => 'compact',
			],
			'SlevomatCodingStandard.TypeHints.PropertyTypeHint' => fn(array $o, Translation $t) => $t
				->setAll(array_filter([
					'types.property' => 'required',
					'types.traversableClasses' => $o['traversableTypeHints'] ?? null,
				], fn($v) => $v !== null)),
			'SlevomatCodingStandard.TypeHints.PropertyTypeHintSpacing' => [
				'spacing.typeDeclaration' => 'compact',
			],
			'SlevomatCodingStandard.TypeHints.ReturnTypeHint' => fn(array $o, Translation $t) => $t
				->setAll(array_filter([
					'types.return' => 'required',
					'types.traversableClasses' => $o['traversableTypeHints'] ?? null,
				], fn($v) => $v !== null)),
			'SlevomatCodingStandard.TypeHints.ReturnTypeHintSpacing' => [
				'spacing.typeDeclaration' => 'compact',
			],
			'SlevomatCodingStandard.TypeHints.UnionTypeHintFormat' => $typeFormat('SlevomatCodingStandard.TypeHints.UnionTypeHintFormat'),
			'SlevomatCodingStandard.TypeHints.UselessConstantTypeHint' => ['phpdoc.constantVar' => 'forbidden'],
			'SlevomatCodingStandard.Variables.DuplicateAssignmentToVariable' => ['correctness.repeatedAssignment' => 'forbidden'],
			'Squiz.Arrays.ArrayBracketSpacing' => ['spacing.offsetBrackets' => 'compact'],
			'Squiz.Classes.SelfMemberReference' => ['qualification.currentClass' => 'self'],
			'Squiz.Classes.ValidClassName' => fn(array $o, Translation $t) => $t->set('naming.class', 'PascalCase'),
			'Squiz.Commenting.DocCommentAlignment' => ['phpdoc.stars' => 'aligned'],
			'Squiz.Commenting.FunctionComment.DuplicateReturn' => ['phpdoc.duplicateReturn' => 'forbidden'],
			'Squiz.Commenting.FunctionComment.ExtraParamComment' => ['phpdoc.paramOfMissingParameter' => 'forbidden'],
			'Squiz.Commenting.VariableComment' => fn(array $o, Translation $t) => $t->setAll([
				'phpdoc.propertyComment' => 'phpdoc',
				'phpdoc.duplicateVar' => 'forbidden',
				'phpdoc.emptyAnnotation' => 'forbidden',
			]),
			'Squiz.ControlStructures.ControlSignature' => fn(array $o, Translation $t) => $t->setAll([
				'braces.controlStructure' => 'sameLine',
				'braces.continuingKeyword' => 'sameLine',
				'spacing.controlKeyword' => 'spaced',
				'spacing.connectingKeyword' => 'spaced',
			]),
			'Squiz.ControlStructures.ForEachLoopDeclaration' => function (array $o, Translation $t) {
				if (($o['requiredSpacesAfterOpen'] ?? 0) > 0 || ($o['requiredSpacesBeforeClose'] ?? 0) > 0) {
					$t->warn('`Squiz.ControlStructures.ForEachLoopDeclaration` with spaces inside the parentheses has no equivalent; DressCode writes none.');
				}
				$t->warn('`Squiz.ControlStructures.ForEachLoopDeclaration` also puts a single space around the `=>` of a `foreach`, which DressCode decides for every binary operator together, by `spacing.binaryOperator`.');
				$t->setAll([
					'spacing.parentheses' => 'compact',
					'spacing.connectingKeyword' => 'spaced',
					'builtin.keyword' => 'lowercase',
				]);
			},
			'Squiz.ControlStructures.ForLoopDeclaration' => function (array $o, Translation $t) {
				if (($o['requiredSpacesAfterOpen'] ?? 0) > 0 || ($o['requiredSpacesBeforeClose'] ?? 0) > 0) {
					$t->warn('`Squiz.ControlStructures.ForLoopDeclaration` with spaces inside the parentheses has no equivalent; DressCode writes none.');
				}
				$t->setAll([
					'spacing.parentheses' => 'compact',
					'spacing.beforeSemicolon' => 'compact',
					'spacing.afterSemicolon' => 'spaced',
				]);
			},
			'Squiz.ControlStructures.LowercaseDeclaration' => ['builtin.keyword' => 'lowercase'],
			'Squiz.Functions.FunctionDeclaration' => ['spacing.functionKeyword' => 'spaced', 'spacing.call' => 'compact'],
			'Squiz.Functions.FunctionDeclarationArgumentSpacing' => ['spacing.comma' => 'spaced', 'spacing.commaAlignment' => 'tabs'],
			'Squiz.Functions.LowercaseFunctionKeywords' => ['builtin.keyword' => 'lowercase'],
			'Squiz.Functions.MultiLineFunctionDeclaration' => [
				'braces.function' => 'nextLine',
				'braces.afterMultilineSignature' => 'sameLine',
				'braces.closure' => 'sameLine',
				'multiline.signature' => 'perLine',
			],
			'Squiz.NamingConventions.ValidFunctionName' => fn(array $o, Translation $t) => $t->setAll(['naming.method' => 'camelCase', 'naming.function' => 'camelCase']),
			'Squiz.NamingConventions.ValidVariableName' => function (array $o, Translation $t) {
				$t->warn('`Squiz.NamingConventions.ValidVariableName` also rules on the underscore prefix of private properties, which DressCode does not.');
				$t->setAll(['naming.property' => 'camelCase', 'naming.variable' => 'camelCase']);
			},
			'Squiz.Operators.ValidLogicalOperators' => ['expressions.wordLogicalOperators' => 'forbidden'],
			'Squiz.PHP.DiscouragedFunctions' => fn(array $o, Translation $t) => $t->set('upgrading.libraries.forbiddenFunctions', ['error_log' => null, 'print_r' => null, 'var_dump' => null]),
			'Squiz.PHP.GlobalKeyword' => ['correctness.globalStatement' => 'forbidden'],
			'Squiz.PHP.InnerFunctions' => ['functions.innerFunctions' => 'forbidden'],
			'Squiz.PHP.LowercasePHPFunctions' => ['builtin.function' => 'declared'],
			'Squiz.Scope.MethodScope' => ['classes.memberVisibility' => 'required', 'classes.interfaceMethodVisibility' => 'required'],
			'Squiz.Scope.StaticThisUsage' => ['correctness.thisOutsideObject' => 'forbidden'],
			'Squiz.Strings.ConcatenationSpacing' => fn(array $o, Translation $t) => $t->set('spacing.concatenation', ($o['spacing'] ?? 0) > 0 ? 'spaced' : 'compact'),
			'Squiz.Strings.DoubleQuoteUsage' => fn(array $o, Translation $t) => $t->set('literals.quotes', 'single'),
			'Squiz.Strings.EchoedStrings' => ['expressions.parenthesesAfterConstruct' => 'forbidden'],
			'Squiz.WhiteSpace.CastSpacing' => ['builtin.castType' => 'short'],
			'Squiz.WhiteSpace.ControlStructureSpacing' => function (array $o, Translation $t) {
				$t->warn('`Squiz.WhiteSpace.ControlStructureSpacing` checks the blank lines inside the braces and the spaces inside the parentheses of a control structure only, while `blankLines.afterBlockOpeningBrace`, `blankLines.beforeBlockClosingBrace` and `spacing.parentheses` govern every block and every parenthesis.');
				$t->setAll([
					'spacing.parentheses' => 'compact',
					'blankLines.afterBlockOpeningBrace' => 0,
					'blankLines.beforeBlockClosingBrace' => 0,
					'blankLines.afterStatement' => array_fill_keys(['do', 'for', 'foreach', 'if', 'switch', 'try', 'while'], [1, null]),
				]);
			},
			'Squiz.WhiteSpace.FunctionOpeningBraceSpace' => fn(array $o, Translation $t) => $t->set('blankLines.afterBlockOpeningBrace', 0),
			'Squiz.WhiteSpace.FunctionSpacing' => function (array $o, Translation $t) {
				$t->warn('`Squiz.WhiteSpace.FunctionSpacing` leaves the blank lines around classes alone, while `blankLines` sets them together with those around functions.');
				$t->setAll([
					'blankLines.betweenDeclarations' => $o['spacing'] ?? 2,
					'blankLines.betweenMethods' => $o['spacing'] ?? 2,
					'blankLines.betweenInterfaceMethods' => $o['spacing'] ?? 2,
					'blankLines.beforeFirstMethod' => $o['spacingBeforeFirst'] ?? 2,
					'blankLines.afterLastMethod' => $o['spacingAfterLast'] ?? 2,
				]);
			},
			'Squiz.WhiteSpace.LogicalOperatorSpacing' => ['spacing.binaryOperator' => 'spaced', 'spacing.binaryOperatorAlignment' => 'spaces'],
			'Squiz.WhiteSpace.ObjectOperatorSpacing' => ['spacing.objectOperator' => 'compact'],
			'Squiz.WhiteSpace.OperatorSpacing' => ['spacing.binaryOperator' => 'spaced', 'spacing.binaryOperatorAlignment' => 'spaces'],
			'Squiz.WhiteSpace.OperatorSpacing.Unary' => ['spacing.unaryOperator' => 'compact', 'spacing.unaryOperatorsWithSpace' => []],
			'Squiz.WhiteSpace.ScopeClosingBrace' => fn(array $o, Translation $t) => $t->warn('`Squiz.WhiteSpace.ScopeClosingBrace` puts a closing brace on a line of its own at the indentation of the line opening it, which DressCode does wherever `braces` places the opening brace and `indentation.unit` indents.'),
			'Squiz.WhiteSpace.ScopeKeywordSpacing' => ['spacing.doubleColon' => 'compact'],
			'Squiz.WhiteSpace.SemicolonSpacing' => ['spacing.beforeSemicolon' => 'compact', 'multiline.semicolonOnOwnLine' => 'forbidden'],
			'Squiz.WhiteSpace.SuperfluousWhitespace' => ['file.trailingWhitespace' => 'forbidden'],
		];
	}


	/**
	 * Rules of a phpcs.xml ruleset: every <rule ref> with the properties it sets and every <exclude> inside it or
	 * <severity> of 0 as a rule switched off, and what the ruleset says that no rule carries. A value is typed by its text, true, false
	 * and digits, because XML carries every value as a string.
	 * @return array{array<string, bool|array<string, mixed>>, list<string>}
	 * @throws ConfigurationException
	 */
	public static function readConfig(string $file): array
	{
		if (!is_file($file)) {
			throw new ConfigurationException("File `$file` does not exist.");
		}

		$internal = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$xml = simplexml_load_file($file);
		$error = libxml_get_errors()[0] ?? null;
		libxml_clear_errors();
		libxml_use_internal_errors($internal);
		if ($xml === false) {
			throw new ConfigurationException("File `$file` is not a PHP_CodeSniffer ruleset" . ($error ? ': ' . trim($error->message) . " on line $error->line." : '.'));
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

			$rules[$ref] = (string) $rule->severity === '0' ? false : ($properties === [] ? true : $properties);
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
