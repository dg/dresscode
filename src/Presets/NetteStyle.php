<?php declare(strict_types=1);

namespace DressCode\Presets;

use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;


/**
 * The layout of the Nette Coding Standard, which is PER with tabs and the places where it departs from it:
 * the declare on the line of the opening tag, two blank lines between methods, none inside the braces of
 * a body, a tab that may align commas, the brace of a multi-line signature below a return type, promoted
 * properties on lines of their own, and a chain, an array or a condition that may keep the shape it has.
 * It says nothing about which rules a project runs, so it composes with any set of them.
 */
#[PresetInfo('dresscode/nette-style', 'The layout of the Nette Coding Standard', indent: 'tab', eol: 'majority')]
final class NetteStyle implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [
			// the file: the declare on the line of the opening tag
			'strict-types-required' => ['placement' => 'openingTagLine'],

			// the header: imports in one block, one blank line between the blocks and before a statement that follows
			// them, two before a declaration
			'blank-lines' => [
				'beforeNamespace' => 1, 'afterOpeningTag' => 'keep', 'afterNamespace' => 1, 'afterImports' => 1, 'betweenImportGroups' => 0, 'beforeDeclaration' => 2,
				'betweenFunctions' => 2, 'betweenFunctionsInInterface' => 1, 'betweenMembers' => [0, 1],
				'beforeDocumentedMember' => 1, 'afterPhpDoc' => 0, 'afterBlockBrace' => 0,
			],

			// the whitespace of a line: exactly one space around a ternary, a tab may align commas, a space after the
			// slashes of a comment
			'ternary-operator-spacing' => ['spacing' => 'single'],
			'comment-spacing' => true,
			'semicolon-spacing' => ['after' => 'single'],
			'comma-spacing' => ['alignment' => 'tabs'],
			'object-operator-spacing' => true,
			'double-colon-spacing' => true,
			'array-spacing' => true,
			'offset-bracket-spacing' => true,
			'class-definition-spacing' => ['beforeParenthesis' => 'single'],

			// breaks: the brace of a multi-line signature below its return type, promoted properties on lines of their
			// own, the first link of a chain and several items of an array may share a line, and a broken condition
			// may begin on the line of its parenthesis or below it
			'braces-position' => [
				'multiLineParameters' => 'nextLineAfterReturnType', 'emptyBodies' => 'ownLine',
				'singleLineAnonymousFunctions' => 'allowed',
			],
			// a link of a chain that returns something else than the link before it stands one level deeper
			'indentation' => ['chain' => 'nesting'],
			'multi-line-signature' => ['promotedProperties' => 'ownLines'],
			'multi-line-chain' => ['leadingLinks' => 'sameLine'],
			'multi-line-array' => ['shape' => 'keep'],
			'multi-line-condition' => ['shape' => ['perLine', 'compact']],
			'trailing-comma' => ['multiLine' => ['arrays', 'arguments', 'parameters']],
			'phpdoc-alignment' => true,
		];
	}


	public function getParents(): array
	{
		return [];
	}
}
