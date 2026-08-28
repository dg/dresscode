<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Presets;

use DressCode\{Preset, PresetInfo, Profile};


/**
 * The layout of the Nette Coding Standard, which is PER with tabs and the places where it departs from it:
 * the declare on the line of the opening tag, two blank lines between methods, none inside the braces of
 * a body, one between the branches of a chain and the cases of a switch that have paragraphs, a tab that may
 * align commas, the brace of a multi-line signature below a return type, promoted properties on lines of their
 * own, an expression on the line of its return, an operator at a line break at the start of the next line, and
 * a chain, an array or a condition that may keep the shape it has.
 * It says nothing about which rules a project runs, so it composes with any set of them.
 */
#[PresetInfo('dresscode/nette-style', 'The layout of the Nette Coding Standard')]
final class NetteStyle implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(
			indent: 'tab',
			eol: 'majority',
			rules: [
				// the file: the declare on the line of the opening tag
				'strict-types-required' => ['placement' => 'openingTagLine'],

				// the header: imports in one block, one blank line between the blocks and before a statement that follows
				// them, two before a declaration; one or two around a declaration among statements; the branches of if
				// or try and the cases of a switch set apart where one of them is split into paragraphs
				'blank-lines' => [
					'beforeNamespace' => 1, 'afterOpeningTag' => 'keep', 'afterNamespace' => 1, 'afterImports' => 1, 'betweenImportGroups' => 0, 'beforeDeclaration' => 2,
					'betweenDeclarations' => [1, 2], 'betweenMethods' => 2, 'betweenMethodsInInterface' => 1, 'betweenMembers' => [0, 1],
					'beforeDocumentedMember' => 1, 'afterPhpDoc' => 0, 'afterBlockBrace' => 0,
					'betweenBranches' => 'paragraphed', 'betweenCases' => 'paragraphed',
				],

				// the whitespace of a line: exactly one space around a ternary, a tab may align commas, a space after the
				// slashes of a comment
				'ternary-operator-spacing' => ['spacing' => 'single', 'operatorPosition' => 'start'],
				'comment-spacing' => true,
				'semicolon-spacing' => ['after' => 'single', 'allowOwnLine' => false],
				'comma-spacing' => ['alignment' => 'tabs'],
				'object-operator-spacing' => true,
				'double-colon-spacing' => true,
				'array-spacing' => true,
				'offset-bracket-spacing' => true,
				'class-definition-spacing' => ['beforeParenthesis' => 'single'],

				// breaks: the brace of a multi-line signature below its return type, promoted properties on lines of
				// their own, the first link of a chain and several items of an array may share a line, an array
				// wider than 130 characters is spread, a broken condition may begin on the line of its parenthesis
				// or below it, an operator at a line break begins the next line, and an expression begins on the
				// line of its return
				'binary-operator-spacing' => ['operatorPosition' => 'start'],
				'concat-spacing' => ['operatorPosition' => 'start'],
				'construct-spacing' => ['allowMultiLineExpression' => false],
				'braces-position' => [
					'multiLineParameters' => 'nextLineAfterReturnType', 'emptyBodies' => 'ownLine',
					'singleLineAnonymousFunctions' => 'allowed',
				],
				// a link of a chain that returns something else than the link before it stands one level deeper
				'indentation' => ['chain' => 'nesting'],
				'multi-line-signature' => ['promotedProperties' => 'ownLines'],
				'multi-line-chain' => ['leadingLinks' => 'sameLine'],
				'multi-line-array' => ['shape' => 'keep', 'maxWidth' => 130],
				'multi-line-condition' => ['shape' => ['perLine', 'compact'], 'operatorPosition' => 'start'],
				'trailing-comma' => ['multiLine' => ['arrays', 'arguments', 'parameters']],
				'phpdoc-alignment' => true,
			],
		);
	}
}
