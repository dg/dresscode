<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\Analyses\IndentationPlan;
use DressCode\{Decision, DecisionKind, Domain, Plugin, PluginManifest, Rules};
use DressCode\Domains\{Count, Names, Words};


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
				Rules\Classes\MultilineImplementsRule::class,
				Rules\Classes\ClassNameNotationRule::class,
				Rules\Classes\ClassKindInNameRule::class,
				Rules\Classes\NameCasingRule::class,
				Rules\Classes\NoThisOutsideObjectRule::class,
				Rules\Classes\MemberOrderRule::class,
				Rules\Classes\PublicWithSetVisibilityRule::class,
				Rules\Classes\SelfForCurrentClassRule::class,
				Rules\Classes\NoGroupedDeclarationsRule::class,
				Rules\Classes\NoMembersSharingLineRule::class,
				Rules\Classes\UselessModifierRule::class,
				Rules\Classes\UselessNullInitializationRule::class,
				Rules\Classes\VisibilityRequiredRule::class,
				Rules\Classes\ModifierOrderRule::class,
				Rules\Comments\CommentSpacingRule::class,
				Rules\Comments\NoEmptyCommentsRule::class,
				Rules\Comments\NoHashCommentsRule::class,
				Rules\ControlFlow\NoBracelessBodiesRule::class,
				Rules\ControlFlow\EarlyExitForTrailingIfRule::class,
				Rules\ControlFlow\ElseifNotationRule::class,
				Rules\ControlFlow\FallThroughCommentRule::class,
				Rules\ControlFlow\MultilineConditionRule::class,
				Rules\ControlFlow\MultilineForRule::class,
				Rules\ControlFlow\NoAlternativeSyntaxRule::class,
				Rules\ControlFlow\NoContinueInSwitchRule::class,
				Rules\ControlFlow\NoEmptyStatementsRule::class,
				Rules\ControlFlow\NoUnreachableCatchesRule::class,
				Rules\ControlFlow\NoRepeatedCatchesRule::class,
				Rules\ControlFlow\ThrowableForExceptionRule::class,
				Rules\ControlFlow\ReturnForBooleanIfRule::class,
				Rules\ControlFlow\SwitchCaseNotationRule::class,
				Rules\ControlFlow\SwitchCaseSpacingRule::class,
				Rules\ControlFlow\TernaryForIfRule::class,
				Rules\ControlFlow\UselessBracesRule::class,
				Rules\ControlFlow\UselessCatchVariableRule::class,
				Rules\Expressions\UselessParenthesesAfterConstructRule::class,
				Rules\ControlFlow\UselessElseRule::class,
				Rules\ControlFlow\UselessReturnRule::class,
				Rules\Expressions\BinaryOperatorSpacingRule::class,
				Rules\Expressions\CastCanonicalTypeRule::class,
				Rules\Expressions\CastSpacingRule::class,
				Rules\Expressions\CombinedAssignmentForRepeatedTargetRule::class,
				Rules\Expressions\ConcatenationSpacingRule::class,
				Rules\Expressions\DoubleColonSpacingRule::class,
				Rules\Expressions\EmptyArgumentParenthesesRule::class,
				Rules\Expressions\ExplicitPrecedenceRequiredRule::class,
				Rules\Expressions\IncrementForAddOneRule::class,
				Rules\Expressions\MultilineBinaryOperatorRule::class,
				Rules\Expressions\MultilineMatchArmRule::class,
				Rules\Expressions\MultilineChainRule::class,
				Rules\Expressions\MultilineTernaryRule::class,
				Rules\Expressions\NoDoubleNegationsRule::class,
				Rules\Expressions\NotEqualsNotationRule::class,
				Rules\Expressions\NullCoalescingForNullTernaryRule::class,
				Rules\Expressions\ObjectOperatorSpacingRule::class,
				Rules\Expressions\OffsetBracketSpacingRule::class,
				Rules\Expressions\ReferenceSpacingRule::class,
				Rules\Expressions\ShortTernaryForRepeatedConditionRule::class,
				Rules\Expressions\SpreadOperatorSpacingRule::class,
				Rules\Expressions\NoLooseComparisonsRule::class,
				Rules\Expressions\LogicalOperatorNotationRule::class,
				Rules\Expressions\TernaryOperatorSpacingRule::class,
				Rules\Expressions\UnaryOperatorSpacingRule::class,
				Rules\Expressions\UselessParenthesesAroundNewRule::class,
				Rules\Expressions\UselessTernaryOperatorRule::class,
				Rules\Expressions\YodaRule::class,
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
				Rules\Functions\ArrowFunctionForClosureRule::class,
				Rules\Functions\FunctionNameSpacingRule::class,
				Rules\Functions\MultilineCallRule::class,
				Rules\Functions\MultilineSignatureRule::class,
				Rules\Functions\NamedArgumentSpacingRule::class,
				Rules\Functions\NoAliasFunctionsRule::class,
				Rules\Functions\NoConversionFunctionsRule::class,
				Rules\Functions\NoExplicitInvokeCallsRule::class,
				Rules\Functions\NoDirnameOfFileRule::class,
				Rules\Functions\NoInnerFunctionsRule::class,
				Rules\Functions\NoIsNullRule::class,
				Rules\Functions\NoSettypeRule::class,
				Rules\Functions\StaticForClosureWithoutThisRule::class,
				Rules\Functions\StrictComparisonArgumentRequiredRule::class,
				Rules\Functions\UselessParameterDefaultRule::class,
				Rules\Literals\NoDollarBraceInterpolationsRule::class,
				Rules\Literals\HeredocIndentationRule::class,
				Rules\Literals\BuiltinCasingRule::class,
				Rules\Expressions\NoBacktickOperatorsRule::class,
				Rules\Literals\NoImplicitBackslashesRule::class,
				Rules\Literals\NoTrailingWhitespaceInStringRule::class,
				Rules\Literals\NowdocForHeredocRule::class,
				Rules\Literals\NumericLiteralSeparatorRule::class,
				Rules\Literals\OctalNotationRule::class,
				Rules\Literals\StringQuotesRule::class,
				Rules\Literals\UselessStringConcatenationRule::class,
				Rules\Namespaces\ImportNotationRule::class,
				Rules\Namespaces\DeclaredNameCasingRule::class,
				Rules\Namespaces\NoUnlistedNamespacedDeclarationsRule::class,
				Rules\Namespaces\ImportOrderRule::class,
				Rules\Namespaces\GlobalNameQualificationRule::class,
				Rules\Namespaces\ForeignNameQualificationRule::class,
				Rules\Namespaces\OptimizedCallNotationRule::class,
				Rules\Namespaces\UselessLeadingBackslashRule::class,
				Rules\Namespaces\NoUnusedImportsRule::class,
				Rules\Namespaces\UselessAliasRule::class,
				Rules\Namespaces\UselessCurrentNamespaceImportRule::class,
				Rules\PhpDoc\NoEmptyPhpdocsRule::class,
				Rules\PhpDoc\PhpdocAlignmentRule::class,
				Rules\PhpDoc\PhpdocBlankLinesRule::class,
				Rules\Types\TypeDeclarationSpacingRule::class,
				Rules\Upgrading\NoDeprecatedPhpCallsRule::class,
				Rules\Variables\NoSeparateIssetsRule::class,
				Rules\Variables\NoSeparateUnsetsRule::class,
				Rules\Variables\NoRepeatedAssignmentsRule::class,
				Rules\Variables\NoGlobalStatementsRule::class,
				Rules\Whitespace\AttributePositionRule::class,
				Rules\Whitespace\AttributeSpacingRule::class,
				Rules\Whitespace\StatementBlankLinesRule::class,
				Rules\Whitespace\MemberBlankLinesRule::class,
				Rules\Whitespace\BlockBlankLinesRule::class,
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
				new Decision(
					'file.lineLength',
					new Count(1, range: false, words: ['none' => 'no line is too wide']),
					'The widest line, by which what spreads over lines is split',
					kind: DecisionKind::Parameter,
					default: 'none',
				),
				new Decision('file.lineLengthExcept', new Names([
					'imports' => 'a `use` import, which cannot be split',
					'strings' => 'a line that would fit were each of its string literals an empty pair of quotes, a string being never split',
					'call' => 'the arguments of a call',
					'new' => 'the arguments of `new`',
					'attribute' => 'the arguments of an attribute',
					'signature' => 'the parameters of a function or a method',
					'condition' => 'a condition of `if`, `elseif`, `while` and `do-while`',
					'chain' => 'a chain of method calls',
					'ternary' => 'a ternary',
					'for' => 'the header of a `for`',
					'binaryOperator' => 'the operands of a boolean operator, of concatenations or of `|`',
					'array' => 'the items of an array, which `multiline.arrayMaxWidth` spreads all the same',
					'implements' => 'the interfaces a class implements or an interface extends',
					'matchArm' => 'the conditions of a `match` arm',
				], regularExpressions: true), 'What a line too wide leaves as it is: a construct named is not spread over lines for its width, and a line it stands on, one a regular expression matches, or with `strings` one wide only for its string literals, is not reported', kind: DecisionKind::Parameter, default: ['imports']),
				new Decision('indentation.unit', new Words([
					'tab' => 'one tab per level',
					'4 spaces' => 'four spaces per level',
					'2 spaces' => 'two spaces per level',
				]), 'Every line indented by the construct it continues, one level per nesting, the level being this unit; under `keep` a line stays where it is'),
				new Decision(
					'indentation.tabWidth',
					new Count(1, 8, range: false),
					'How many columns a tab counts for in the width of a line',
					kind: DecisionKind::Parameter,
					default: 4,
				),
				...self::createIndentationDecisions(),
				...self::createImportDecisions(),

				// how far a global function is written out, which decides the arguments of its optimized call too
				Rules\Namespaces\QualificationPolicy::createGlobalDecision(
					'qualification.function.global',
					'A global function called in a namespace, `strlen()`, those the compiler optimizes included unless `function.optimized` requires a form for them',
					'`strlen()`',
					'`use function strlen;` and `strlen()`',
					'`\strlen()`',
					'Not a function of the namespace of the file, nor a name in a file without a namespace, which `inFileWithoutNamespace` decides.',
				),
				Rules\Namespaces\QualificationPolicy::createOptimizedDecision(
					'qualification.function.optimized',
					'A global function whose call PHP compiles to one opcode once it knows the function is global, `count()`, `strlen()`, `is_int()` and the others; where this key requires a form, it decides such a function over `function.global`, and a call of it written imported or with the backslash, by either key, has its arguments passed positionally and an unpacked one reported',
					'`strlen()`',
					'`use function strlen;`',
					'`\strlen()`',
					'optimizes it',
					'A function is optimized in a namespace where one of its calls there is, an unpacked or named argument aside, and every reference to it in that namespace is then written so.',
				),

				// the lines below a doc comment or an attribute, of a member as of a declaration among statements
				new Decision(
					'blankLines.afterPhpdoc',
					Domain::blankLines(),
					'Between a doc comment or an attribute and the declaration it belongs to',
				),
			],
		);
	}


	/** @return list<Decision>  the levels of the lines continuing a construct, which a rule deciding by the width of a line waits for */
	private static function createIndentationDecisions(): array
	{
		$level = new Count(0, 1, range: false);
		return [
			new Decision(
				IndentationPlan::Binary,
				$level,
				'The levels a line opened by `&&`, `+`, `.` steps in by from the start of its expression, 1 at least where the expression shares its first line',
			),
			new Decision(IndentationPlan::Ternary, $level, 'The levels `?` and `:` opening a line step in by'),
			new Decision(IndentationPlan::TernaryBelowCondition, new Words([
				'stepped' => 'from the last line of the condition',
				'aligned' => 'lined up with the operators of the condition',
			]), 'Where `?` and `:` below a condition spread over lines stand', kind: DecisionKind::Parameter, default: 'aligned'),
			new Decision(IndentationPlan::SwitchCase, $level, 'The levels `case` steps in by from `switch`'),
			new Decision(IndentationPlan::Chain, new Words([
				'flat' => 'every link one level below the start',
				'nested' => 'a link one level deeper or shallower than the link before it',
			]), 'Where the links of a chain spread over lines stand'),
		];
	}


	/** @return list<Decision>  how the names of the imports are put into statements, which an import a rule adds takes too */
	private static function createImportDecisions(): array
	{
		$shapes = new Words([
			'separated' => 'a `use` of its own for every name, `use Foo; use Bar;`',
			'combined' => 'all names of the kind in one `use`, `use Foo, Bar;`, one per namespace declaration',
			'grouped' => 'a group use of one namespace stays, `use Acme\Shop\{Order, Cart};`',
		], tolerance: true);
		return [
			new Decision('imports.grouping.class', $shapes, 'How the imports of classes are put into `use` statements'),
			new Decision('imports.grouping.function', $shapes, 'How the imports of functions are put into `use` statements'),
			new Decision('imports.grouping.constant', $shapes, 'How the imports of constants are put into `use` statements'),
		];
	}
}
