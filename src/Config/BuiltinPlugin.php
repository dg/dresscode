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
				Rules\Expressions\OffsetBracketSpacingRule::class,
				Rules\Arrays\ArraySpacingRule::class,
				Rules\Arrays\ShortArraySyntaxRule::class,
				Rules\Comments\NoEmptyCommentsRule::class,
				Rules\Comments\NoHashCommentsRule::class,
				Rules\ControlFlow\ElseifKeywordRule::class,
				Rules\ControlFlow\NoEmptyStatementsRule::class,
				Rules\Files\NoBomRule::class,
				Rules\Files\NoInvisibleCharactersRule::class,
				Rules\Files\FullOpeningTagRule::class,
				Rules\Files\LineEndingRule::class,
				Rules\Files\NoClosingTagRule::class,
				Rules\Files\StrictTypesRequiredRule::class,
				Rules\Functions\FunctionNameSpacingRule::class,
				Rules\Literals\TrueFalseNullCasingRule::class,
				Rules\Literals\KeywordCasingRule::class,
				Rules\Expressions\BinaryOperatorSpacingRule::class,
				Rules\Expressions\CastSpacingRule::class,
				Rules\Expressions\ConcatSpacingRule::class,
				Rules\Expressions\DoubleColonSpacingRule::class,
				Rules\Expressions\NotEqualsNotationRule::class,
				Rules\Expressions\ObjectOperatorSpacingRule::class,
				Rules\Expressions\TernaryOperatorSpacingRule::class,
				Rules\Expressions\UnaryOperatorSpacingRule::class,
				Rules\PhpDoc\NoEmptyPhpdocsRule::class,
				Rules\Literals\MagicConstantCasingRule::class,
				Rules\Variables\NoGlobalStatementsRule::class,
				Rules\Whitespace\CommaSpacingRule::class,
				Rules\Files\DeclareSpacingRule::class,
				Rules\Whitespace\SemicolonSpacingRule::class,
				Rules\Whitespace\ParenthesesSpacingRule::class,
				Rules\Files\NoTrailingWhitespaceRule::class,
				Rules\Files\EofLineEndingRule::class,
				Rules\Whitespace\ConstructSpacingRule::class,
			],
			ruleUrl: 'https://dresscode.run/rules/{slug}',
		);
	}
}
