<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Interop;

use DressCode\ConfigurationException;
use DressCode\Domains\Map;
use DressCode\Rules\Functions\NoAliasFunctionsRule;


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
		// the blank lines before a property, a constant or an enum case, `blankLines` counting those after a member of
		// another kind and before a documented one for every member together
		$memberSpacing = fn(string $place) => fn(array $o, Translation $t) => $t
			->setBlankLines(
				"blankLines.betweenMembers.$place",
				$o['minLinesCountBeforeWithoutComment'] ?? 0,
				$o['maxLinesCountBeforeWithoutComment'] ?? 1,
			)
			->setBlankLines(
				'blankLines.betweenMemberKinds',
				$o['minLinesCountBeforeWithoutComment'] ?? 0,
				$o['maxLinesCountBeforeWithoutComment'] ?? 1,
			)
			->setBlankLines(
				'blankLines.beforeDocumentedMember',
				$o['minLinesCountBeforeWithComment'] ?? 1,
				$o['maxLinesCountBeforeWithComment'] ?? 1,
			);

		// the nullable and the place of null in a type, which the sniffs check only where their properties name them
		$typeFormat = fn(string $sniff) => function (array $o, Translation $t) use ($sniff) {
			if (!isset($o['shortNullable']) && !isset($o['nullPosition'])) {
				$t->warn("`$sniff` without `shortNullable` or `nullPosition` checks nothing DressCode decides.");
			}

			$values = array_filter([
				'nullable' => isset($o['shortNullable']) ? ($o['shortNullable'] === 'yes' ? 'questionMark' : 'union') : null,
				'nullPosition' => $o['nullPosition'] ?? null,
			], fn($v) => $v !== null);
			// the doc comments are checked alike where the sniff is told so
			foreach (($o['enableForDocComments'] ?? false) ? ['types', 'phpdoc.types'] : ['types'] as $section) {
				foreach ($values as $key => $value) {
					$t->set("$section.$key", $value);
				}
			}
		};

		return [
			'Generic.Arrays.DisallowLongArraySyntax' => ['literals.arraySyntax.array' => 'short'],
			'Generic.Arrays.DisallowShortArraySyntax' => ['literals.arraySyntax.array' => 'long'],
			'Generic.CodeAnalysis.RequireExplicitBooleanOperatorPrecedence' => ['expressions.explicitPrecedence' => 'required'],
			'Generic.CodeAnalysis.UnnecessaryFinalModifier' => ['classes.modifiers.implied' => 'forbidden'],
			'Generic.ControlStructures.InlineControlStructure' => ['braces.bracelessBody' => 'forbidden'],
			'Generic.Files.ByteOrderMark' => ['file.bom' => 'forbidden'],
			'Generic.Files.LineEndings' => ['file.lineEnding' => 'majority'],
			// a `lineLimit` given alone is the limit the project names; the absolute one is a limit only where it is given too
			'Generic.Files.LineLength' => fn(array $o, Translation $t) => $t->setLineLength(
				($o['absoluteLineLimit'] ?? (isset($o['lineLimit']) ? 0 : 100)) ?: ($o['lineLimit'] ?? 80),
			)
				->setAll(['file.longLines' => 'forbidden', 'file.lineLengthExcept' => ['imports']]),
			'Generic.Formatting.DisallowMultipleStatements' => ['file.statementsPerLine' => 1],
			'Generic.Functions.FunctionCallArgumentSpacing' => ['spacing.comma' => 'spaced', 'spacing.alignment.comma' => 'tabs'],
			'Generic.Functions.FunctionCallArgumentSpacing.SpaceBeforeOpenBracket' => ['spacing.call' => 'compact'],
			'Generic.NamingConventions.CamelCapsFunctionName' => fn(array $o, Translation $t) => $t->setAll([
				'naming.method' => 'camelCase',
				'naming.function' => 'camelCase',
			]),
			'Generic.NamingConventions.UpperCaseConstantName' => function (array $o, Translation $t) {
				$t->warn('`Generic.NamingConventions.UpperCaseConstantName` also checks `define()`, which DressCode does not.');
				$t->set('naming.constant', 'UPPER_CASE');
			},
			'Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase' => ['naming.constant' => 'UPPER_CASE'],
			'Generic.PHP.DeprecatedFunctions' => ['upgrading.php.deprecatedApi' => 'forbidden'],
			'Generic.PHP.DisallowShortOpenTag' => ['file.openingTag' => 'required'],
			// an alias of PHP is written as its function, any other replacement is advice; the default of the sniff leaves out
			// `delete`, which PHP has no function of
			'Generic.PHP.ForbiddenFunctions' => function (array $o, Translation $t) {
				$t->cover('upgrading.api.replacedFunctions', 'upgrading.api.forbiddenFunctions');
				$replaced = $forbidden = [];
				foreach ($o['forbiddenFunctions'] ?? ['sizeof' => 'count'] as $name => $replacement) {
					$function = strtolower($name); // as the sniff reads it
					$replacement = $replacement === 'null' ? null : $replacement; // the text a ruleset writes for no replacement
					if ($replacement !== null && NoAliasFunctionsRule::isAlias($function, $replacement)) {
						$replaced[$function] = $replacement;
					} else {
						$forbidden[$function] = $replacement === null ? null : "use `$replacement()` instead";
					}
				}

				if ($replaced !== []) {
					$t->set('upgrading.api.replacedFunctions', $replaced);
				}
				if ($forbidden !== []) {
					$t->set('upgrading.api.forbiddenFunctions', Map::collapseBareNames($forbidden));
				}
			},
			'Generic.PHP.LowerCaseConstant' => ['casing.trueFalseNull' => 'lowercase'],
			'Generic.PHP.LowerCaseKeyword' => ['casing.keyword' => 'lowercase'],
			// `array`, `self` and the like are keywords of a declaration
			'Generic.PHP.LowerCaseType' => [
				'expressions.cast' => 'canonical',
				'casing.type' => 'lowercase',
				'casing.keyword' => 'lowercase',
			],
			'Generic.PHP.RequireStrictTypes' => ['file.strictTypes.declaration' => 'required', 'file.strictTypes.position' => 'nextLine'],
			'Generic.PHP.SAPIUsage' => fn(array $o, Translation $t) => $t->set(
				'upgrading.api.forbiddenFunctions',
				['php_sapi_name' => 'use `PHP_SAPI` instead'],
			),
			'Generic.Strings.UnnecessaryStringConcat' => fn(array $o, Translation $t) => $t->setAll([
				'literals.concatenatedLiterals' => 'forbidden',
				'literals.concatenatedLiteralsOverLines' => !($o['allowMultiline'] ?? false),
				'literals.emptyStringConcatenation' => 'forbidden',
			]),
			'Generic.WhiteSpace.DisallowSpaceIndent' => ['indentation.unit' => 'tab'],
			// spaces of a width the sniff does not say, four unless a sniff naming the width decides it, in either order
			'Generic.WhiteSpace.DisallowTabIndent' => fn(array $o, Translation $t) => $t->prefer('indentation.unit', '4 spaces'),
			'Generic.WhiteSpace.IncrementDecrementSpacing' => ['spacing.unaryOperator' => 'compact'],
			'Generic.WhiteSpace.LanguageConstructSpacing' => ['spacing.keyword' => 'required'],
			'Generic.WhiteSpace.ScopeIndent' => function (array $o, Translation $t) {
				$unit = ($o['tabIndent'] ?? false) ? 'tab' : match ($o['indent'] ?? 4) {
					4 => '4 spaces',
					2 => '2 spaces',
					default => null,
				};
				if ($unit === null) {
					$t->warn("`Generic.WhiteSpace.ScopeIndent` with `indent={$o['indent']}` has no equivalent; DressCode indents by a tab, four spaces or two.");
				} elseif (isset($o['tabIndent']) || isset($o['indent'])) {
					$t->set('indentation.unit', $unit);
				} else {
					// with its defaults the sniff says only the width of a level, so a sniff asking for tabs wins
					$t->prefer('indentation.unit', $unit);
				}
			},
			'PEAR.Commenting.InlineComment' => ['comments.singleline' => 'slashes'],
			'PEAR.Functions.ValidDefaultValue' => ['functions.unreachableParameterDefault' => 'forbidden'],
			'PEAR.WhiteSpace.ObjectOperatorIndent' => fn(array $o, Translation $t) => $t
				->set('indentation.chain', ($o['multilevel'] ?? false) ? 'nested' : 'flat'),
			'PSR1.Methods.CamelCapsMethodName' => fn(array $o, Translation $t) => $t->set('naming.method', 'camelCase'),
			'PSR1.Methods.CamelCapsMethodName.NotCamelCaps' => ['naming.method' => 'camelCase'],
			'PSR12.Classes.AnonClassDeclaration' => [
				'braces.position.anonymousClass' => 'sameLine',
				'spacing.keyword' => 'required',
				'spacing.anonymousClass' => 'spaced',
				'multiline.items.implements' => 'perLine',
				'multiline.edges.implements' => 'apart',
				...Translation::NoSpaceInParentheses,
			],
			'PSR12.Classes.ClassInstantiation' => ['classes.emptyParentheses.instantiation' => 'required'],
			'PSR12.Classes.ClosingBrace' => function (array $o, Translation $t) {
				$t->warn('`PSR12.Classes.ClosingBrace` also forbids a comment after the closing brace of a class or a function, which DressCode does not.');
				$t->set('file.statementsPerLine', 1);
			},
			'PSR12.Classes.OpeningBraceSpace' => fn(array $o, Translation $t) => $t->setAll([
				'blankLines.bodyStart.class' => 0,
				'blankLines.bodyStart.anonymousClass' => 0,
			]),
			'PSR12.ControlStructures.BooleanOperatorPlacement' => function (array $o, Translation $t) {
				$only = $o['allowOnly'] ?? null;
				if ($only === 'first') {
					$t->set('multiline.operatorPosition.condition', 'lineStart');
				} elseif ($only === 'last') {
					$t->set('multiline.operatorPosition.condition', 'lineEnd');
				} else {
					$t->warn('`PSR12.ControlStructures.BooleanOperatorPlacement` forbids a condition mixing boolean operators at the start and at the end of its lines, which DressCode does not check.');
					$t->keep('multiline.operatorPosition.condition');
				}
			},
			'PSR12.ControlStructures.ControlStructureSpacing' => function (array $o, Translation $t) {
				$t->warn('`PSR12.ControlStructures.ControlStructureSpacing` also puts the parentheses of a multi-line `for`, `foreach`, `switch`, `catch` and `match` on lines of their own, which DressCode does not.');
				$t->setAll([
					...Translation::NoSpaceInParentheses,
					'multiline.items.condition' => ['perLine', 'fill'],
					'multiline.edges.condition' => 'apart',
				]);
			},
			'PSR12.Files.DeclareStatement' => function (array $o, Translation $t) {
				$t->warn('`PSR12.Files.DeclareStatement` also checks the case of the directive of a `declare`, which DressCode does not.');
				$t->setAll(['spacing.declare' => 'compact', 'braces.position.controlStructure' => 'sameLine']);
			},
			'PSR12.Files.FileHeader' => [
				'blankLines.afterOpeningTag' => 1,
				'blankLines.afterDeclare' => 1,
				'blankLines.beforeNamespace' => 1,
				'blankLines.afterNamespace' => 1,
				'blankLines.afterImports' => 1,
				'blankLines.beforeDeclaration' => 1,
				'blankLines.betweenImports' => 0,
				'blankLines.betweenImportKinds' => 1,
			],
			'PSR12.Files.ImportStatement' => fn(array $o, Translation $t) => $t->set('imports.leadingBackslash', 'forbidden'),
			'PSR12.Files.OpenTag' => function (array $o, Translation $t) {
				$t->warn('`PSR12.Files.OpenTag` leaves the line below the opening tag as it is, where DressCode puts a blank line.');
				$t->set('blankLines.afterOpeningTag', 1);
			},
			'PSR12.Functions.NullableTypeDeclaration' => ['spacing.typeDeclaration' => 'compact'],
			'PSR12.Functions.ReturnTypeDeclaration' => ['spacing.typeDeclaration' => 'compact'],
			'PSR12.Keywords.ShortFormTypeKeywords' => ['expressions.cast' => 'canonical'],
			'PSR12.Operators.OperatorSpacing' => fn(array $o, Translation $t) => $t->setAll([
				'spacing.binaryOperator' => 'spaced',
				'spacing.alignment.binaryOperator' => 'spaces',
				'spacing.ternary' => 'spaced',
				'spacing.alignment.ternary' => 'optional',
				'spacing.concatenation' => 'spaced',
			]),
			'PSR12.Properties.ConstantVisibility' => [
				'classes.visibility.constant' => 'required',
				'classes.visibility.property' => 'required',
				'classes.visibility.method' => 'required',
			],
			'PSR12.Traits.UseDeclaration' => function (array $o, Translation $t) {
				$t->warn('`PSR12.Traits.UseDeclaration` also lays out the conflict block of a trait use, its opening brace on the line of `use` and a rule per line, which DressCode does not.');
				$t->setAll([
					'blankLines.bodyStart.class' => 0,
					'blankLines.betweenTraitUses' => 0,
					'blankLines.afterTraitUses' => 1,
					'blankLines.bodyEnd.class' => 0,
					// the space after `use`, around `as` and `insteadof`, and none before the semicolon
					'spacing.keyword' => 'required',
					'spacing.semicolon.before' => 'compact',
				]);
				$t->set('classes.members.grouping.traitUse', 'separated');
				// a full order another rule sets puts the trait uses where it says
				$t->prefer('classes.members.order', ['traitUse']);
			},
			'PSR2.Classes.ClassDeclaration' => [
				'braces.position.class' => 'nextLine',
				'spacing.keyword' => 'required',
				'multiline.items.implements' => 'perLine',
				'multiline.edges.implements' => 'apart',
			],
			'PSR2.Classes.ClassDeclaration.SpaceBeforeKeyword' => ['spacing.keyword' => 'required'],
			'PSR2.Classes.PropertyDeclaration' => [
				'classes.visibility.constant' => 'required',
				'classes.visibility.property' => 'required',
				'classes.visibility.method' => 'required',
				'classes.modifiers.order' => 'canonical',
			],
			'PSR2.ControlStructures.ControlStructureSpacing' => Translation::NoSpaceInParentheses,
			'PSR2.ControlStructures.ElseIfDeclaration' => ['controlFlow.elseif' => 'oneWord'],
			'PSR2.ControlStructures.SwitchDeclaration' => function (array $o, Translation $t) {
				$t->warn('`PSR2.ControlStructures.SwitchDeclaration` also starts the body of a case on the line below it and forbids braces around it, which DressCode does not.');
				$t->setAll([
					'upgrading.php.caseSemicolon' => 'forbidden',
					'spacing.switchCase' => 'compact',
					'controlFlow.switch.fallThroughComment' => 'no break',
					'casing.keyword' => 'lowercase',
					'spacing.keyword' => 'required',
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
					...Translation::NoSpaceInParentheses,
					'multiline.items.argument' => ($o['allowMultipleArguments'] ?? false) ? 'fill' : 'perLine',
					'multiline.edges.argument' => 'apart',
				]);
			},
			'PSR2.Methods.FunctionClosingBrace' => fn(array $o, Translation $t) => $t->setAll([
				'blankLines.bodyEnd.function' => 0,
				'blankLines.bodyEnd.closure' => 0,
			]),
			'PSR2.Methods.MethodDeclaration' => function (array $o, Translation $t) {
				$t->warn('`PSR2.Methods.MethodDeclaration` also rules on the underscore prefix of method names, which DressCode does not.');
				$t->set('classes.modifiers.order', 'canonical');
			},
			'PSR2.Namespaces.NamespaceDeclaration' => ['blankLines.afterNamespace' => 1, 'blankLines.beforeDeclaration' => 1],
			'PSR2.Namespaces.UseDeclaration' => [
				'blankLines.afterImports' => 1,
				'blankLines.beforeDeclaration' => 1,
				'imports.grouping.class' => 'separated',
				'imports.grouping.function' => 'separated',
				'imports.grouping.constant' => 'separated',
			],
			'SlevomatCodingStandard.Arrays.MultiLineArrayEndBracketPlacement' => ['multiline.edges.array' => ['apart', 'attached']],
			'SlevomatCodingStandard.Arrays.SingleLineArrayWhitespace' => Translation::NoSpaceInArrays,
			'SlevomatCodingStandard.Arrays.TrailingArrayComma' => fn(array $o, Translation $t) => $t->set(
				'multiline.trailingComma.array',
				'required',
			),
			'SlevomatCodingStandard.Attributes.AttributeAndTargetSpacing' => fn(array $o, Translation $t) => $t->set(
				'blankLines.afterAttributes',
				$o['linesCountBetweenAttributeAndTarget'] ?? 0,
			),
			'SlevomatCodingStandard.Attributes.DisallowMultipleAttributesPerLine' => [
				'multiline.attributePosition.declaration' => 'above',
				'multiline.attributePosition.parameter' => 'above',
			],
			'SlevomatCodingStandard.Attributes.RequireAttributeAfterDocComment' => ['phpdoc.aboveAttributes' => 'required'],
			'SlevomatCodingStandard.Classes.BackedEnumTypeSpacing' => [
				'spacing.typeDeclaration' => 'compact',
			],
			'SlevomatCodingStandard.Classes.ClassConstantVisibility' => [
				'classes.visibility.constant' => 'required',
				'classes.visibility.property' => 'required',
				'classes.visibility.method' => 'required',
			],
			// only between members of two kinds, a trait use, a constant, a property, a method
			'SlevomatCodingStandard.Classes.ClassMemberSpacing' => fn(array $o, Translation $t) => $t
				->setBlankLines('blankLines.betweenMemberKinds', $o['linesCountBetweenMembers'] ?? 1, $o['linesCountBetweenMembers'] ?? 1),
			'SlevomatCodingStandard.Classes.ConstantSpacing' => $memberSpacing('constant'),
			'SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition' => ['classes.members.grouping.constant' => 'separated'],
			'SlevomatCodingStandard.Classes.DisallowMultiPropertyDefinition' => ['classes.members.grouping.property' => 'separated'],
			'SlevomatCodingStandard.Classes.EmptyLinesAroundClassBraces' => fn(array $o, Translation $t) => $t->setAll([
				'blankLines.bodyStart.class' => $o['linesCountAfterOpeningBrace'] ?? 1,
				'blankLines.bodyStart.anonymousClass' => $o['linesCountAfterOpeningBrace'] ?? 1,
				'blankLines.bodyEnd.class' => $o['linesCountBeforeClosingBrace'] ?? 1,
				'blankLines.bodyEnd.anonymousClass' => $o['linesCountBeforeClosingBrace'] ?? 1,
			]),
			'SlevomatCodingStandard.Classes.EnumCaseSpacing' => $memberSpacing('enumCase'),
			'SlevomatCodingStandard.Classes.MethodSpacing' => fn(array $o, Translation $t) => $t
				->setBlankLines('blankLines.aroundMethods', $o['minLinesCount'] ?? 1, $o['maxLinesCount'] ?? 1)
				->setBlankLines('blankLines.aroundInterfaceMethods', $o['minLinesCount'] ?? 1, $o['maxLinesCount'] ?? 1),
			'SlevomatCodingStandard.Classes.ModernClassNameReference' => fn(array $o, Translation $t) => $t->setAll([
				'hygiene.classNameNotation.currentClass' => 'required',
				'hygiene.classNameNotation.object' => ($o['enableOnObjects'] ?? false) ? 'required' : 'keep',
			]),
			'SlevomatCodingStandard.Classes.PropertyDeclaration' => [
				'classes.modifiers.order' => 'canonical',
			],
			'SlevomatCodingStandard.Classes.PropertySpacing' => $memberSpacing('property'),
			'SlevomatCodingStandard.Classes.RequireConstructorPropertyPromotion' => fn(array $o, Translation $t) => ($o['enable'] ?? true)
				? $t->set('upgrading.syntax.promotedProperties', 'required')
				: $t->keep('upgrading.syntax.promotedProperties'),
			'SlevomatCodingStandard.Classes.RequireMultiLineMethodSignature' => function (array $o, Translation $t) {
				if (($min = $o['minLineLength'] ?? 121) > 1) {
					$t->setLineLength($min - 1);
				} else {
					$t->warn("`SlevomatCodingStandard.Classes.RequireMultiLineMethodSignature` with `minLineLength=$min` has no equivalent; DressCode spreads over lines only a signature longer than the line.");
				}

				$t->setAll([
					'multiline.items.parameter' => 'perLine',
					'multiline.edges.parameter' => 'apart',
					'multiline.spreadAlways' => ($o['withPromotedProperties'] ?? false) ? ['promotedProperty'] : [],
				]);
			},
			'SlevomatCodingStandard.Classes.SuperfluousAbstractClassNaming' => fn(array $o, Translation $t) => $t->set(
				'naming.abstractClassAffix',
				'forbidden',
			),
			'SlevomatCodingStandard.Classes.SuperfluousErrorNaming' => fn(array $o, Translation $t) => $t->set(
				'naming.errorAffix',
				'forbidden',
			),
			'SlevomatCodingStandard.Classes.SuperfluousExceptionNaming' => fn(array $o, Translation $t) => $t->set(
				'naming.exceptionAffix',
				'forbidden',
			),
			'SlevomatCodingStandard.Classes.SuperfluousInterfaceNaming' => fn(array $o, Translation $t) => $t->set(
				'naming.interfaceAffix',
				'forbidden',
			),
			'SlevomatCodingStandard.Classes.SuperfluousTraitNaming' => fn(array $o, Translation $t) => $t->set(
				'naming.traitAffix',
				'forbidden',
			),
			'SlevomatCodingStandard.Classes.TraitUseDeclaration' => ['classes.members.grouping.traitUse' => 'separated'],
			'SlevomatCodingStandard.Classes.TraitUseSpacing' => fn(array $o, Translation $t) => $t->setAll([
				'blankLines.betweenTraitUses' => $o['linesCountBetweenUses'] ?? 0,
				'blankLines.afterTraitUses' => $o['linesCountAfterLastUse'] ?? 1,
			]),
			'SlevomatCodingStandard.Classes.UselessLateStaticBinding' => fn(array $o, Translation $t) => $t->setAll([
				'qualification.currentClass' => 'self',
				'qualification.staticInFinalClass' => 'required',
			]),
			'SlevomatCodingStandard.Commenting.AnnotationName' => ['phpdoc.annotation.casing' => 'canonical'],
			'SlevomatCodingStandard.Commenting.DisallowOneLinePropertyDocComment' => ['phpdoc.property.lineSpan' => 'expanded'],
			'SlevomatCodingStandard.Commenting.EmptyComment' => ['comments.empty' => 'forbidden'],
			'SlevomatCodingStandard.Commenting.ForbiddenAnnotations' => fn(array $o, Translation $t) => $t->set(
				'phpdoc.forbiddenAnnotations',
				($o['forbiddenAnnotations'] ?? []) ?: 'keep',
			),
			'SlevomatCodingStandard.Commenting.ForbiddenComments' => fn(array $o, Translation $t) => $t->set(
				'phpdoc.forbiddenLines',
				$o['forbiddenCommentPatterns'] ?? [],
			),
			'SlevomatCodingStandard.Commenting.InlineDocCommentDeclaration.InvalidCommentType' => ['phpdoc.annotation.plainComment' => 'forbidden'],
			'SlevomatCodingStandard.Commenting.RequireOneLinePropertyDocComment' => ['phpdoc.property.lineSpan' => 'collapsed'],
			'SlevomatCodingStandard.Commenting.UselessFunctionDocComment' => ['phpdoc.annotation.nativeTypeOnly' => 'forbidden'],
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
			'SlevomatCodingStandard.ControlStructures.DisallowContinueWithoutIntegerOperandInSwitch' => function (array $o, Translation $t) {
				$t->warn('`SlevomatCodingStandard.ControlStructures.DisallowContinueWithoutIntegerOperandInSwitch` is only reported by DressCode, which does not rewrite the `continue`.');
				$t->set('mistakes.continueInSwitch', 'forbidden');
			},
			'SlevomatCodingStandard.ControlStructures.DisallowShortTernaryOperator' => ['expressions.ternary.short' => 'forbidden'],
			'SlevomatCodingStandard.ControlStructures.DisallowTrailingMultiLineTernaryOperator' => ['multiline.operatorPosition.ternary' => 'lineStart'],
			'SlevomatCodingStandard.ControlStructures.DisallowYodaComparison' => ['expressions.comparison.yoda' => 'forbidden'],
			'SlevomatCodingStandard.ControlStructures.RequireYodaComparison' => ['expressions.comparison.yoda' => 'required'],
			'SlevomatCodingStandard.ControlStructures.EarlyExit' => function (array $o, Translation $t) {
				foreach (['ignoreStandaloneIfInScope', 'ignoreOneLineTrailingIf'] as $property) {
					if ($o[$property] ?? false) {
						$t->warn("`SlevomatCodingStandard.ControlStructures.EarlyExit` with `$property=true` has no equivalent; DressCode makes a guard of every trailing `if` that has enough statements.");
					}
				}

				$t->setAll([
					'controlFlow.trailingIf' => 'forbidden',
					'controlFlow.trailingIfMinStatements' => ($o['ignoreTrailingIfWithOneInstruction'] ?? false) ? 2 : 1,
					'controlFlow.elseAfterReturn' => 'forbidden',
					'controlFlow.elseifAfterReturn' => 'forbidden',
				]);
			},
			'SlevomatCodingStandard.ControlStructures.JumpStatementsSpacing' => fn(array $o, Translation $t) => $t->setAll([
				'blankLines.beforeStatement' => array_fill_keys(
					array_intersect(
						$o['jumpStatements'] ?? ['break', 'continue', 'return', 'throw', 'yield'],
						['break', 'continue', 'return', 'throw', 'yield'],
					),
					$o['linesCountBefore'] ?? 1,
				),
				'blankLines.afterStatement' => array_fill_keys(
					array_intersect(
						$o['jumpStatements'] ?? ['break', 'continue', 'return', 'throw', 'yield'],
						['break', 'continue', 'return', 'throw', 'yield'],
					),
					$o['linesCountAfter'] ?? 1,
				),
			]),
			'SlevomatCodingStandard.ControlStructures.LanguageConstructWithParentheses' => ['expressions.parenthesesAfterConstruct' => 'forbidden'],
			'SlevomatCodingStandard.ControlStructures.NewWithParentheses' => ['classes.emptyParentheses.instantiation' => 'required'],
			'SlevomatCodingStandard.ControlStructures.NewWithoutParentheses' => ['classes.emptyParentheses.instantiation' => 'forbidden'],
			'SlevomatCodingStandard.ControlStructures.RequireMultiLineCondition' => function (array $o, Translation $t) {
				if (($min = $o['minLineLength'] ?? 121) > 1) {
					$t->setLineLength($min - 1);
				} else {
					$t->warn("`SlevomatCodingStandard.ControlStructures.RequireMultiLineCondition` with `minLineLength=$min` has no equivalent; DressCode spreads over lines only a condition longer than the line.");
				}

				$t->setAll([
					'multiline.items.condition' => ($o['alwaysSplitAllConditionParts'] ?? false) ? 'perLine' : ['perLine', 'fill'],
					'multiline.edges.condition' => ($o['alwaysSplitAllConditionParts'] ?? false) ? 'apart' : 'closingApart',
				]);
			},
			'SlevomatCodingStandard.ControlStructures.RequireMultiLineTernaryOperator' => function (array $o, Translation $t) {
				if (($limit = $o['lineLengthLimit'] ?? 0) > 0) {
					$t->setLineLength($limit);
				} else {
					$t->warn('`SlevomatCodingStandard.ControlStructures.RequireMultiLineTernaryOperator` with `lineLengthLimit=0` has no equivalent; DressCode spreads over lines only a ternary longer than the line.');
				}

				if (isset($o['minExpressionsLength'])) {
					$t->warn('`SlevomatCodingStandard.ControlStructures.RequireMultiLineTernaryOperator` with `minExpressionsLength` has no equivalent; DressCode does not measure the expressions of a ternary.');
				}

				$t->set('multiline.items.ternary', 'perLine');
			},
			'SlevomatCodingStandard.ControlStructures.RequireNullCoalesceEqualOperator' => ['expressions.assignment.targetInValue' => 'forbidden'],
			'SlevomatCodingStandard.ControlStructures.RequireNullCoalesceOperator' => ['expressions.coalesce' => 'required'],
			'SlevomatCodingStandard.ControlStructures.RequireNullSafeObjectOperator' => fn(array $o, Translation $t) => ($o['enable'] ?? true)
				? $t->set('upgrading.syntax.nullsafe', 'required')
				: $t->keep('upgrading.syntax.nullsafe'),
			'SlevomatCodingStandard.ControlStructures.RequireShortTernaryOperator' => ['expressions.ternary.short' => 'required'],
			'SlevomatCodingStandard.ControlStructures.RequireTernaryOperator' => ['controlFlow.ifElseReturning' => 'forbidden'],
			'SlevomatCodingStandard.ControlStructures.UselessIfConditionWithReturn' => ['controlFlow.ifReturningTrueFalse' => 'forbidden'],
			'SlevomatCodingStandard.ControlStructures.UselessTernaryOperator' => ['expressions.ternary.ofTrueFalse' => 'forbidden'],
			'SlevomatCodingStandard.Exceptions.DeadCatch' => ['mistakes.unreachableCatch' => 'forbidden'],
			'SlevomatCodingStandard.Exceptions.ReferenceThrowableOnly' => ['mistakes.exceptionWhereThrowableBelongs' => 'forbidden'],
			'SlevomatCodingStandard.Exceptions.RequireNonCapturingCatch' => ['hygiene.unreadCatchVariable' => 'forbidden'],
			'SlevomatCodingStandard.Files.LineLength' => fn(array $o, Translation $t) => $t->setLineLength($o['lineLengthLimit'] ?? 120)
				->setAll([
					'file.longLines' => 'forbidden',
					'file.lineLengthExcept' => ($o['ignoreImports'] ?? true) ? ['imports'] : [],
				]),
			'SlevomatCodingStandard.Functions.ArrowFunctionDeclaration' => fn(array $o, Translation $t) => match ($o['spacesCountAfterKeyword'] ?? 1) {
				0 => $t->set('spacing.arrowFunction', 'compact'),
				1 => $t->set('spacing.arrowFunction', 'spaced'),
				default => $t->warn("`SlevomatCodingStandard.Functions.ArrowFunctionDeclaration` with `spacesCountAfterKeyword={$o['spacesCountAfterKeyword']}` has no equivalent; DressCode writes a single space after `fn` or none."),
			},
			'SlevomatCodingStandard.Functions.NamedArgumentSpacing' => ['spacing.namedArgument' => 'spacedAfter'],
			'SlevomatCodingStandard.Functions.RequireArrowFunction' => fn(array $o, Translation $t) => $t->setAll([
				'functions.arrowFunction.oneExpression' => 'required',
				'functions.arrowFunctionNested' => $o['allowNested'] ?? true,
			]),
			'SlevomatCodingStandard.Functions.RequireTrailingCommaInCall' => fn(array $o, Translation $t) => $t->set(
				'multiline.trailingComma.argument',
				'required',
			),
			'SlevomatCodingStandard.Functions.RequireTrailingCommaInDeclaration' => fn(array $o, Translation $t) => $t->set(
				'multiline.trailingComma.parameter',
				'required',
			),
			'SlevomatCodingStandard.Functions.StaticClosure' => ['functions.staticClosure' => 'required'],
			'SlevomatCodingStandard.Functions.StrictCall' => ['mistakes.strictArgument' => 'required'],
			'SlevomatCodingStandard.Functions.UselessParameterDefaultValue' => ['functions.unreachableParameterDefault' => 'forbidden'],
			'SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses' => fn(array $o, Translation $t) => $t->setAll([
				'imports.order.names' => 'alphabetical',
				'imports.order.kinds' => ($o['psr12Compatible'] ?? true) ? ['class', 'function', 'constant'] : 'keep',
				'imports.order.caseSensitive' => $o['caseSensitive'] ?? false,
			]),
			'SlevomatCodingStandard.Namespaces.DisallowGroupUse' => function (array $o, Translation $t) {
				foreach (['class', 'function', 'constant'] as $kind) {
					$t->setAllowed("imports.grouping.$kind", ['separated', 'combined']);
				}
			},
			'SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalConstants' => function (array $o, Translation $t) {
				$named = [...$o['include'] ?? [], ...$o['exclude'] ?? []];
				if ($named !== []) {
					$t->warn('`SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalConstants` names constants one by one (`' . implode('`, `', $named) . '`), which DressCode decides by group; a constant named follows `qualification.constant.global`.');
				}

				// only an include left empty names every constant
				if (($o['include'] ?? []) === []) {
					$t->setQualificationShape(['globalConstant' => 'backslashed']);
					$t->setQualificationFallback(['constant' => 'qualified']);
				}
			},
			'SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalFunctions' => function (array $o, Translation $t) {
				$named = [...$o['include'] ?? [], ...$o['exclude'] ?? []];
				if ($named !== []) {
					$t->warn('`SlevomatCodingStandard.Namespaces.FullyQualifiedGlobalFunctions` names functions one by one (`' . implode('`, `', $named) . '`), which DressCode decides by group; a function named follows `qualification.function.global`, or `qualification.function.optimized` where the compiler optimizes it.');
				}

				// the special functions join the include, and only an include left empty names every function
				if ($o['includeSpecialFunctions'] ?? false) {
					$t->setQualificationShape(['optimizedFunction' => 'backslashed']);
					$t->setQualificationFallback(['optimizedFunction' => 'qualified']);
				} elseif (($o['include'] ?? []) === []) {
					$t->setQualificationShape(['globalFunction' => 'backslashed']);
					$t->setQualificationFallback(['function' => 'qualified']);
				}
			},
			// a comma between the names of a group use is one too
			'SlevomatCodingStandard.Namespaces.MultipleUsesPerLine' => [
				'imports.grouping.class' => 'separated',
				'imports.grouping.function' => 'separated',
				'imports.grouping.constant' => 'separated',
			],
			'SlevomatCodingStandard.Namespaces.NamespaceSpacing' => fn(array $o, Translation $t) => $t->setAll([
				'blankLines.beforeNamespace' => $o['linesCountBeforeNamespace'] ?? 1,
				'blankLines.afterNamespace' => $o['linesCountAfterNamespace'] ?? 1,
			]),
			'SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly' => function (array $o, Translation $t) {
				// a global name written with the backslash may stay so, and a bare one may stand on the fallback, or else it is imported
				$shape = fn(bool $backslash) => $backslash ? 'keep' : 'imported';
				$t->setQualificationShape([
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
				$t->setQualificationFallback($fallback);
				if (($o['allowPartialUses'] ?? true) === false) {
					$t->warn('`SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly` with `allowPartialUses=false` has no equivalent; DressCode lets a partial name stand.');
				}
			},
			'SlevomatCodingStandard.Namespaces.UnusedUses' => function (array $o, Translation $t) {
				if (!($o['searchAnnotations'] ?? false)) {
					$t->warn('`SlevomatCodingStandard.Namespaces.UnusedUses` without `searchAnnotations` removes an import only a doc comment uses; DressCode keeps it.');
				}

				$t->set('imports.unused', 'forbidden');
			},
			'SlevomatCodingStandard.Namespaces.UseDoesNotStartWithBackslash' => fn(array $o, Translation $t) => $t->set(
				'imports.leadingBackslash',
				'forbidden',
			),
			'SlevomatCodingStandard.Namespaces.UseFromSameNamespace' => ['imports.fromCurrentNamespace' => 'forbidden'],
			'SlevomatCodingStandard.Namespaces.UselessAlias' => ['imports.aliasEqualToName' => 'forbidden'],
			'SlevomatCodingStandard.Numbers.DisallowNumericLiteralSeparator' => [
				'literals.digitSeparator.integer' => 'forbidden',
				'literals.digitSeparator.fraction' => 'forbidden',
			],
			'SlevomatCodingStandard.Numbers.RequireNumericLiteralSeparator' => fn(array $o, Translation $t) => $t->setAll([
				'literals.digitSeparator.integer' => 'required',
				'literals.digitSeparator.fraction' => 'required',
				'literals.digitSeparatorMinDigits.integer' => $o['minDigitsBeforeDecimalPoint'] ?? 4,
				'literals.digitSeparatorMinDigits.fraction' => $o['minDigitsAfterDecimalPoint'] ?? 4,
			]),
			'SlevomatCodingStandard.Operators.DisallowEqualOperators' => ['expressions.comparison.loose' => 'forbidden'],
			'SlevomatCodingStandard.Operators.NegationOperatorSpacing' => fn(array $o, Translation $t) => $t->set(
				'spacing.logicalNot',
				($o['spacesCount'] ?? 0) === 0 ? 'compact' : 'spaced',
			),
			'SlevomatCodingStandard.Operators.RequireCombinedAssignmentOperator' => ['expressions.assignment.targetInValue' => 'forbidden'],
			'SlevomatCodingStandard.Operators.SpreadOperatorSpacing' => ['spacing.spread' => 'compact'],
			'SlevomatCodingStandard.PHP.DisallowDirectMagicInvokeCall' => ['hygiene.__invokeCall' => 'forbidden'],
			'SlevomatCodingStandard.PHP.OptimizedFunctionsWithoutUnpacking' => fn(array $o, Translation $t) => $t->markCallsOptimized(),
			'SlevomatCodingStandard.PHP.ReferenceSpacing' => ['spacing.reference' => 'compact'],
			'SlevomatCodingStandard.PHP.RequireExplicitAssertion' => ['phpdoc.annotation.inlineVar' => 'forbidden'],
			'SlevomatCodingStandard.PHP.RequireNowdoc' => ['literals.heredocWithoutInterpolation' => 'forbidden'],
			'SlevomatCodingStandard.PHP.ShortList' => ['literals.arraySyntax.list' => 'short'],
			'SlevomatCodingStandard.PHP.TypeCast' => ['expressions.cast' => 'canonical'],
			'SlevomatCodingStandard.PHP.UselessSemicolon' => ['controlFlow.emptyStatement' => 'forbidden'],
			'SlevomatCodingStandard.TypeHints.DeclareStrictTypes' => function (array $o, Translation $t) {
				if (($o['spacesCountAroundEqualsSign'] ?? 1) !== 0) {
					$t->warn('`SlevomatCodingStandard.TypeHints.DeclareStrictTypes` with spaces around the equals sign has no equivalent; DressCode writes `declare(strict_types=1)` without spaces.');
				}
				$t->setAll([
					'file.strictTypes.declaration' => 'required',
					'file.strictTypes.position' => ($o['declareOnFirstLine'] ?? false) ? 'sameLine' : 'nextLine',
				]);
			},
			'SlevomatCodingStandard.TypeHints.DisallowArrayTypeHintSyntax' => fn(array $o, Translation $t) => $t->set(
				'phpdoc.types.array',
				'generic',
			),
			'SlevomatCodingStandard.TypeHints.DNFTypeHintFormat' => $typeFormat('SlevomatCodingStandard.TypeHints.DNFTypeHintFormat'),
			'SlevomatCodingStandard.TypeHints.LongTypeHints' => fn(array $o, Translation $t) => $t->set(
				'phpdoc.types.builtin',
				'canonical',
			),
			'SlevomatCodingStandard.TypeHints.NullTypeHintOnLastPosition' => fn(array $o, Translation $t) => $t->set(
				'phpdoc.types.nullPosition',
				'last',
			),
			'SlevomatCodingStandard.TypeHints.NullableTypeForNullDefaultValue' => ['upgrading.php.implicitNullable' => 'forbidden'],
			'SlevomatCodingStandard.TypeHints.ParameterTypeHint' => fn(array $o, Translation $t) => $t
				->setAll(array_filter([
					'types.declaration.parameter' => 'required',
					'phpdoc.annotation.nativeTypeOnly' => 'forbidden',
					'phpdoc.annotation.itemTypes' => 'required',
					'types.traversableClasses' => $o['traversableTypeHints'] ?? null,
				], fn($v) => $v !== null)),
			'SlevomatCodingStandard.TypeHints.ParameterTypeHintSpacing' => [
				'spacing.typeDeclaration' => 'compact',
			],
			'SlevomatCodingStandard.TypeHints.PropertyTypeHint' => fn(array $o, Translation $t) => $t
				->setAll(array_filter([
					'types.declaration.property' => 'required',
					'phpdoc.annotation.nativeTypeOnly' => 'forbidden',
					'phpdoc.annotation.itemTypes' => 'required',
					'types.traversableClasses' => $o['traversableTypeHints'] ?? null,
				], fn($v) => $v !== null)),
			'SlevomatCodingStandard.TypeHints.ReturnTypeHint' => fn(array $o, Translation $t) => $t
				->setAll(array_filter([
					'types.declaration.return' => 'required',
					'types.declaration.anonymousFunction' => ($o['enableClosure'] ?? true) ? 'required' : null,
					'phpdoc.annotation.nativeTypeOnly' => 'forbidden',
					'phpdoc.annotation.itemTypes' => 'required',
					'types.traversableClasses' => $o['traversableTypeHints'] ?? null,
				], fn($v) => $v !== null)),
			'SlevomatCodingStandard.TypeHints.ReturnTypeHintSpacing' => [
				'spacing.typeDeclaration' => 'compact',
			],
			'SlevomatCodingStandard.TypeHints.UnionTypeHintFormat' => $typeFormat('SlevomatCodingStandard.TypeHints.UnionTypeHintFormat'),
			'SlevomatCodingStandard.TypeHints.UselessConstantTypeHint' => ['phpdoc.annotation.constantVar' => 'forbidden'],
			'SlevomatCodingStandard.Variables.DuplicateAssignmentToVariable' => ['mistakes.repeatedAssignment' => 'forbidden'],
			'Squiz.Arrays.ArrayBracketSpacing' => ['spacing.padding.offset' => 'forbidden'],
			'Squiz.Classes.SelfMemberReference' => ['qualification.currentClass' => 'self'],
			'Squiz.Classes.ValidClassName' => fn(array $o, Translation $t) => $t->set('naming.class', 'PascalCase'),
			'Squiz.Commenting.DocCommentAlignment' => ['phpdoc.stars' => 'aligned'],
			'Squiz.Commenting.FunctionComment.DuplicateReturn' => ['phpdoc.annotation.duplicateReturn' => 'forbidden'],
			'Squiz.Commenting.FunctionComment.ExtraParamComment' => ['phpdoc.annotation.extraParam' => 'forbidden'],
			'Squiz.Commenting.VariableComment' => fn(array $o, Translation $t) => $t->setAll([
				'phpdoc.property.plainComment' => 'forbidden',
				'phpdoc.annotation.duplicateVar' => 'forbidden',
				'phpdoc.annotation.empty' => 'forbidden',
			]),
			'Squiz.ControlStructures.ControlSignature' => fn(array $o, Translation $t) => $t->setAll([
				'braces.position.controlStructure' => 'sameLine',
				'braces.position.continuingKeyword' => 'sameLine',
				'spacing.keyword' => 'required',
				'spacing.openBrace' => 'required',
			]),
			'Squiz.ControlStructures.ForEachLoopDeclaration' => function (array $o, Translation $t) {
				if (($o['requiredSpacesAfterOpen'] ?? 0) > 0 || ($o['requiredSpacesBeforeClose'] ?? 0) > 0) {
					$t->warn('`Squiz.ControlStructures.ForEachLoopDeclaration` with spaces inside the parentheses has no equivalent; DressCode writes none.');
				}
				$t->warn('`Squiz.ControlStructures.ForEachLoopDeclaration` also puts a single space around the `=>` of a `foreach`, which DressCode decides for every binary operator together, by `spacing.binaryOperator`.');
				$t->setAll([
					...Translation::NoSpaceInParentheses,
					'spacing.keyword' => 'required',
					'casing.keyword' => 'lowercase',
				]);
			},
			'Squiz.ControlStructures.ForLoopDeclaration' => function (array $o, Translation $t) {
				if (($o['requiredSpacesAfterOpen'] ?? 0) > 0 || ($o['requiredSpacesBeforeClose'] ?? 0) > 0) {
					$t->warn('`Squiz.ControlStructures.ForLoopDeclaration` with spaces inside the parentheses has no equivalent; DressCode writes none.');
				}
				$t->setAll([
					...Translation::NoSpaceInParentheses,
					'spacing.semicolon.before' => 'compact',
					'spacing.semicolon.after' => 'spaced',
				]);
			},
			'Squiz.ControlStructures.LowercaseDeclaration' => ['casing.keyword' => 'lowercase'],
			'Squiz.Functions.FunctionDeclaration' => ['spacing.keyword' => 'required', 'spacing.call' => 'compact'],
			'Squiz.Functions.FunctionDeclarationArgumentSpacing' => ['spacing.comma' => 'spaced', 'spacing.alignment.comma' => 'tabs'],
			'Squiz.Functions.LowercaseFunctionKeywords' => ['casing.keyword' => 'lowercase'],
			'Squiz.Functions.MultiLineFunctionDeclaration' => [
				'braces.position.function' => 'nextLine',
				'braces.position.multilineSignature' => 'sameLine',
				'braces.position.closure' => 'sameLine',
				'spacing.anonymousFunction' => 'spaced',
				'multiline.items.parameter' => 'perLine',
				'multiline.edges.parameter' => 'apart',
			],
			'Squiz.NamingConventions.ValidFunctionName' => fn(array $o, Translation $t) => $t->setAll([
				'naming.method' => 'camelCase',
				'naming.function' => 'camelCase',
			]),
			'Squiz.NamingConventions.ValidVariableName' => function (array $o, Translation $t) {
				$t->warn('`Squiz.NamingConventions.ValidVariableName` also rules on the underscore prefix of private properties, which DressCode does not.');
				$t->setAll(['naming.property' => 'camelCase', 'naming.variable' => 'camelCase']);
			},
			'Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps' => ['naming.property' => 'camelCase'],
			'Squiz.NamingConventions.ValidVariableName.NotCamelCaps' => ['naming.variable' => 'camelCase'],
			'Squiz.NamingConventions.ValidVariableName.StringNotCamelCaps' => ['naming.variable' => 'camelCase'],
			'Squiz.Operators.ValidLogicalOperators' => ['expressions.wordLogicalOperator' => 'forbidden'],
			// `print_r` and `var_dump` print what was debugged, `error_log` does not
			'Squiz.PHP.DiscouragedFunctions' => fn(array $o, Translation $t) => $t->setAll([
				'mistakes.debugOutput' => 'forbidden',
				'upgrading.api.forbiddenFunctions' => ['error_log'],
			]),
			'Squiz.PHP.GlobalKeyword' => ['mistakes.globalStatement' => 'forbidden'],
			'Squiz.PHP.InnerFunctions' => ['functions.innerFunction' => 'forbidden'],
			'Squiz.PHP.LowercasePHPFunctions' => ['casing.function' => 'declared'],
			'Squiz.Scope.MethodScope' => ['classes.visibility.constant' => 'required',
				'classes.visibility.property' => 'required',
				'classes.visibility.method' => 'required', 'classes.visibility.interfaceMethod' => 'required'],
			'Squiz.Scope.StaticThisUsage' => ['mistakes.thisOutsideObject' => 'forbidden'],
			'Squiz.Strings.ConcatenationSpacing' => fn(array $o, Translation $t) => $t->set(
				'spacing.concatenation',
				($o['spacing'] ?? 0) > 0 ? 'spaced' : 'compact',
			),
			'Squiz.Strings.DoubleQuoteUsage' => fn(array $o, Translation $t) => $t->set('literals.quotes', 'single'),
			'Squiz.Strings.EchoedStrings' => ['expressions.parenthesesAfterConstruct' => 'forbidden'],
			'Squiz.WhiteSpace.CastSpacing' => ['expressions.cast' => 'canonical'],
			'Squiz.WhiteSpace.ControlStructureSpacing' => function (array $o, Translation $t) {
				$t->setAll([
					'spacing.padding.controlStructure' => 'forbidden',
					'blankLines.bodyStart.controlStructure' => 0,
					'blankLines.bodyEnd.controlStructure' => 0,
					'blankLines.afterStatement' => array_fill_keys(['do', 'for', 'foreach', 'if', 'switch', 'try', 'while'], [1, null]),
				]);
			},
			'Squiz.WhiteSpace.FunctionOpeningBraceSpace' => fn(array $o, Translation $t) => $t->setAll([
				'blankLines.bodyStart.function' => 0,
				'blankLines.bodyStart.closure' => 0,
			]),
			'Squiz.WhiteSpace.FunctionSpacing' => function (array $o, Translation $t) {
				$t->warn('`Squiz.WhiteSpace.FunctionSpacing` leaves the blank lines around classes alone, while `blankLines` sets them together with those around functions.');
				$t->setAll([
					'blankLines.aroundDeclarations' => $o['spacing'] ?? 2,
					'blankLines.aroundMethods' => $o['spacing'] ?? 2,
					'blankLines.aroundInterfaceMethods' => $o['spacing'] ?? 2,
					'blankLines.bodyStart.class' => $o['spacingBeforeFirst'] ?? 2,
					'blankLines.bodyEnd.class' => $o['spacingAfterLast'] ?? 2,
				]);
			},
			'Squiz.WhiteSpace.LogicalOperatorSpacing' => [
				'spacing.binaryOperator' => 'spaced',
				'spacing.alignment.binaryOperator' => 'spaces',
			],
			'Squiz.WhiteSpace.ObjectOperatorSpacing' => ['spacing.objectOperator' => 'compact'],
			'Squiz.WhiteSpace.OperatorSpacing' => ['spacing.binaryOperator' => 'spaced', 'spacing.alignment.binaryOperator' => 'spaces'],
			'Squiz.WhiteSpace.OperatorSpacing.Unary' => ['spacing.unaryOperator' => 'compact', 'spacing.logicalNot' => 'compact'],
			'Squiz.WhiteSpace.ScopeClosingBrace' => fn(array $o, Translation $t) => $t->warn('`Squiz.WhiteSpace.ScopeClosingBrace` puts a closing brace on a line of its own at the indentation of the line opening it, which DressCode does wherever `braces` places the opening brace and `indentation.unit` indents.'),
			'Squiz.WhiteSpace.ScopeKeywordSpacing' => ['spacing.doubleColon' => 'compact'],
			'Squiz.WhiteSpace.SemicolonSpacing' => ['spacing.semicolon.before' => 'compact', 'multiline.semicolon' => 'sameLine'],
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

		$internal = libxml_use_internal_errors(use_errors: true);
		libxml_clear_errors();
		$xml = simplexml_load_file($file);
		$error = libxml_get_errors()[0] ?? null;
		libxml_clear_errors();
		libxml_use_internal_errors($internal);
		if ($xml === false) {
			throw new ConfigurationException("File `$file` is not a PHP_CodeSniffer ruleset" . ($error ? ': ' . trim($error->message) . " on line $error->line." : '.'));
		}

		$rules = $warnings = [];
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

			// the paths a sniff leaves out become an override, which a whole set has no decisions for
			if (isset($rule->{'exclude-pattern'}, self::getSets()[$ref])) {
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


	/**
	 * The suppression comments of PHP_CodeSniffer in the files, which `dresscode migrate suppressions` rewrites.
	 * @param  list<string>  $files  relative to the root
	 */
	public static function countSuppressions(string $root, array $files): int
	{
		$count = 0;
		foreach ($files as $file) {
			$code = (string) @file_get_contents("$root/$file"); // @ file may have been deleted meanwhile
			$count += preg_match_all('~(?://|#|/\*)\s*phpcs:(?:ignoreFile|ignore|disable|enable)\b|@phpcsSuppress\b~', $code);
		}

		return $count;
	}
}
