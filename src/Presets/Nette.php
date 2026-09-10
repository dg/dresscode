<?php declare(strict_types=1);

namespace DressCode\Presets;

use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;


/**
 * The Nette Coding Standard: PER with tabs, where it departs from PER (the opening tag carries the declare,
 * two blank lines between methods, constants in PascalCase, the first link of a chain and several items of
 * an array may share a line, the brace of a multi-line signature goes below a return type) and with the rules
 * about imports, names, PHPDoc and modern syntax the Nette libraries follow.
 */
#[PresetInfo('dresscode/nette', 'Nette Coding Standard', indent: 'tab', eol: 'majority')]
final class Nette implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [
			// the file: the declare on the line of the opening tag, no invisible characters
			'strict-types-required' => ['placement' => 'openingTagLine'],
			'no-invisible-characters' => true,

			// the header: imports in one block, one blank line between the blocks and before a statement that follows
			// them, two before a declaration
			'header-blank-lines' => [
				'beforeNamespace' => 1, 'afterOpeningTag' => 'keep', 'afterNamespace' => 1, 'afterImports' => 1, 'betweenImportGroups' => 0, 'beforeDeclaration' => 2,
			],
			'ordered-imports' => ['order' => 'alphabetical'],
			'unused-imports' => true,
			'reference-used-names-only' => true,
			'import-notation' => ['functions' => 'combined', 'constants' => 'combined', 'groupUse' => 'keep'],
			'useless-alias' => true,
			'use-from-same-namespace' => true,
			'class-reference-name-casing' => true,
			'no-leading-backslash-in-global-namespace' => true,

			// blank lines: two between methods, none inside a body's braces
			'declaration-blank-lines' => [
				'betweenFunctions' => 2, 'betweenFunctionsInInterface' => 1, 'betweenMembers' => [0, 1],
				'beforeDocumentedMember' => 1, 'afterPhpDoc' => 0,
			],
			'body-blank-lines' => true,

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
			'multi-line-signature' => ['promotedProperties' => 'ownLines'],
			'multi-line-chain' => ['leadingLinks' => 'sameLine'],
			'multi-line-array' => ['shape' => 'keep'],
			'multi-line-condition' => ['shape' => ['perLine', 'compact']],
			'trailing-comma' => ['multiLine' => ['arrays', 'arguments', 'parameters']],

			// names and declarations
			'name-casing' => [
				'classes' => 'PascalCase', 'methods' => 'camelCase', 'functions' => 'camelCase', 'constants' => 'PascalCase',
				'enumCases' => 'PascalCase', 'properties' => 'camelCase', 'variables' => 'camelCase',
			],
			'ordered-members' => ['order' => [
				'use_trait', 'constant', 'constant_public', 'constant_protected', 'constant_private',
				'property_public', 'property_protected', 'property_private',
			]],
			'useless-modifier' => true,
			'useless-null-property-initialization' => true,
			'self-for-current-class' => true,
			'modern-class-name-reference' => ['onObjects' => true],
			'no-this-in-static-context' => true,
			'new-argument-parentheses' => ['namedClasses' => 'forbidden', 'anonymousClasses' => 'forbidden'],
			'nullable-type-for-default-null' => true,
			'useless-parameter-default' => true,
			'no-inner-functions' => true,
			'no-global-keyword' => true,

			// expressions and literals
			'not-equals-operator' => true,
			'no-yoda-comparison' => true,
			'explicit-operator-precedence' => true,
			'useless-parentheses-around-new' => true,
			'no-short-bool-cast' => true,
			'increment-operator' => true,
			'combined-assignment-operator' => true,
			'symbolic-logical-operators' => true,
			'short-ternary-operator' => true,
			'useless-ternary-operator' => true,
			'null-coalescing-operator' => true,
			'magic-constant-casing' => true,
			'single-quoted-strings' => true,
			'no-trailing-whitespace-in-string' => true,
			'complex-string-variable' => true,
			'no-implicit-backslash' => true,
			'no-backtick-operator' => true,
			'useless-string-concat' => true,
			'octal-notation' => true,
			'numeric-literal-separator' => ['minDigitsBeforeDecimalPoint' => 7, 'minDigitsAfterDecimalPoint' => 20],
			'combined-unsets' => true,
			'combined-issets' => true,
			'no-duplicate-assignment' => true,

			// control flow: a fall-through in a switch says "break omitted"
			'no-empty-statement' => true,
			'useless-construct-parentheses' => true,
			'useless-braces' => true,
			'useless-return' => true,
			'useless-catch-variable' => true,
			'useless-if-condition-with-return' => true,
			'fall-through-comment' => ['comment' => 'break omitted'],
			'no-alternative-syntax' => true,
			'no-continue-in-switch' => true,
			'no-unreachable-catch' => true,
			'reference-throwable-only' => true,

			// functions
			'native-function-casing' => true,
			'arrow-function' => true,
			'strict-call' => true,
			'no-is-null' => true,
			'no-conversion-functions' => true,
			'no-dirname-of-file' => true,
			'no-alias-functions' => true,
			'no-settype' => true,
			'no-deprecated-functions' => true,
			'no-unpacking-in-optimized-call' => true,

			// comments and PHPDoc: both notations of an array type stay as they are
			'no-empty-comment' => true,
			'no-hash-comment' => true,
			'commented-out-function' => ['functions' => ['print_r', 'var_dump', 'var_export', 'dump']],
			'no-empty-phpdoc' => true,
			'phpdoc-trim' => true,
			'phpdoc-canonical-types' => ['arrayNotation' => 'keep'],
			'phpdoc-null-last' => true,
			'phpdoc-alignment' => true,
			'annotation-name' => true,
			'explicit-assertion' => true,
			'useless-inheritdoc' => true,
			'useless-function-phpdoc' => true,
			'useless-constant-var-annotation' => true,
			'property-phpdoc-single-line' => true,
			'property-phpdoc-required' => true,
			'property-var-annotation' => true,
			'promoted-property-annotation-position' => true,
			'no-empty-var-annotation' => true,
			'no-duplicate-return-annotation' => true,
			'no-unknown-param-annotation' => true,
			'forbidden-annotations' => [
				'annotations' => ['@access', '@author', '@copyright', '@created', '@license', '@package', '@since', '@subpackage', '@todo', '@version'],
			],
			'forbidden-phpdoc-lines' => [
				'patterns' => ['~^(?:(?!private|protected|static)\S+ )?(?:con|de)structor\.\z~i', '~^Created by \S+\.\z~i', '~^\S+ [gs]etter\.\z~i'],
			],
		];
	}


	public function getParents(): array
	{
		return [Per::class];
	}
}
