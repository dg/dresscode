<?php declare(strict_types=1);

namespace DressCode\Presets;

use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;


/**
 * The Nette Coding Standard: PER laid out the Nette way (dresscode/nette-style), the hygiene of the
 * fragments, and what is left here, the choices the Nette libraries make: how names are cased, in what
 * order the members of a class stand, which functions and constructs are out, and what a doc comment
 * of theirs holds.
 */
#[PresetInfo('dresscode/nette', 'Nette Coding Standard')]
final class Nette implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [
			'no-invisible-characters' => true,

			// the imports: one block, sorted, functions and constants of a namespace in one statement
			'ordered-imports' => ['order' => 'alphabetical'],
			'import-notation' => ['functions' => 'combined', 'constants' => 'combined', 'groupUse' => 'keep'],
			'class-reference-name-casing' => true,

			// names and declarations
			'name-casing' => [
				'classes' => 'PascalCase', 'methods' => 'camelCase', 'functions' => 'camelCase', 'constants' => 'PascalCase',
				'enumCases' => 'PascalCase', 'properties' => 'camelCase', 'variables' => 'camelCase',
			],
			'kind-in-class-name' => 'forbidden',
			'ordered-members' => ['order' => [
				'use_trait', 'constant', 'constant_public', 'constant_protected', 'constant_private',
				'property_public', 'property_protected', 'property_private',
			]],
			'modern-class-name-reference' => ['onObjects' => true],
			'new-argument-parentheses' => ['namedClasses' => 'forbidden', 'anonymousClasses' => 'forbidden'],
			'no-inner-functions' => true,
			'no-global-keyword' => true,

			// expressions and literals
			'not-equals-operator' => true,
			'yoda' => 'forbidden',
			'explicit-operator-precedence' => true,
			'no-short-bool-cast' => true,
			'increment-operator' => true,
			'symbolic-logical-operators' => true,
			'magic-constant-casing' => true,
			'string-quotes' => 'single',
			'no-trailing-whitespace-in-string' => true,
			'complex-string-variable' => true,
			'no-implicit-backslash' => true,
			'no-backtick-operator' => true,
			'numeric-literal-separator' => ['minDigitsBeforeDecimalPoint' => 7, 'minDigitsAfterDecimalPoint' => 20],

			// control flow: a fall-through in a switch says "break omitted", and an else after a return may stay
			'fall-through-comment' => ['comment' => 'break omitted'],
			'no-alternative-syntax' => true,
			'no-continue-in-switch' => true,
			'reference-throwable-only' => true,
			'useless-else' => 'keep',

			// functions: a parameter or a return without a type is a matter of the library, not of the standard
			'native-function-casing' => true,
			'no-is-null' => true,
			'no-conversion-functions' => true,
			'no-dirname-of-file' => true,
			'no-alias-functions' => true,
			'no-settype' => true,
			'no-deprecated-functions' => true,
			'no-direct-invoke-call' => true,
			'no-unpacking-in-optimized-call' => true,
			'type-hint-required' => 'keep',

			// comments and PHPDoc: both notations of an array type stay as they are
			'no-hash-comment' => true,
			'commented-out-function' => ['functions' => ['print_r', 'var_dump', 'var_export', 'dump']],
			'phpdoc-canonical-types' => ['arrayNotation' => 'keep'],
			'explicit-assertion' => true,
			'property-phpdoc-single-line' => true,
			'property-phpdoc-required' => true,
			'promoted-property-annotation-position' => true,
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
		return [Per::class, NetteStyle::class, Cleanup::class, Modern::class, Types::class, PhpDoc::class, Imports::class, Classes::class];
	}
}
