<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\Analyses\IndentationPlan;
use DressCode\{Decision, Plugin, PluginManifest, Rules};
use DressCode\Domains\{Count, Words};


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
				Rules\Arrays\MultilineArrayRule::class,
				Rules\Arrays\NoLongArraySyntaxRule::class,
				Rules\Arrays\TrailingCommaRule::class,
				Rules\Classes\ClassHeadSpacingRule::class,
				Rules\Classes\NoMembersSharingLineRule::class,
				Rules\Comments\CommentSpacingRule::class,
				Rules\Comments\NoEmptyCommentsRule::class,
				Rules\Comments\NoHashCommentsRule::class,
				Rules\ControlFlow\NoBracelessBodiesRule::class,
				Rules\ControlFlow\ElseifNotationRule::class,
				Rules\ControlFlow\MultilineConditionRule::class,
				Rules\ControlFlow\NoEmptyStatementsRule::class,
				Rules\ControlFlow\SwitchCaseSpacingRule::class,
				Rules\ControlFlow\UselessParenthesesAfterConstructRule::class,
				Rules\Expressions\BinaryOperatorSpacingRule::class,
				Rules\Expressions\CastSpacingRule::class,
				Rules\Expressions\CombinedAssignmentForRepeatedTargetRule::class,
				Rules\Expressions\ConcatenationSpacingRule::class,
				Rules\Expressions\DoubleColonSpacingRule::class,
				Rules\Expressions\ExplicitPrecedenceRequiredRule::class,
				Rules\Expressions\IncrementForAddOneRule::class,
				Rules\Expressions\MultilineChainRule::class,
				Rules\Expressions\MultilineTernaryRule::class,
				Rules\Expressions\NotEqualsNotationRule::class,
				Rules\Expressions\ObjectOperatorSpacingRule::class,
				Rules\Expressions\OffsetBracketSpacingRule::class,
				Rules\Expressions\ReferenceSpacingRule::class,
				Rules\Expressions\SpreadOperatorSpacingRule::class,
				Rules\Expressions\LogicalOperatorNotationRule::class,
				Rules\Expressions\TernaryOperatorSpacingRule::class,
				Rules\Expressions\UnaryOperatorSpacingRule::class,
				Rules\Files\DeclareSpacingRule::class,
				Rules\Files\FinalLineEndingsRule::class,
				Rules\Files\OpeningTagNotationRule::class,
				Rules\Files\LineEndingRule::class,
				Rules\Files\LineLengthRule::class,
				Rules\Files\NoBomRule::class,
				Rules\Files\NoClosingTagRule::class,
				Rules\Files\NoInvisibleCharactersRule::class,
				Rules\Files\NoTrailingWhitespaceRule::class,
				Rules\Files\StrictTypesRequiredRule::class,
				Rules\Functions\FunctionNameSpacingRule::class,
				Rules\Functions\MultilineCallRule::class,
				Rules\Functions\MultilineSignatureRule::class,
				Rules\Functions\NamedArgumentSpacingRule::class,
				Rules\Literals\HeredocIndentationRule::class,
				Rules\Literals\BuiltinCasingRule::class,
				Rules\PhpDoc\NoEmptyPhpdocsRule::class,
				Rules\Types\TypeDeclarationSpacingRule::class,
				Rules\Variables\NoGlobalStatementsRule::class,
				Rules\Whitespace\AttributePositionRule::class,
				Rules\Whitespace\AttributeSpacingRule::class,
				Rules\Whitespace\BlankLinesRule::class,
				Rules\Whitespace\BracesPositionRule::class,
				Rules\Whitespace\CommaSpacingRule::class,
				Rules\Whitespace\ConstructSpacingRule::class,
				Rules\Whitespace\IndentationRule::class,
				Rules\Whitespace\ParenthesesSpacingRule::class,
				Rules\Whitespace\SemicolonSpacingRule::class,
				Rules\Whitespace\SingleLevelIndentationRule::class,
				Rules\Whitespace\NoStatementsSharingLineRule::class,
			],
			// the decisions no single rule owns, each turning on the rules that name it in `RuleInfo::$decisions`
			decisions: [
				// the style of the run, which the engine reads and every rule writing code takes
				new Decision('file.lineEnding', new Words([
					'LF' => 'every line ends with LF',
					'CRLF' => 'every line ends with CRLF',
					'majority' => 'every line ends as most lines of the file do, LF on a tie',
				]), 'The line ending of every line, which the code written new takes too; under `keep` that follows the file'),
				new Decision('file.maxLineLength', new Count(1, range: false, words: ['none' => 'no line is too wide']), 'The widest line, by which what spreads over lines is split', parameter: true, default: 'none'),
				new Decision('indentation.unit', new Words([
					'tab' => 'one tab per level',
					'4 spaces' => 'four spaces per level',
					'2 spaces' => 'two spaces per level',
				]), 'Every line indented by the construct it continues, one level per nesting, the level being this unit; under `keep` a line stays where it is'),
				new Decision('indentation.tabWidth', new Count(1, 8, range: false), 'How many columns a tab counts for in the width of a line', parameter: true, default: 4),
				...self::createIndentationDecisions(),
			],
		);
	}


	/** @return list<Decision>  the levels of the lines continuing a construct, which a rule deciding by the width of a line waits for */
	private static function createIndentationDecisions(): array
	{
		$level = new Count(0, 1, range: false);
		return [
			new Decision(IndentationPlan::Binary, $level, 'The levels a line opened by `&&`, `+`, `.` steps in by from the start of its expression, 1 at least where the expression shares its first line'),
			new Decision(IndentationPlan::Ternary, $level, 'The levels `?` and `:` opening a line step in by'),
			new Decision(IndentationPlan::TernaryBelowCondition, new Words([
				'stepped' => 'from the last line of the condition',
				'aligned' => 'lined up with the operators of the condition',
			]), 'Where `?` and `:` below a condition spread over lines stand', parameter: true, default: 'aligned'),
			new Decision(IndentationPlan::SwitchCase, $level, 'The levels `case` steps in by from `switch`'),
			new Decision(IndentationPlan::Chain, new Words([
				'flat' => 'every link one level below the start',
				'nested' => 'a link one level deeper or shallower than the link before it',
			]), 'Where the links of a chain spread over lines stand'),
		];
	}
}
