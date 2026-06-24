<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Plugin, PluginManifest, Rules};


/**
 * What DressCode itself brings: the built-in presets and rules, whose pages are on dresscode.run.
 * @internal
 */
final class BuiltinPlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest(
			presets: [
			],
			rules: [
				Rules\Arrays\ShortArraySyntaxRule::class,
				Rules\Comments\NoHashCommentsRule::class,
				Rules\ControlFlow\ElseifKeywordRule::class,
				Rules\ControlFlow\NoEmptyStatementsRule::class,
				Rules\Files\NoBomRule::class,
				Rules\Files\NoInvisibleCharactersRule::class,
				Rules\Files\FullOpeningTagRule::class,
				Rules\Files\LineEndingRule::class,
				Rules\Files\NoClosingTagRule::class,
				Rules\Literals\TrueFalseNullCasingRule::class,
				Rules\Literals\KeywordCasingRule::class,
				Rules\Expressions\NotEqualsNotationRule::class,
				Rules\Literals\MagicConstantCasingRule::class,
				Rules\Variables\NoGlobalStatementsRule::class,
				Rules\Files\NoTrailingWhitespaceRule::class,
				Rules\Files\EofLineEndingRule::class,
			],
			ruleUrl: 'https://dresscode.run/rules/{slug}',
		);
	}
}
