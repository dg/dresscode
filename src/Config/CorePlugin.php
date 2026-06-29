<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Decision, Plugin, PluginManifest, Rules};
use DressCode\Domains\Words;


/**
 * What the core of DressCode brings: its rules, whose pages are on dresscode.run, and the decisions no single rule of
 * it owns.
 * @internal
 */
final class CorePlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		// built once, since nothing of it changes
		static $manifest;
		return $manifest ??= new PluginManifest(
			rules: [
				Rules\Arrays\ArraySpacingRule::class,
				Rules\Arrays\NoLongArraySyntaxRule::class,
				Rules\Comments\NoEmptyCommentsRule::class,
				Rules\Comments\NoHashCommentsRule::class,
				Rules\ControlFlow\ElseifNotationRule::class,
				Rules\ControlFlow\NoEmptyStatementsRule::class,
				Rules\Expressions\BinaryOperatorSpacingRule::class,
				Rules\Expressions\CastSpacingRule::class,
				Rules\Expressions\ConcatenationSpacingRule::class,
				Rules\Expressions\DoubleColonSpacingRule::class,
				Rules\Expressions\NotEqualsNotationRule::class,
				Rules\Expressions\ObjectOperatorSpacingRule::class,
				Rules\Expressions\OffsetBracketSpacingRule::class,
				Rules\Expressions\TernaryOperatorSpacingRule::class,
				Rules\Expressions\UnaryOperatorSpacingRule::class,
				Rules\Files\DeclareSpacingRule::class,
				Rules\Files\FinalLineEndingsRule::class,
				Rules\Files\OpeningTagNotationRule::class,
				Rules\Files\LineEndingRule::class,
				Rules\Files\NoBomRule::class,
				Rules\Files\NoClosingTagRule::class,
				Rules\Files\NoInvisibleCharactersRule::class,
				Rules\Files\NoTrailingWhitespaceRule::class,
				Rules\Files\StrictTypesRequiredRule::class,
				Rules\Literals\BuiltinCasingRule::class,
				Rules\PhpDoc\NoEmptyPhpdocsRule::class,
				Rules\Variables\NoGlobalStatementsRule::class,
				Rules\Whitespace\CommaSpacingRule::class,
				Rules\Whitespace\ConstructSpacingRule::class,
				Rules\Whitespace\ParenthesesSpacingRule::class,
				Rules\Whitespace\SemicolonSpacingRule::class,
			],
			// the decisions no single rule owns, each turning on the rules that name it in `RuleInfo::$decisions`
			decisions: [
				// the style of the run, which the engine reads and every rule writing code takes
				new Decision('file.lineEnding', new Words([
					'LF' => 'every line ends with LF',
					'CRLF' => 'every line ends with CRLF',
					'majority' => 'every line ends as most lines of the file do, LF on a tie',
				]), 'The line ending of every line, which the code written new takes too; under `keep` that follows the file'),
			],
		);
	}
}
