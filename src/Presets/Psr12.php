<?php declare(strict_types=1);

namespace DressCode\Presets;

use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;


/**
 * PSR-12 Extended Coding Style, section by section; the specification defines the style and this preset
 * adds nothing of its own.
 */
#[PresetInfo('dresscode/psr12', 'PSR-12 Extended Coding Style', indent: 4, eol: 'majority')]
final class Psr12 implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [
			// 2. General: files, lines, indenting, keywords and types
			'no-bom' => true,
			'full-opening-tag' => true,
			'line-ending' => true,
			'eof-newline' => true,
			'no-closing-tag' => true,
			'no-trailing-whitespace' => true,
			'single-statement-per-line' => true,
			'indentation' => true,
			'keyword-casing' => true,
			'constant-casing' => true,
			'cast-spacing' => true,
			'cast-canonical-type' => true,

			// 2.1 Basic coding standard: PSR-1 3 and 4 on the case of names
			'name-casing' => ['classes' => 'PascalCase', 'methods' => 'camelCase', 'constants' => 'UPPER_CASE'],

			// 3. Declare statements, namespace and import statements
			'header-blank-lines' => true,
			'ordered-imports' => ['alphabetically' => false],
			'no-leading-backslash-in-import' => true,
			'declare-spacing' => true,

			// 4. Classes, properties and methods
			'new-argument-parentheses' => ['anonymousClasses' => null],
			'class-definition-spacing' => true,
			'braces-position' => ['allowSingleLineAnonymousFunctions' => false],
			'declaration-blank-lines' => [
				'betweenFunctions' => null, 'betweenFunctionsInInterface' => null,
				'betweenMembers' => null, 'beforeDocumentedMember' => null, 'afterPhpDoc' => null,
			],
			'ordered-members' => ['order' => ['use_trait']],
			'visibility-required' => true,
			'single-member-per-declaration' => ['members' => ['property', 'trait']],
			'function-name-spacing' => true,
			'parentheses-spacing' => true,
			'comma-spacing' => ['tabAlignment' => false],
			'multi-line-signature' => ['promotedProperties' => false],
			'type-hint-spacing' => ['catchTypes' => 'single'],
			'reference-spacing' => true,
			'spread-operator-spacing' => true,
			'multi-line-call' => true,

			// 5. Control structures
			'construct-spacing' => true,
			'control-structure-braces' => true,
			'elseif-keyword' => true,
			'continuation-position' => true,
			'multi-line-condition' => true,
			'switch-case-colon' => true,
			'switch-case-spacing' => true,
			'fall-through-comment' => true,

			// 6. Operators
			'unary-operator-spacing' => true,
			'binary-operator-spacing' => true,
			'ternary-operator-spacing' => true,

			// 11. Arrays
			'short-array-syntax' => true,
		];
	}


	public function getParents(): array
	{
		return [];
	}
}
