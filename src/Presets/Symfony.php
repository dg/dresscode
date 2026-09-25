<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Presets;

use DressCode\{Preset, PresetInfo, Profile};


/**
 * The Symfony Coding Standards as the @Symfony rule set of PHP CS Fixer defines them, without most of its rules
 * about phpDoc, of which it takes only an empty doc comment, the trimming and the canonical types: PER, which the
 * set builds on, with the casing, the imports, the blank lines and the
 * whitespace Symfony adds, and without the rules that would break a construct over lines, which Symfony
 * leaves to the author.
 */
#[PresetInfo('dresscode/symfony', 'Symfony Coding Standards as the `@Symfony` rule set defines them, without most of its phpDoc rules')]
final class Symfony implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(
			presets: [PerCs::class],
			indent: 4,
			lineEnding: 'majority',
			lineLength: false,
			rules: [
				// a construct keeps the lines it is written on: method_argument_space says on_multiline=ignore,
				// and no fixer of the set breaks a condition, a chain, an array or a ternary
				'multilineCall' => false,
				'multilineSignature' => false,
				'multilineCondition' => false,
				'multilineChain' => false,
				'multilineArray' => false,
				'multilineTernary' => false,
				'indentation' => ['chain' => 'keep', 'binary' => 'keep'],

				// heredoc_to_nowdoc and heredoc_indentation belong to other sets
				'nowdocWithoutInterpolation' => false,
				'heredocIndentation' => false,

				// casing
				'nativeClassCasing' => true,
				'magicConstantCasing' => true,
				'nativeFunctionCasing' => true,

				// imports
				'importNotation' => true,
				// global_namespace_import imports nothing: a class is written fully qualified, a function and a constant too
				// where they are qualified, and a bare one stays bare
				'nameNotation' => ['globalClass' => 'backslash', 'globalFunction' => 'backslash', 'globalConstant' => 'backslash'],
				'orderedImports' => ['order' => 'byName', 'caseSensitive' => false],
				'unusedImports' => true,

				// comments and phpdoc
				'commentSpacing' => true,
				'noEmptyComments' => true,
				'noHashComments' => true,
				'noEmptyPhpdocs' => true,
				'phpdocCanonicalTypes' => ['arrayNotation' => 'keep'],
				'phpdocTrim' => true,

				// blank lines

				'blankLines' => [
					'betweenDeclarations' => [0, 1], 'betweenMethods' => 1, 'betweenInterfaceMethods' => 1, 'beforeFirstMethod' => 0, 'afterLastMethod' => 0,
					'beforeFirstMember' => 0, 'afterLastMember' => 0, 'betweenTraitUses' => 'keep',
					'afterTraitUses' => 'keep', 'betweenMembers' => 'keep', 'beforeDocumentedMember' => 'keep', 'afterPhpdoc' => 0,
					'before' => ['return' => [1, null]],
				],

				// the whitespace of a line
				'arraySpacing' => true,
				'commaSpacing' => ['alignment' => 'keep'],
				'objectOperatorSpacing' => true,
				'offsetBracketSpacing' => true,
				'semicolonSpacing' => ['after' => 'single'],
				'classDefinitionSpacing' => ['beforeParenthesis' => 'none'],
				'constructSpacing' => ['arrowFunction' => 'single'],
				'declareSpacing' => true,
				'bracesPosition' => [
					'class' => 'nextLine', 'anonymousClass' => 'sameLine', 'anonymousFunction' => 'sameLine',
					'controlStructure' => 'sameLine', 'singlelineAnonymousFunction' => 'keep', 'emptyAnonymousClass' => 'sameLine',
					// single_line_empty_body is switched off in @Symfony
					'emptyBody' => 'ownLine',
				],

				// operators
				'binaryOperatorSpacing' => ['alignment' => 'none'],
				'concatSpacing' => ['spacing' => 'none'],
				'unaryOperatorSpacing' => true,
				'incrementForAddOne' => true,
				'notEqualsNotation' => true,
				'noShortBoolCasts' => true,

				// literals and strings
				'stringQuotes' => 'single',
				'complexStringVariable' => true,
				'noBacktickOperators' => true,

				// control structures
				'noAlternativeSyntax' => true,
				'noContinueInSwitch' => true,
				'noEmptyStatements' => true,
				'uselessBraces' => true,
				'uselessConstructParentheses' => true,
				'uselessElse' => true,
				'uselessReturn' => true,

				// classes, arrays and types
				'uselessNullInitialization' => true,
				'nullableTypeForDefaultNull' => true,
				'typeHintSpacing' => ['catch' => 'none'],
				'trailingComma' => ['argument' => 'optional', 'closureUse' => 'keep'],
			],
		);
	}
}
