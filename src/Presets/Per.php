<?php declare(strict_types=1);

namespace DressCode\Presets;

use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;


/**
 * PER Coding Style in its current version, the default preset: PSR-12 plus what the PER added (trailing
 * commas, short closures, empty bodies as `{}`, attributes, enumerations, heredoc, property hooks); the
 * specification defines the style and this preset adds nothing of its own.
 */
#[PresetInfo('dresscode/per', 'PER Coding Style 3.1', indent: 4, eol: 'majority')]
final class Per implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [
			// 2.6 Trailing commas
			'trailing-comma' => ['multiLine' => ['arrays', 'arguments', 'parameters', 'match', 'closureUses']],

			// 4. Classes, properties and methods: empty bodies, anonymous classes, named arguments, chains
			'class-definition-spacing' => ['spaceBeforeParenthesis' => false],
			'braces-position' => ['allowSingleLineAnonymousFunctions' => false, 'emptyBodies' => 'sameLine'],
			'new-argument-parentheses' => ['namedClasses' => 'required', 'anonymousClasses' => 'forbidden'],
			'named-argument-spacing' => true,
			'multi-line-chain' => true,

			// 5.6 The types of a multi-catch hug their bar, as every compound type does
			'type-hint-spacing' => true,

			// 6. Operators
			'concat-spacing' => true,
			'multi-line-ternary' => true,

			// 7.1 Short closures
			'semicolon-spacing' => ['after' => null],

			// 9. Enumerations: cases in PascalCase, on top of what PSR-1 says about names, each on its own line
			'name-casing' => ['classes' => 'PascalCase', 'methods' => 'camelCase', 'constants' => 'UPPER_CASE', 'enumCases' => 'PascalCase'],
			'single-member-per-line' => true,

			// 10. Heredoc and nowdoc
			'nowdoc-without-interpolation' => true,
			'heredoc-indentation' => true,

			// 11. Arrays
			'multi-line-array' => true,

			// 12. Attributes
			'attribute-spacing' => true,
			'useless-attribute-parentheses' => true,
			'attribute-position' => true,
			'attribute-after-phpdoc' => true,
		];
	}


	public function getParents(): array
	{
		return [Psr12::class];
	}
}
