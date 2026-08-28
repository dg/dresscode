<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Presets;

use DressCode\{Preset, PresetInfo, Profile, RuleGroup};


/**
 * The Nette Coding Standard: PER Coding Style with tabs and the layout of the Nette libraries, everything the groups
 * of hygiene ask for, and the choices the Nette libraries make: strict types declared on the line of the opening tag,
 * how names are cased, in what order the members of a class stand, which functions and constructs are out, and what
 * a doc comment of theirs holds. The layout departs from PER Coding Style in two blank lines between methods, none
 * inside the braces of a body, one closing a branch or a case another one follows when its last statement stands
 * apart, a tab that may align commas, the brace of a multi-line signature below a return type, promoted properties
 * on lines of their own, an expression on the line of its return, an operator at a line break at the start of the
 * next line, and a chain, an array or a condition that may keep the shape it has.
 */
#[PresetInfo('dresscode/nette', 'Nette Coding Standard')]
final class Nette implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(
			indent: 'tab',
			lineEnding: 'majority',
			lineLength: 140,
			presets: [
				PerCs::class,
			],
			groups: [
				RuleGroup::Cleanup,
				RuleGroup::Types,
				RuleGroup::Correctness,
			],
			rules: [
				// the header: imports in one block, one blank line between the blocks and before a statement that follows
				// them, two before a declaration; one or two around a declaration among statements; one closing a branch
				// of if or try or a case of a switch that another one follows, when its last statement stands apart
				'blankLines' => [
					'beforeNamespace' => 1, 'afterOpeningTag' => 'keep', 'afterNamespace' => 1, 'afterImports' => 1, 'betweenImportGroups' => 0, 'beforeDeclaration' => 2,
					'betweenDeclarations' => [1, 2], 'betweenMethods' => 2, 'betweenInterfaceMethods' => 1, 'betweenMembers' => [0, 1],
					'beforeDocumentedMember' => 1, 'afterPhpdoc' => 0, 'afterBlockOpeningBrace' => 0,
					'betweenBranches' => 'lastSetApart', 'betweenCases' => 'lastSetApart',
				],

				// the whitespace of a line: exactly one space around a ternary, a tab may align commas, a space after the
				// slashes of a comment
				'ternaryOperatorSpacing' => ['spacing' => 'single', 'operatorPosition' => 'start'],
				'commentSpacing' => true,
				'semicolonSpacing' => ['after' => 'single', 'allowOwnLine' => false],
				'commaSpacing' => ['alignment' => 'tabs'],
				'objectOperatorSpacing' => true,
				'doubleColonSpacing' => true,
				'arraySpacing' => true,
				'offsetBracketSpacing' => true,
				'classDefinitionSpacing' => ['beforeParenthesis' => 'single'],

				// breaks: the brace of a multi-line signature below its return type, promoted properties on lines of
				// their own, the first link of a chain and several items of an array may share a line, an array
				// wider than 130 characters is spread, a broken condition may begin on the line of its parenthesis
				// or below it, an operator at a line break begins the next line, and an expression begins on the
				// line of its return
				'binaryOperatorSpacing' => ['operatorPosition' => 'start'],
				'concatSpacing' => ['operatorPosition' => 'start'],
				'constructSpacing' => ['allowMultilineExpression' => false],
				'bracesPosition' => [
					'multilineParameters' => 'nextLineAfterReturnType', 'emptyBody' => 'ownLine',
					'singlelineAnonymousFunction' => 'keep',
				],
				// a link of a chain that returns something else than the link before it stands one level deeper
				'indentation' => ['chain' => 'nesting'],
				'multilineSignature' => ['promotedProperty' => 'always'],
				'multilineChain' => ['leadingLinks' => 'sameLine'],
				'multilineArray' => ['shape' => 'keep', 'maxWidth' => 130],
				'multilineCondition' => ['shape' => ['perLine', 'compact'], 'operatorPosition' => 'start'],
				'trailingComma' => ['matchArm' => 'keep', 'closureUse' => 'keep'],
				'phpdocAlignment' => true,

				'noInvisibleCharacters' => true,

				// the file: strict types, declared on the line of the opening tag
				'strictTypesRequired' => ['placement' => 'openingTagLine'],

				// what the groups do not carry, because it is a decision of the standard and not of its kind
				'annotationCasing' => true,
				'phpdocNullPosition' => true,
				'phpdocTrim' => true,
				'selfForCurrentClass' => true,

				// the imports: one block, sorted, functions and constants of a namespace in one statement, a class of another namespace imported
				'orderedImports' => ['order' => 'byName'],
				'importNotation' => ['function' => 'combined', 'constant' => 'combined', 'group' => 'keep'],
				'nameNotation' => ['class' => 'import', 'globalClass' => 'keep'],
				'nativeClassCasing' => true,

				// names and declarations
				'nameCasing' => [
					'class' => 'PascalCase', 'method' => 'camelCase', 'function' => 'camelCase', 'constant' => 'PascalCase',
					'enumCase' => 'PascalCase', 'property' => 'camelCase', 'variable' => 'camelCase',
				],
				'kindInClassName' => 'forbidden',
				'orderedMembers' => ['order' => [
					'traitUse', 'constant', 'publicConstant', 'protectedConstant', 'privateConstant',
					'publicProperty', 'protectedProperty', 'privateProperty',
				]],
				'getClassNotation' => ['onObjects' => true],
				'newArgumentParentheses' => ['namedClass' => 'forbidden', 'anonymousClass' => 'forbidden'],
				'noInnerFunctions' => true,
				'noGlobalStatements' => true,

				// expressions and literals
				'nullCoalescingForNullTernary' => true,
				'shortTernaryForRepeatedCondition' => true,
				'combinedAssignmentForRepeatedTarget' => true,
				'combinedIssets' => true,
				'combinedUnsets' => true,
				'octalNotation' => true,
				'notEqualsNotation' => true,
				'strictComparison' => true,
				'yoda' => 'forbidden',
				'explicitOperatorPrecedence' => true,
				'noShortBoolCasts' => true,
				'incrementForAddOne' => true,
				'symbolicLogicalOperators' => true,
				'magicConstantCasing' => true,
				'stringQuotes' => 'single',
				'noTrailingWhitespaceInString' => true,
				'complexStringVariable' => true,
				'noImplicitBackslashes' => true,
				'noBacktickOperators' => true,
				'numericLiteralSeparator' => ['minIntegerDigits' => 7, 'minFractionDigits' => 20],

				// control flow: a fall-through in a switch says "break omitted", and an else after a return may stay
				'fallThroughComment' => ['comment' => 'break omitted'],
				'noAlternativeSyntax' => true,
				'noContinueInSwitch' => true,
				'referenceThrowableOnly' => true,
				'uselessElse' => 'keep',

				// functions: a parameter or a return without a type is a matter of the library, not of the standard
				'arrowFunction' => true,
				'nullableTypeForDefaultNull' => true,
				'nativeFunctionCasing' => true,
				'noIsNull' => true,
				'noConversionFunctions' => true,
				'noDirnameOfFile' => true,
				'noAliasFunctions' => true,
				'noSettype' => true,
				'noDeprecatedFunctions' => true,
				'noDirectInvokeCalls' => true,
				'typeHintRequired' => 'keep',

				// comments and PHPDoc: both notations of an array type stay as they are
				'noHashComments' => true,
				'commentedOutFunction' => 'keep',
				'phpdocCanonicalTypes' => ['arrayNotation' => 'keep'],
				'explicitAssertion' => true,
				'propertyPhpdocSingleline' => true,
				'propertyPhpdocRequired' => true,
				'promotedPropertyAnnotationPosition' => true,
				'forbiddenAnnotations' => [
					'annotations' => ['@access', '@author', '@copyright', '@created', '@license',
						'@package', '@since', '@subpackage', '@todo', '@version'],
				],
				'forbiddenPhpdocLines' => [
					'patterns' => [
						'~^(?:(?!private|protected|static)\S+ )?(?:con|de)structor\.\z~i',
						'~^Created by \S+\.\z~i',
						'~^\S+ [gs]etter\.\z~i',
					],
				],
			],
		);
	}
}
