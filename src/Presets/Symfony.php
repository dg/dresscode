<?php declare(strict_types=1);

namespace DressCode\Presets;

use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;


/**
 * The Symfony Coding Standards as the @Symfony rule set of PHP CS Fixer defines them, without the rules
 * about PHPDoc: PER, which the set builds on, with the casing, the imports, the blank lines and the
 * whitespace Symfony adds, and without the rules that would break a construct over lines, which Symfony
 * leaves to the author.
 */
#[PresetInfo(
	'dresscode/symfony',
	'Symfony Coding Standards as the @Symfony rule set defines them, without the phpDoc rules',
	indent: 4,
	eol: 'majority',
)]
final class Symfony implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [
			// a construct keeps the lines it is written on: method_argument_space says on_multiline=ignore,
			// and no fixer of the set breaks a condition, a chain, an array or a ternary
			'multi-line-call' => false,
			'multi-line-signature' => false,
			'multi-line-condition' => false,
			'multi-line-chain' => false,
			'multi-line-array' => false,
			'multi-line-ternary' => false,
			'indentation' => ['chain' => null, 'binary' => null],

			// heredoc_to_nowdoc and heredoc_indentation belong to other sets
			'nowdoc-without-interpolation' => false,
			'heredoc-indentation' => false,

			// casing
			'class-reference-name-casing' => true,
			'magic-constant-casing' => true,
			'native-function-casing' => true,

			// imports
			'import-notation' => true,
			'ordered-imports' => ['alphabetically' => true, 'caseSensitive' => false],
			'unused-imports' => true,

			// comments and phpdoc
			'comment-spacing' => true,
			'no-empty-comment' => true,
			'no-hash-comment' => true,
			'no-empty-phpdoc' => true,
			'phpdoc-canonical-types' => ['arrayNotation' => null],
			'phpdoc-trim' => true,

			// blank lines
			'header-blank-lines' => true,
			'declaration-blank-lines' => [
				'betweenFunctions' => 1, 'betweenFunctionsInInterface' => 1, 'beforeFirst' => 0, 'afterLast' => 0,
				'afterOpeningBrace' => 0, 'beforeClosingBrace' => 0, 'betweenTraitUses' => null,
				'afterTraitUses' => null, 'betweenMembers' => null, 'beforeDocumentedMember' => null, 'afterPhpDoc' => 0,
			],
			'statement-blank-lines' => ['before' => ['return' => [1, null]]],

			// the whitespace of a line
			'array-spacing' => true,
			'comma-spacing' => ['tabAlignment' => true],
			'object-operator-spacing' => true,
			'offset-bracket-spacing' => true,
			'semicolon-spacing' => ['after' => 'single'],
			'class-definition-spacing' => ['spaceBeforeParenthesis' => false],
			'construct-spacing' => ['arrowFunction' => 'single'],
			'declare-spacing' => true,
			'braces-position' => [
				'classes' => 'nextLine', 'anonymousClasses' => 'sameLine', 'anonymousFunctions' => 'sameLine',
				'controlStructures' => 'sameLine', 'allowSingleLineAnonymousFunctions' => true, 'emptyAnonymousClasses' => 'sameLine',
				// single_line_empty_body is switched off in @Symfony
				'emptyBodies' => 'ownLine',
			],

			// operators
			'binary-operator-spacing' => ['spacing' => 'single'],
			'concat-spacing' => ['spacing' => 'none'],
			'unary-operator-spacing' => true,
			'increment-operator' => true,
			'not-equals-operator' => true,
			'no-short-bool-cast' => true,

			// literals and strings
			'single-quoted-strings' => true,
			'complex-string-variable' => true,
			'no-backtick-operator' => true,

			// control structures
			'no-alternative-syntax' => true,
			'no-continue-in-switch' => true,
			'no-empty-statement' => true,
			'useless-braces' => true,
			'useless-construct-parentheses' => true,
			'useless-else' => true,
			'useless-return' => true,

			// classes, arrays and types
			'useless-null-property-initialization' => true,
			'nullable-type-for-default-null' => true,
			'type-hint-spacing' => ['catchTypes' => 'none'],
			'trailing-comma' => [
				'multiLine' => ['arrays', 'match', 'parameters'],
				'singleLine' => true,
			],
		];
	}


	public function getParents(): array
	{
		return [Per::class];
	}
}
