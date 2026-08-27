<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Presets;

use DressCode\{Preset, PresetInfo, Profile};


/**
 * PSR-12 Extended Coding Style, section by section; the specification defines the style and this preset
 * adds nothing of its own.
 */
#[PresetInfo('dresscode/psr12', 'PSR-12 Extended Coding Style')]
final class Psr12 implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(
			indent: 4,
			lineEnding: 'majority',
			lineLength: 120,
			rules: [
				// 2. General: files, lines, indenting, keywords and types
				'noBom' => true,
				'fullOpeningTag' => true,
				'lineEnding' => true,
				'eofLineEnding' => true,
				'noClosingTag' => true,
				'noTrailingWhitespace' => true,
				'singleStatementPerLine' => true,
				'indentation' => true,
				'keywordCasing' => true,
				'trueFalseNullCasing' => true,
				'castSpacing' => true,
				'castCanonicalType' => true,

				// 2.1 Basic coding standard: PSR-1 3 and 4 on the case of names
				'nameCasing' => ['class' => 'PascalCase', 'method' => 'camelCase', 'constant' => 'UPPER_CASE'],

				// 3. Declare statements, namespace and import statements
				'orderedImports' => ['order' => 'byKind'],
				'uselessImportBackslash' => true,
				'declareSpacing' => true,

				// 4. Classes, properties and methods
				'newArgumentParentheses' => ['anonymousClass' => 'keep'],
				'classDefinitionSpacing' => true,
				'bracesPosition' => ['singlelineAnonymousFunction' => 'always'],
				// PSR-12 asks for blank lines in the header and none between the members it names; what it says
				// nothing about is left as it is
				'blankLines' => [
					'betweenDeclarations' => 'keep', 'betweenMethods' => 'keep', 'betweenInterfaceMethods' => 'keep',
					'betweenMembers' => 'keep', 'beforeDocumentedMember' => 'keep', 'afterPhpdoc' => 'keep',
					'afterBlockOpeningBrace' => 'keep', 'before' => [],
				],
				'orderedMembers' => ['order' => ['traitUse']],
				'visibilityRequired' => true,
				'singleMemberPerDeclaration' => ['members' => ['property', 'trait']],
				'functionNameSpacing' => true,
				'parenthesesSpacing' => true,
				'commaSpacing' => ['alignment' => 'none'],
				'multilineSignature' => ['promotedProperty' => 'keep'],
				'typeHintSpacing' => ['catch' => 'single'],
				'referenceSpacing' => true,
				'spreadOperatorSpacing' => true,
				'multilineCall' => true,

				// 5. Control structures; what a return gives may begin below it, which the specification leaves open
				'constructSpacing' => ['allowMultilineExpression' => true],
				'controlStructureBraces' => true,
				'elseifKeyword' => true,
				'multilineCondition' => true,
				'switchCaseColon' => true,
				'switchCaseSpacing' => true,
				'fallThroughComment' => true,

				// 6. Operators
				'unaryOperatorSpacing' => true,
				'binaryOperatorSpacing' => true,
				'ternaryOperatorSpacing' => true,

				// 11. Arrays
				'shortArraySyntax' => true,
			],
		);
	}
}
