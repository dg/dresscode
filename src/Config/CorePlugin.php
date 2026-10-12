<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\Analyses\IndentationPlan;
use DressCode\{Decision, DecisionKind, Domain, Plugin, PluginManifest, Rules, Violation};
use DressCode\Domains\{Count, Flag, GrammarEntry, Map, Names, Shapes, Words};
use DressCode\Rules\Upgrading\{AttributeForMemberEntry, AttributeTarget, CallTemplate, MemberTarget};
use DressCode\Upgrading\{MemberMapGrammar, MemberPattern};
use Nette\Schema\{Context, Expect, Schema};
use function dirname;


/**
 * What the core of DressCode brings: its presets, its rules, whose pages are on dresscode.run, and the decisions no
 * single rule of it owns.
 * @internal
 */
final class CorePlugin implements Plugin
{
	/**
	 * The presets that are complete standards; the other presets are sets.
	 */
	public const Standards = ['perCs', 'psr12', 'nette', 'symfony'];
	private const QualifiedName = '\\\\?\w+(\\\\\w+)*';


	public function getManifest(): PluginManifest
	{
		// built once, the grammars of the maps above all, since nothing of it changes
		static $manifest;
		return $manifest ??= new PluginManifest(
			presets: [
				'dresscode/perCs' => dirname(__DIR__) . '/Presets/perCs.neon',
				'dresscode/psr12' => dirname(__DIR__) . '/Presets/psr12.neon',
				'dresscode/nette' => dirname(__DIR__) . '/Presets/nette.neon',
				'dresscode/symfony' => dirname(__DIR__) . '/Presets/symfony.neon',
				'dresscode/cleanup' => dirname(__DIR__) . '/Presets/cleanup.neon',
				'dresscode/compilerOptimizations' => dirname(__DIR__) . '/Presets/compilerOptimizations.neon',
				'dresscode/correctness' => dirname(__DIR__) . '/Presets/correctness.neon',
				'dresscode/deprecations' => dirname(__DIR__) . '/Presets/deprecations.neon',
				'dresscode/modernizations' => dirname(__DIR__) . '/Presets/modernizations.neon',
				'dresscode/types' => dirname(__DIR__) . '/Presets/types.neon',
			],
			rules: [
				Rules\Arrays\ArraySyntaxRule::class,
				Rules\Arrays\MultilineArrayRule::class,
				Rules\Arrays\NoManualListTestsRule::class,
				Rules\Arrays\NoNullArrayKeysRule::class,
				Rules\Arrays\SpreadForArrayMergeRule::class,
				Rules\Arrays\TrailingCommaRule::class,
				Rules\Classes\ClassConstantForConstantCallRule::class,
				Rules\Classes\ClassHeadSpacingRule::class,
				Rules\Classes\ClassKeywordForStringRule::class,
				Rules\Classes\ClassKindInNameRule::class,
				Rules\Classes\ClassNameNotationRule::class,
				Rules\Classes\FinalForInternalClassRule::class,
				Rules\Classes\MemberOrderRule::class,
				Rules\Classes\ModifierOrderRule::class,
				Rules\Classes\MultilineImplementsRule::class,
				Rules\Classes\NameCasingRule::class,
				Rules\Classes\NoConstructorReturnValuesRule::class,
				Rules\Classes\NoFinalParentRule::class,
				Rules\Classes\NoGroupedDeclarationsRule::class,
				Rules\Classes\NoNullDebugInfoRule::class,
				Rules\Classes\NoSleepAndWakeupRule::class,
				Rules\Classes\NoThisOutsideObjectRule::class,
				Rules\Classes\NoUnimplementedAbstractMethodsRule::class,
				Rules\Classes\OverrideAttributeRequiredRule::class,
				Rules\Classes\OverridingSignatureRule::class,
				Rules\Classes\PrivateForProtectedRule::class,
				Rules\Classes\PromotedPropertyForAssignmentRule::class,
				Rules\Classes\PublicWithSetVisibilityRule::class,
				Rules\Classes\ReadonlyClassForReadonlyPropertiesRule::class,
				Rules\Classes\ReadonlyForAnnotationRule::class,
				Rules\Classes\ReadonlyForUnwrittenPropertyRule::class,
				Rules\Classes\SelfForCurrentClassRule::class,
				Rules\Classes\SerializeMethodsRequiredRule::class,
				Rules\Classes\StaticForMethodWithoutThisRule::class,
				Rules\Classes\StaticSetStateRequiredRule::class,
				Rules\Classes\StringableRequiredRule::class,
				Rules\Classes\UselessModifierRule::class,
				Rules\Classes\UselessNullInitializationRule::class,
				Rules\Classes\UselessOverridingMethodRule::class,
				Rules\Classes\UselessReturnTypeWillChangeRule::class,
				Rules\Classes\VisibilityRequiredRule::class,
				Rules\Comments\CommentSpacingRule::class,
				Rules\Comments\NoEmptyCommentsRule::class,
				Rules\Comments\NoHashCommentsRule::class,
				Rules\ControlFlow\ArraySearchForFilterRule::class,
				Rules\ControlFlow\ArraySearchForForeachRule::class,
				Rules\ControlFlow\EarlyExitForTrailingIfRule::class,
				Rules\ControlFlow\ElseifNotationRule::class,
				Rules\ControlFlow\FallThroughCommentRule::class,
				Rules\ControlFlow\MatchForSwitchRule::class,
				Rules\ControlFlow\MultilineConditionRule::class,
				Rules\ControlFlow\MultilineForRule::class,
				Rules\ControlFlow\NoAlternativeSyntaxRule::class,
				Rules\ControlFlow\NoBracelessBodiesRule::class,
				Rules\ControlFlow\NoContinueInSwitchRule::class,
				Rules\ControlFlow\NoEmptyStatementsRule::class,
				Rules\ControlFlow\NoRepeatedCatchesRule::class,
				Rules\ControlFlow\NoReturnsInFinallyRule::class,
				Rules\ControlFlow\NoUnreachableCatchesRule::class,
				Rules\ControlFlow\ReturnForBooleanIfRule::class,
				Rules\ControlFlow\SwitchCaseNotationRule::class,
				Rules\ControlFlow\SwitchCaseSpacingRule::class,
				Rules\ControlFlow\TernaryForIfRule::class,
				Rules\ControlFlow\ThrowableForExceptionRule::class,
				Rules\ControlFlow\ThrowExpressionForNullGuardRule::class,
				Rules\ControlFlow\UselessBracesRule::class,
				Rules\ControlFlow\UselessCatchVariableRule::class,
				Rules\ControlFlow\UselessElseRule::class,
				Rules\ControlFlow\UselessReturnRule::class,
				Rules\Expressions\BinaryOperatorSpacingRule::class,
				Rules\Expressions\CastCanonicalTypeRule::class,
				Rules\Expressions\CloneWithNotationRule::class,
				Rules\Expressions\CombinedAssignmentForRepeatedTargetRule::class,
				Rules\Expressions\EmptyArgumentParenthesesRule::class,
				Rules\Expressions\ExplicitPrecedenceRequiredRule::class,
				Rules\Expressions\IncrementRule::class,
				Rules\Expressions\InstantiationWrappingRule::class,
				Rules\Expressions\LogicalOperatorNotationRule::class,
				Rules\Expressions\MemberAccessSpacingRule::class,
				Rules\Expressions\MultilineBinaryOperatorRule::class,
				Rules\Expressions\MultilineChainRule::class,
				Rules\Expressions\MultilineMatchArmRule::class,
				Rules\Expressions\MultilineMatchRule::class,
				Rules\Expressions\MultilineTernaryRule::class,
				Rules\Expressions\NoBacktickOperatorsRule::class,
				Rules\Expressions\NoDoubleNegationsRule::class,
				Rules\Expressions\NoErrorSuppressionRule::class,
				Rules\Expressions\NoLooseComparisonsRule::class,
				Rules\Expressions\NotEqualsNotationRule::class,
				Rules\Expressions\NullCoalescingForNullTernaryRule::class,
				Rules\Expressions\NullsafeForGuardedAccessRule::class,
				Rules\Expressions\OperandSpacingRule::class,
				Rules\Expressions\PipeForNestedCallsRule::class,
				Rules\Expressions\ShortTernaryRule::class,
				Rules\Expressions\TernaryOperatorSpacingRule::class,
				Rules\Expressions\UselessParenthesesAfterConstructRule::class,
				Rules\Expressions\UselessTernaryOperatorRule::class,
				Rules\Expressions\YodaRule::class,
				Rules\Files\DeclareSpacingRule::class,
				Rules\Files\FinalLineEndingsRule::class,
				Rules\Files\LineEndingRule::class,
				Rules\Files\LineLengthRule::class,
				Rules\Files\NoBomRule::class,
				Rules\Files\NoClosingTagRule::class,
				Rules\Files\NoInvisibleCharactersRule::class,
				Rules\Files\NoTrailingWhitespaceRule::class,
				Rules\Files\OpeningTagNotationRule::class,
				Rules\Files\StrictTypesRequiredRule::class,
				Rules\Functions\ArrayFirstLastNotationRule::class,
				Rules\Functions\ArrowFunctionForClosureRule::class,
				Rules\Functions\CallableNotationRule::class,
				Rules\Functions\ClampForMinMaxRule::class,
				Rules\Functions\CsvEscapeArgumentRequiredRule::class,
				Rules\Functions\FirstClassCallableForStringRule::class,
				Rules\Functions\FunctionNameSpacingRule::class,
				Rules\Functions\GetDebugTypeForTernaryRule::class,
				Rules\Functions\JsonValidateForDecodeRule::class,
				Rules\Functions\MethodCasingRule::class,
				Rules\Functions\MultilineCallRule::class,
				Rules\Functions\MultilineClosureUseRule::class,
				Rules\Functions\MultilineSignatureRule::class,
				Rules\Functions\NamedArgumentForBooleanRule::class,
				Rules\Functions\NamedArgumentSpacingRule::class,
				Rules\Functions\NewInitializerForNullDefaultRule::class,
				Rules\Functions\NoAliasFunctionsRule::class,
				Rules\Functions\NoCallUserFuncRule::class,
				Rules\Functions\NoConversionFunctionsRule::class,
				Rules\Functions\NoDebugOutputRule::class,
				Rules\Functions\NoDirnameOfFileRule::class,
				Rules\Functions\NoExplicitInvokeCallsRule::class,
				Rules\Functions\NoInnerFunctionsRule::class,
				Rules\Functions\NoIsNullRule::class,
				Rules\Functions\NoManualEmptyStringTestsRule::class,
				Rules\Functions\NoManualSubstringTestsRule::class,
				Rules\Functions\NoSettypeRule::class,
				Rules\Functions\RedundantArgumentsRule::class,
				Rules\Functions\RoundingModeNotationRule::class,
				Rules\Functions\SensitiveParameterRequiredRule::class,
				Rules\Functions\StaticForClosureWithoutThisRule::class,
				Rules\Functions\StrictComparisonArgumentRequiredRule::class,
				Rules\Functions\UselessParameterDefaultRule::class,
				Rules\Literals\BuiltinCasingRule::class,
				Rules\Literals\HeredocIndentationRule::class,
				Rules\Literals\NoDollarBraceInterpolationsRule::class,
				Rules\Literals\NoImplicitBackslashesRule::class,
				Rules\Literals\NoTrailingWhitespaceInStringRule::class,
				Rules\Literals\NowdocForHeredocRule::class,
				Rules\Literals\NumberNotationRule::class,
				Rules\Literals\OctalNotationRule::class,
				Rules\Literals\StringQuotesRule::class,
				Rules\Literals\UselessStringConcatenationRule::class,
				Rules\Namespaces\DeclaredNameCasingRule::class,
				Rules\Namespaces\ForeignNameQualificationRule::class,
				Rules\Namespaces\GlobalNameQualificationRule::class,
				Rules\Namespaces\ImportNotationRule::class,
				Rules\Namespaces\ImportOrderRule::class,
				Rules\Namespaces\MultilineImportRule::class,
				Rules\Namespaces\NoReservedNamesRule::class,
				Rules\Namespaces\NoUnlistedNamespacedDeclarationsRule::class,
				Rules\Namespaces\NoUnusedImportsRule::class,
				Rules\Namespaces\OptimizedCallNotationRule::class,
				Rules\Namespaces\UselessAliasRule::class,
				Rules\Namespaces\UselessCurrentNamespaceImportRule::class,
				Rules\Namespaces\UselessLeadingBackslashRule::class,
				Rules\PhpDoc\AnnotationCasingRule::class,
				Rules\PhpDoc\AssertForInlineVarRule::class,
				Rules\PhpDoc\DeprecatedAttributeForAnnotationRule::class,
				Rules\PhpDoc\ForbiddenAnnotationsRule::class,
				Rules\PhpDoc\ForbiddenPhpdocLinesRule::class,
				Rules\PhpDoc\NoConsecutivePhpdocsRule::class,
				Rules\PhpDoc\NoInvalidAnnotationsRule::class,
				Rules\PhpDoc\NoPlainPhpdocsRule::class,
				Rules\PhpDoc\PhpdocAboveAttributesRule::class,
				Rules\PhpDoc\PhpdocAlignmentRule::class,
				Rules\PhpDoc\PhpdocBlankLinesRule::class,
				Rules\PhpDoc\PhpdocTypeNotationRule::class,
				Rules\PhpDoc\PromotedPropertyAnnotationPositionRule::class,
				Rules\PhpDoc\PropertyPhpdocLineSpanRule::class,
				Rules\PhpDoc\UselessConstantVarAnnotationRule::class,
				Rules\PhpDoc\UselessInheritdocRule::class,
				Rules\Types\NativeTypeRequiredRule::class,
				Rules\Types\NeverForThrowingFunctionRule::class,
				Rules\Types\NullableTypeForDefaultNullRule::class,
				// after the native types written from the annotations, which they then repeat
				Rules\PhpDoc\UselessTypeAnnotationsRule::class,
				Rules\Types\ConstantTypeRequiredRule::class,
				Rules\Types\TypeDeclarationSpacingRule::class,
				Rules\Types\TypeNotationRule::class,
				// what a map replaces is written first, so that what it forbids is reported of what remains
				Rules\Upgrading\ReplacedClassesRule::class,
				Rules\Upgrading\ReplacedFunctionsRule::class,
				Rules\Upgrading\ReplacedMembersRule::class,
				Rules\Upgrading\ReplacedCallsRule::class,
				Rules\Upgrading\ReplacedFunctionCallsRule::class,
				Rules\Upgrading\ForbiddenSymbolsRule::class,
				Rules\Upgrading\ForbiddenMembersRule::class,
				Rules\Upgrading\AttributeForAnnotationRule::class,
				Rules\Upgrading\AttributeForMemberRule::class,
				Rules\Upgrading\NoDeprecatedClassesRule::class,
				Rules\Upgrading\NoDeprecatedMembersRule::class,
				Rules\Upgrading\NoDeprecatedFunctionsRule::class,
				Rules\Upgrading\NoDeprecatedPhpArgumentsRule::class,
				Rules\Variables\NoGlobalStatementsRule::class,
				Rules\Variables\NoRepeatedAssignmentsRule::class,
				Rules\Variables\NoSeparateIssetsRule::class,
				Rules\Variables\NoSeparateUnsetsRule::class,
				Rules\Whitespace\AttributePositionRule::class,
				Rules\Whitespace\AttributeSpacingRule::class,
				Rules\Whitespace\BlockBlankLinesRule::class,
				Rules\Whitespace\BracesPositionRule::class,
				Rules\Whitespace\CommaSpacingRule::class,
				Rules\Whitespace\ConstructSpacingRule::class,
				Rules\Whitespace\DelimiterSpacingRule::class,
				Rules\Whitespace\IndentationRule::class,
				Rules\Whitespace\MemberBlankLinesRule::class,
				Rules\Whitespace\MultilineAttributeRule::class,
				Rules\Whitespace\NoStatementsSharingLineRule::class,
				Rules\Whitespace\SemicolonSpacingRule::class,
				Rules\Whitespace\SingleLevelIndentationRule::class,
				Rules\Whitespace\StatementBlankLinesRule::class,
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
					'imports' => 'a `use` import other than a group use on one line, which cannot be split',
					'strings' => 'a line that would fit were each of its string literals an empty pair of quotes, a string being never split',
					'argument' => 'the arguments of a call, of `new` and of an attribute, the variables of `unset()` and `isset()`',
					'parameter' => 'the parameters of a function or a method',
					'closureUse' => 'the variables of the `use` of a closure',
					'attribute' => 'the attributes of one group',
					'condition' => 'a condition of `if`, `elseif`, `while` and `do-while`',
					'chain' => 'a chain of method calls',
					'ternary' => 'a ternary',
					'for' => 'the header of a `for`',
					'logical' => 'the operands of a boolean operator outside a condition',
					'concatenation' => 'the operands of `.`',
					'arithmetic' => 'the operands of `+`, `-`, `*`, `/`, `%` and `**`',
					'bitwise' => 'the operands of `|`, `&`, `^`, `<<` and `>>`',
					'coalesce' => 'the operands of `??`',
					'pipe' => 'the operands of `|>`',
					'array' => 'the items of an array, which `multiline.maxWidth.array` spreads all the same',
					'list' => 'the items of `list()`',
					'implements' => 'the interfaces a class implements or an interface extends',
					'matchCondition' => 'the conditions of a `match` arm',
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
				new Decision(
					'spacing.keyword',
					new Shapes(['spaced' => ['return $x', 'a single space']]),
					'The space after a keyword followed by code on its line, `return`, `new`, `if`, `function`, a modifier and the words of the head of a class among them, and around a keyword continuing a statement, `else`, `catch`, `finally`, the `while` of a `do`, `as`, `insteadof` and the `use` of a closure, which stay on the line of what is before them',
				),
				new Decision(
					'multiline.operatorPosition.ternary',
					Domain::operatorPosition(),
					'Where `?`, `:` and `?:` of a ternary stand at a line break, of a ternary spread over lines too',
				),
				// where a comparison stands, which the rule of the conditions splits a condition that is one at
				new Decision(
					'multiline.operatorPosition.comparison',
					Domain::operatorPosition(),
					'Where a comparison stands at a line break: one ending its line moves only where the line joined after it would be too wide, one opening its line joins the line before it, what follows staying on its line unless it would be too wide',
				),

				// how far a function PHP optimizes is written out, which decides the arguments of its call too
				Rules\Namespaces\QualificationPolicy::createOptimizedDecision(
					'qualification.function.optimized',
					'A global function whose call PHP compiles to one opcode once it knows the function is global, `count()`, `strlen()`, `is_int()` and the others; a call of it written imported or with the backslash has its arguments passed positionally and an unpacked one reported',
					'`strlen()`',
					'`use function strlen;`',
					'`\strlen()`',
					'optimizes it',
					'A function is optimized in a namespace where one of its calls there is, an unpacked or named argument aside, `in_array()` only with a constant array, and every reference to it in that namespace is then written so; one none of whose calls is optimized is decided by `function.global`.',
				),

				// the lines below a doc comment, of a member as of a declaration among statements
				new Decision(
					'blankLines.afterPhpdoc',
					Domain::blankLines(),
					'Between a doc comment and the declaration it belongs to, or the first attribute of the declaration',
				),

				// the classes a native type takes as iterable
				new Decision(
					'types.traversableClasses',
					new Names,
					'Classes treated like `array` and `iterable`, whose annotation says what their items are',
					kind: DecisionKind::Parameter,
					default: ['Traversable'],
				),

				// the maps of what the libraries retired, those the project writes and those the upgrading files of the installed
				// packages lay under them, each read by its grammar
				new Decision(
					'upgrading.packages',
					new Flag,
					'Whether the code follows the upgrading files of the installed packages, their maps laid under those the project writes and the rules of the plugin of a package turned on where its files say',
					kind: DecisionKind::Parameter,
					default: false,
				),
				new Decision(
					PackageDiscovery::PhpDecision,
					Domain::state('forbidden'),
					'A function, a constant or a member PHP deprecated, written as what replaces it as the upgrading data of PHP say, `mb_convert_encoding()` for `utf8_encode()`, or reported',
				),
				new Decision(
					MemberMapGrammar::ReplacedClasses,
					new Map(new GrammarEntry, wildcards: false, grammar: self::createReplacedClassesGrammar(), caseInsensitive: true),
					'A class written instead of another one, both fully qualified (`Acme\\Old\\Mailer: Acme\\Mail\\Mailer`)',
				),
				new Decision(
					MemberMapGrammar::ReplacedFunctions,
					new Map(new GrammarEntry, wildcards: false, grammar: self::createReplacedFunctionsGrammar(), caseInsensitive: true),
					'A function written instead of another one (`acme_send: Acme\\Mail\\send`)',
				),
				new Decision(
					MemberMapGrammar::ReplacedMembers,
					new Map(
						new GrammarEntry,
						wildcards: false,
						grammar: self::createReplacedMembersGrammar(),
						canonicalize: MemberPattern::canonicalizeKey(...),
					),
					'A constant, a method or a property written instead of another one of the class (`Acme\\Mail\\Mailer::send(): sendMessage()`)',
				),
				new Decision(
					MemberMapGrammar::ReplacedCalls,
					new Map(
						new GrammarEntry,
						wildcards: false,
						grammar: self::createReplacedCallsGrammar(),
						canonicalize: MemberPattern::canonicalizeKey(...),
					),
					'A call written as the template says (`Acme\\Mail\\Mailer::send($to, $body): send(new Message($to, $body))`)',
				),
				new Decision(
					MemberMapGrammar::ForbiddenClasses,
					new Map(new GrammarEntry, wildcards: false, grammar: self::createForbiddenClassesGrammar(), caseInsensitive: true),
					'A class that may not be used, with what to do instead (`Acme\\Legacy\\Db: "use the repository"`)',
				),
				new Decision(
					MemberMapGrammar::ForbiddenFunctions,
					new Map(new GrammarEntry, grammar: self::createForbiddenFunctionsGrammar(), caseInsensitive: true),
					'A function that may not be called, with what to do instead',
				),
				new Decision(
					MemberMapGrammar::ForbiddenConstants,
					new Map(new GrammarEntry, grammar: self::createForbiddenConstantsGrammar()),
					'A global constant that may not be used, with what to do instead (`E_STRICT: "it no longer does anything"`)',
				),
				new Decision(
					MemberMapGrammar::ForbiddenMembers,
					new Map(
						new GrammarEntry,
						wildcards: false,
						grammar: self::createForbiddenMembersGrammar(),
						canonicalize: MemberPattern::canonicalizeKey(...),
					),
					'A constant, a method or a property that may not be used, with what to do instead',
				),
				new Decision(
					MemberMapGrammar::AttributeForAnnotation,
					new Map(new GrammarEntry, grammar: self::createAttributeForAnnotationGrammar()),
					'An attribute written for an annotation (`@ORM\\Entity: ORM\\Entity`)',
				),
				new Decision(
					MemberMapGrammar::AttributeForMember,
					new Map(
						new GrammarEntry,
						wildcards: false,
						grammar: self::createAttributeForMemberGrammar(),
						canonicalize: MemberPattern::canonicalizeKey(...),
					),
					'An attribute written for a member the class declares by convention',
				),

				// the newer constructs, decided once for every rule writing them
				new Decision(
					'upgrading.functions.arraySearchFunctions',
					Domain::state('required'),
					'`array_any()`, `array_all()`, `array_find()` and `array_find_key()` for a `foreach` or an `array_filter()` that only asks what they answer',
				),
				new Decision(
					'upgrading.syntax.firstClassCallables',
					Domain::state('required'),
					'`foo(...)` for `Closure::fromCallable()`, a forwarding closure and `\'self::foo\'`; from PHP 8.6 a partial application',
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
			'grouped' => 'the names of one namespace in one group use, `use Acme\Shop\{Order, Cart};`, from `imports.groupMinNames` of them on',
		], tolerance: true);
		return [
			new Decision(
				'imports.grouping.class',
				$shapes,
				'How the imports of classes are put into `use` statements, a name of the global namespace never grouped',
			),
			new Decision(
				'imports.grouping.function',
				$shapes,
				'How the imports of functions are put into `use` statements, a name of the global namespace never grouped',
			),
			new Decision(
				'imports.grouping.constant',
				$shapes,
				'How the imports of constants are put into `use` statements, a name of the global namespace never grouped',
			),
		];
	}


	private static function createReplacedClassesGrammar(): Schema
	{
		$name = fn() => Expect::string()->pattern(self::QualifiedName);
		return Expect::arrayOf($name(), $name())
			->description('The class → the class written instead, both fully qualified')
			->transform(function (array $options, Context $context): array {
				foreach ($options as $old => $new) {
					if (strcasecmp(ltrim((string) $old, '\\'), ltrim($new, '\\')) === 0) {
						$context->addError("The class `$old` is given as its own replacement.", 'dresscode.sameClass');
					}
				}

				return $options;
			});
	}


	private static function createReplacedFunctionsGrammar(): Schema
	{
		return Expect::arrayOf(
			Expect::string()->pattern(self::QualifiedName . '(::\w+)?'),
			Expect::string()->pattern(self::QualifiedName),
		)
			->description('The function, global or of a namespace → the function or the static method written instead')
			->transform(function (array $options, Context $context): array {
				foreach ($options as $old => $new) {
					if (strcasecmp(ltrim((string) $old, '\\'), ltrim($new, '\\')) === 0) {
						$context->addError("The function `$old()` is given as its own replacement.", 'dresscode.sameFunction');
					}
				}

				return $options;
			});
	}


	private static function createReplacedMembersGrammar(): Schema
	{
		return MemberMapGrammar::createMapSchema(
			MemberMapGrammar::createCodeSchema(),
			'The replaced member, `Class::name` (a constant or a method), `Class::name()` (a method) or `Class::$name` (a property) → the member written instead: its name alone in the same class, `Other::name` in another one, or `\function` for the global function a method becomes',
			MemberTarget::fromCode(...),
		);
	}


	private static function createReplacedCallsGrammar(): Schema
	{
		$write = Expect::anyOf(MemberMapGrammar::Removed, MemberMapGrammar::createCodeSchema());
		return MemberMapGrammar::createMapSchema(
			Expect::anyOf(
				$write,
				Expect::structure(['write' => (clone $write)->required(), 'because' => Expect::string()])->castTo('array'),
			),
			'The replaced use, `Class::name($a, true)`, `Class::name(...$args)` with any arguments, `Class::name()` without any, `Class::__construct($a)`, `Class::$name::get`, `Class::$name::set`, a magic method for the syntax PHP calls it by, or a call of a function, `name($a)` → the expression written instead, with the placeholders of the key, `$value` what is assigned, or `removed` for a call of a function or a method that does nothing; `{write: …, because: …}` where the rewrite may change what the code does',
			CallTemplate::fromEntry(...),
			CallTemplate::fromEntry(...),
		)->transform(CallTemplate::checkCycles(...));
	}


	private static function createForbiddenClassesGrammar(): Schema
	{
		return Expect::arrayOf(Expect::string()->nullable(), Expect::string()->pattern(self::QualifiedName))
			->description('The forbidden class, fully qualified → what to do instead, as the end of the message, or null for none');
	}


	private static function createForbiddenFunctionsGrammar(): Schema
	{
		return Expect::arrayOf(Expect::string()->nullable())
			->description('The forbidden function or a pattern with `*`, a name without a backslash meaning the global function → what to do instead, as the end of the message, or null for none');
	}


	private static function createForbiddenConstantsGrammar(): Schema
	{
		return Expect::arrayOf(Expect::string()->nullable())
			->description('The forbidden constant or a pattern with `*`, in its letter case, a name without a backslash meaning the global constant → what to do instead, as the end of the message, or null for none');
	}


	private static function createForbiddenMembersGrammar(): Schema
	{
		return MemberMapGrammar::createMapSchema(
			Expect::string()->nullable(),
			'The forbidden member, `Class::name` (a constant or a method), `Class::name(...$args)` (a method), `Class::$name` (a property), `Class::$name::get` or `::set` (a read or a write of it), `Class::__construct(...$args)`, or a call with the shape of its arguments, `Class::name()` being one without any → what to do instead, as the end of the message, or null for none',
		);
	}


	private static function createAttributeForAnnotationGrammar(): Schema
	{
		return Expect::arrayOf(
			MemberMapGrammar::createCodeSchema(),
			Expect::string()->pattern('@?[\w-]+|\\\\?\w+(?:\\\\\w+)+|\\\\\w+|\\\\?\w+(?:\\\\\w+)*\\\\\*'),
		)
			->description('The annotation, without the `@`, the class of an attribute, fully qualified, or a namespace of annotations, `Acme\Validation\*` → the attribute written instead, its class fully qualified, with its arguments where it has any, or the namespace of the attributes, `Acme\Validation\*`')
			->transform(function (array $options, Context $context): array {
				foreach ($options as $key => $code) {
					if (str_ends_with((string) $key, '*')) {
						if ($code !== MemberMapGrammar::Keep && AttributeTarget::findNamespace($code) === null) {
							$context->addError(
								"The namespace `$key` is written instead as "
								. Violation::formatCode($code)
								. ', which is not a namespace ending with `\\*`.',
								'dresscode.attributeCode',
							);
						}
					} elseif ($code !== MemberMapGrammar::Keep && AttributeTarget::fromCode($code) === null) {
						$old = str_contains((string) $key, '\\')
							? '#[' . ltrim((string) $key, '\\') . ']'
							: '@' . ltrim((string) $key, '@');
						$context->addError(
							'The attribute '
							. Violation::formatCode($code)
							. " written instead of `$old` is not a class with its arguments, `Class` or `Class(arguments)`.",
							'dresscode.attributeCode',
						);
					}
				}

				return $options;
			});
	}


	private static function createAttributeForMemberGrammar(): Schema
	{
		return Expect::arrayOf(Expect::string(), Expect::string())
			->description('The member of an ancestor, `Class::$name`, `Class::method()` or `\'Class::$name = literal\'` for that value only, or an interface the class implements → the attribute written instead, `$value` standing for the value of the property or what the method returns')
			->transform(function (array $options, Context $context): array {
				foreach ($options as $key => $value) {
					try {
						if ($value !== MemberMapGrammar::Keep) {
							AttributeForMemberEntry::fromEntry((string) $key, $value);
						}
					} catch (\InvalidArgumentException $e) {
						$context->addError($e->getMessage(), 'dresscode.memberMap');
					}
				}

				return $options;
			});
	}
}
