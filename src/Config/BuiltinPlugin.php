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
				Rules\Arrays\TrailingCommaRule::class,
				Rules\Arrays\MultilineArrayRule::class,
				Rules\Whitespace\AttributeSpacingRule::class,
				Rules\Expressions\UselessAttributeParenthesesRule::class,
				Rules\Whitespace\AttributePositionRule::class,
				Rules\Whitespace\BracesPositionRule::class,
				Rules\ControlFlow\ControlStructureBracesRule::class,
				Rules\ControlFlow\UselessBracesRule::class,
				Rules\Classes\ClassDefinitionSpacingRule::class,
				Rules\Expressions\UselessParenthesesAroundNewRule::class,
				Rules\Classes\SingleMemberPerLineRule::class,
				Rules\Comments\CommentSpacingRule::class,
				Rules\Comments\NoEmptyCommentsRule::class,
				Rules\Comments\NoHashCommentsRule::class,
				Rules\ControlFlow\NoUnreachableCatchesRule::class,
				Rules\ControlFlow\EarlyExitRule::class,
				Rules\ControlFlow\ElseifKeywordRule::class,
				Rules\ControlFlow\TernaryForIfRule::class,
				Rules\ControlFlow\MultilineConditionRule::class,
				Rules\ControlFlow\NoAlternativeSyntaxRule::class,
				Rules\ControlFlow\FallThroughCommentRule::class,
				Rules\ControlFlow\NoEmptyStatementsRule::class,
				Rules\ControlFlow\UselessConstructParenthesesRule::class,
				Rules\ControlFlow\UselessElseRule::class,
				Rules\ControlFlow\UselessReturnRule::class,
				Rules\ControlFlow\UselessCatchVariableRule::class,
				Rules\ControlFlow\ReferenceThrowableOnlyRule::class,
				Rules\ControlFlow\SwitchCaseColonRule::class,
				Rules\ControlFlow\SwitchCaseSpacingRule::class,
				Rules\ControlFlow\NoContinueInSwitchRule::class,
				Rules\ControlFlow\ReturnForBooleanIfRule::class,
				Rules\Files\NoBomRule::class,
				Rules\Files\NoInvisibleCharactersRule::class,
				Rules\Files\FullOpeningTagRule::class,
				Rules\Files\LineEndingRule::class,
				Rules\Files\LineLengthRule::class,
				Rules\Files\NoClosingTagRule::class,
				Rules\Files\StrictTypesRequiredRule::class,
				Rules\Functions\MultilineCallRule::class,
				Rules\Functions\MultilineSignatureRule::class,
				Rules\Functions\NamedArgumentSpacingRule::class,
				Rules\Functions\FunctionNameSpacingRule::class,
				Rules\Literals\TrueFalseNullCasingRule::class,
				Rules\Literals\KeywordCasingRule::class,
				Rules\Expressions\BinaryOperatorSpacingRule::class,
				Rules\Expressions\CastSpacingRule::class,
				Rules\Expressions\CastCanonicalTypeRule::class,
				Rules\Expressions\CombinedAssignmentForRepeatedTargetRule::class,
				Rules\Expressions\ConcatSpacingRule::class,
				Rules\Expressions\DoubleColonSpacingRule::class,
				Rules\Expressions\ExplicitOperatorPrecedenceRule::class,
				Rules\Expressions\NoShortBoolCastsRule::class,
				Rules\Expressions\YodaRule::class,
				Rules\Expressions\NotEqualsNotationRule::class,
				Rules\Expressions\NullCoalescingForNullTernaryRule::class,
				Rules\Expressions\ObjectOperatorSpacingRule::class,
				Rules\Expressions\MultilineChainRule::class,
				Rules\Expressions\ReferenceSpacingRule::class,
				Rules\Expressions\SpreadOperatorSpacingRule::class,
				Rules\Expressions\IncrementForAddOneRule::class,
				Rules\Expressions\StrictComparisonRule::class,
				Rules\Expressions\SymbolicLogicalOperatorsRule::class,
				Rules\Expressions\TernaryOperatorSpacingRule::class,
				Rules\Expressions\MultilineTernaryRule::class,
				Rules\Expressions\ShortTernaryForRepeatedConditionRule::class,
				Rules\Expressions\UnaryOperatorSpacingRule::class,
				Rules\Expressions\UselessTernaryOperatorRule::class,
				Rules\PhpDoc\NoEmptyPhpdocsRule::class,
				Rules\Literals\HeredocIndentationRule::class,
				Rules\Literals\MagicConstantCasingRule::class,
				Rules\Types\TypeHintSpacingRule::class,
				Rules\Variables\CombinedIssetsRule::class,
				Rules\Variables\CombinedUnsetsRule::class,
				Rules\Variables\NoDuplicateAssignmentsRule::class,
				Rules\Variables\NoGlobalStatementsRule::class,
				Rules\Whitespace\CommaSpacingRule::class,
				Rules\Files\DeclareSpacingRule::class,
				Rules\Whitespace\BlankLinesRule::class,
				Rules\Whitespace\SemicolonSpacingRule::class,
				Rules\Whitespace\ParenthesesSpacingRule::class,
				Rules\Files\NoTrailingWhitespaceRule::class,
				Rules\Whitespace\SingleStatementPerLineRule::class,
				Rules\Files\EofLineEndingRule::class,
				Rules\Whitespace\ConstructSpacingRule::class,
				Rules\Whitespace\IndentationRule::class,
				Rules\Whitespace\SingleLevelIndentationRule::class,
			],
			ruleUrl: 'https://dresscode.run/rules/{slug}',
		);
	}
}
