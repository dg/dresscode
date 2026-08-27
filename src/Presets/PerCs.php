<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Presets;

use DressCode\{Preset, PresetInfo, Profile};


/**
 * PER Coding Style 3.1: PSR-12 plus what the PER added (trailing
 * commas, short closures, empty bodies as `{}`, attributes, enumerations, heredoc, property hooks); the
 * specification defines the style and this preset adds nothing of its own.
 */
#[PresetInfo('dresscode/perCs', 'PER Coding Style 3.1')]
final class PerCs implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(
			presets: [Psr12::class],
			indent: 4,
			lineEnding: 'majority',
			rules: [
				// 2.6 Trailing commas
				'trailingComma' => ['array' => 'required', 'argument' => 'required', 'parameter' => 'required', 'matchArm' => 'required', 'closureUse' => 'required'],

				// 4. Classes, properties and methods: empty bodies, anonymous classes, named arguments, chains
				'classDefinitionSpacing' => ['beforeParenthesis' => 'none'],
				'bracesPosition' => ['singlelineAnonymousFunction' => 'always', 'emptyBody' => 'sameLine'],
				'newArgumentParentheses' => ['namedClass' => 'required', 'anonymousClass' => 'forbidden'],
				'namedArgumentSpacing' => true,
				'multilineChain' => true,

				// 5.6 The types of a multi-catch hug their bar, as every compound type does
				'typeHintSpacing' => ['catch' => 'none'],

				// 6. Operators
				'concatSpacing' => true,
				'multilineTernary' => true,

				// 7.1 Short closures; a semicolon on a line of its own below a statement spanning lines is left open as well
				'semicolonSpacing' => ['after' => 'keep', 'allowOwnLine' => true],

				// 9. Enumerations: cases in PascalCase, on top of what PSR-1 says about names, each on its own line
				'nameCasing' => [
					'class' => 'PascalCase',
					'method' => 'camelCase',
					'constant' => 'UPPER_CASE',
					'enumCase' => 'PascalCase',
				],
				'singleMemberPerLine' => true,

				// 10. Heredoc and nowdoc
				'nowdocWithoutInterpolation' => true,
				'heredocIndentation' => true,

				// 11. Arrays
				'multilineArray' => true,

				// 12. Attributes
				'attributeSpacing' => true,
				'uselessAttributeParentheses' => true,
				'attributePosition' => true,
				'attributeAfterPhpdoc' => true,
			],
		);
	}
}
