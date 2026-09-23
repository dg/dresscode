<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\Analyses\IndentationPlan;
use DressCode\{Decision, Domain, ImportStyle, Plugin, PluginManifest, Rules, Violation};
use DressCode\Domains\{Count, GrammarEntry, Map, Names, Words};
use DressCode\Rules\Upgrading\{AttributeTarget, CallTemplate, MemberMaps, MemberTarget};
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
	public const Standards = ['perCs', 'psr12', 'nette'];


	public function getManifest(): PluginManifest
	{
		// built once, the grammars of the maps above all, since nothing of it changes
		static $manifest;
		return $manifest ??= new PluginManifest(
			presets: [
				'dresscode/perCs' => dirname(__DIR__) . '/Presets/perCs.neon',
				'dresscode/psr12' => dirname(__DIR__) . '/Presets/psr12.neon',
				'dresscode/nette' => dirname(__DIR__) . '/Presets/nette.neon',
				'dresscode/cleanup' => dirname(__DIR__) . '/Presets/cleanup.neon',
				'dresscode/compilerOptimizations' => dirname(__DIR__) . '/Presets/compilerOptimizations.neon',
				'dresscode/correctness' => dirname(__DIR__) . '/Presets/correctness.neon',
				'dresscode/deprecations' => dirname(__DIR__) . '/Presets/deprecations.neon',
				'dresscode/modernizations' => dirname(__DIR__) . '/Presets/modernizations.neon',
				'dresscode/types' => dirname(__DIR__) . '/Presets/types.neon',
			],
			rules: [
				Rules\Arrays\ArraySpacingRule::class,
				Rules\Arrays\MultilineArrayRule::class,
				Rules\Arrays\NoManualListTestsRule::class,
				Rules\Arrays\NoNullArrayKeysRule::class,
				Rules\Arrays\NoLongArraySyntaxRule::class,
				Rules\Arrays\SpreadForArrayMergeRule::class,
				Rules\Arrays\TrailingCommaRule::class,
				Rules\Classes\ClassConstantForConstantCallRule::class,
				Rules\Classes\ClassHeadSpacingRule::class,
				Rules\Classes\ClassKeywordForStringRule::class,
				Rules\Classes\FinalForInternalClassRule::class,
				Rules\Classes\ClassNameNotationRule::class,
				Rules\Classes\ClassKindInNameRule::class,
				Rules\Classes\NameCasingRule::class,
				Rules\Classes\NoConstructorReturnValuesRule::class,
				Rules\Classes\NoFinalParentRule::class,
				Rules\Classes\NoNullDebugInfoRule::class,
				Rules\Classes\NoSleepAndWakeupRule::class,
				Rules\Classes\NoThisOutsideObjectRule::class,
				Rules\Classes\MemberOrderRule::class,
				Rules\Classes\OverrideAttributeRequiredRule::class,
				Rules\Classes\OverridingSignatureRule::class,
				Rules\Classes\PromotedPropertyForAssignmentRule::class,
				Rules\Classes\PublicWithSetVisibilityRule::class,
				Rules\Classes\ReadonlyClassForReadonlyPropertiesRule::class,
				Rules\Classes\ReadonlyForAnnotationRule::class,
				Rules\Classes\ReadonlyForUnwrittenPropertyRule::class,
				Rules\Classes\SelfForCurrentClassRule::class,
				Rules\Classes\NoGroupedDeclarationsRule::class,
				Rules\Classes\NoMembersSharingLineRule::class,
				Rules\Classes\StaticForMethodWithoutThisRule::class,
				Rules\Classes\StaticSetStateRequiredRule::class,
				Rules\Classes\StringableRequiredRule::class,
				Rules\Classes\UselessModifierRule::class,
				Rules\Classes\UselessNullInitializationRule::class,
				Rules\Classes\UselessOverridingMethodRule::class,
				Rules\Classes\UselessReturnTypeWillChangeRule::class,
				Rules\Classes\VisibilityRequiredRule::class,
				Rules\Classes\ModifierOrderRule::class,
				Rules\Comments\CommentSpacingRule::class,
				Rules\Comments\NoEmptyCommentsRule::class,
				Rules\Comments\NoHashCommentsRule::class,
				Rules\ControlFlow\ArraySearchForFilterRule::class,
				Rules\ControlFlow\ArraySearchForForeachRule::class,
				Rules\ControlFlow\NoBracelessBodiesRule::class,
				Rules\ControlFlow\EarlyExitForTrailingIfRule::class,
				Rules\ControlFlow\ElseifNotationRule::class,
				Rules\ControlFlow\FallThroughCommentRule::class,
				Rules\ControlFlow\MatchForSwitchRule::class,
				Rules\ControlFlow\MultilineConditionRule::class,
				Rules\ControlFlow\NoAlternativeSyntaxRule::class,
				Rules\ControlFlow\NoContinueInSwitchRule::class,
				Rules\ControlFlow\NoEmptyStatementsRule::class,
				Rules\ControlFlow\NoReturnsInFinallyRule::class,
				Rules\ControlFlow\NoUnreachableCatchesRule::class,
				Rules\ControlFlow\NoRepeatedCatchesRule::class,
				Rules\ControlFlow\ThrowableForExceptionRule::class,
				Rules\ControlFlow\ReturnForBooleanIfRule::class,
				Rules\ControlFlow\SwitchCaseNotationRule::class,
				Rules\ControlFlow\SwitchCaseSpacingRule::class,
				Rules\ControlFlow\TernaryForIfRule::class,
				Rules\ControlFlow\ThrowExpressionForNullGuardRule::class,
				Rules\ControlFlow\UselessBracesRule::class,
				Rules\ControlFlow\UselessCatchVariableRule::class,
				Rules\ControlFlow\UselessParenthesesAfterConstructRule::class,
				Rules\ControlFlow\UselessElseRule::class,
				Rules\ControlFlow\UselessReturnRule::class,
				Rules\Expressions\BinaryOperatorSpacingRule::class,
				Rules\Expressions\CastCanonicalTypeRule::class,
				Rules\Expressions\CastSpacingRule::class,
				Rules\Expressions\CloneWithNotationRule::class,
				Rules\Expressions\CombinedAssignmentForRepeatedTargetRule::class,
				Rules\Expressions\ConcatenationSpacingRule::class,
				Rules\Expressions\DoubleColonSpacingRule::class,
				Rules\Expressions\EmptyArgumentParenthesesRule::class,
				Rules\Expressions\ExplicitPrecedenceRequiredRule::class,
				Rules\Expressions\IncrementForAddOneRule::class,
				Rules\Expressions\MultilineChainRule::class,
				Rules\Expressions\MultilineTernaryRule::class,
				Rules\Expressions\NoErrorSuppressionRule::class,
				Rules\Expressions\NoDoubleNegationsRule::class,
				Rules\Expressions\NotEqualsNotationRule::class,
				Rules\Expressions\NullCoalescingForNullTernaryRule::class,
				Rules\Expressions\NullsafeForGuardedAccessRule::class,
				Rules\Expressions\ObjectOperatorSpacingRule::class,
				Rules\Expressions\OffsetBracketSpacingRule::class,
				Rules\Expressions\PipeForNestedCallsRule::class,
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
				Rules\Functions\ArrayFirstLastNotationRule::class,
				Rules\Functions\ArrowFunctionForClosureRule::class,
				Rules\Functions\ClampForMinMaxRule::class,
				Rules\Functions\NoDebugOutputRule::class,
				Rules\Functions\CsvEscapeArgumentRequiredRule::class,
				Rules\Functions\CallableNotationRule::class,
				Rules\Functions\FirstClassCallableForStringRule::class,
				Rules\Functions\FunctionNameSpacingRule::class,
				Rules\Functions\GetDebugTypeForTernaryRule::class,
				Rules\Functions\JsonValidateForDecodeRule::class,
				Rules\Functions\MultilineCallRule::class,
				Rules\Functions\MultilineSignatureRule::class,
				Rules\Functions\NamedArgumentSpacingRule::class,
				Rules\Functions\NewInitializerForNullDefaultRule::class,
				Rules\Functions\NoAliasFunctionsRule::class,
				Rules\Functions\NoCallUserFuncRule::class,
				Rules\Functions\NoConversionFunctionsRule::class,
				Rules\Functions\NoExplicitInvokeCallsRule::class,
				Rules\Functions\NoDirnameOfFileRule::class,
				Rules\Functions\NoInnerFunctionsRule::class,
				Rules\Functions\NoIsNullRule::class,
				Rules\Functions\NoManualEmptyStringTestsRule::class,
				Rules\Functions\NoManualSubstringTestsRule::class,
				Rules\Functions\NoSettypeRule::class,
				Rules\Functions\RoundingModeNotationRule::class,
				Rules\Functions\SensitiveParameterRequiredRule::class,
				Rules\Functions\StaticForClosureWithoutThisRule::class,
				Rules\Functions\StrictComparisonArgumentRequiredRule::class,
				Rules\Functions\RedundantArgumentsRule::class,
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
				Rules\Namespaces\MultilineImportRule::class,
				Rules\Namespaces\BuiltinNameCasingRule::class,
				Rules\Namespaces\NoReservedNamesRule::class,
				Rules\Namespaces\NoUnlistedNamespacedDeclarationsRule::class,
				Rules\Namespaces\ImportOrderRule::class,
				Rules\Namespaces\GlobalNameQualificationRule::class,
				Rules\Namespaces\ForeignNameQualificationRule::class,
				Rules\Namespaces\OptimizedCallNotationRule::class,
				Rules\Namespaces\UselessLeadingBackslashRule::class,
				Rules\Namespaces\NoUnusedImportsRule::class,
				Rules\Namespaces\UselessAliasRule::class,
				Rules\Namespaces\UselessCurrentNamespaceImportRule::class,
				Rules\PhpDoc\AnnotationCasingRule::class,
				Rules\PhpDoc\PhpdocAboveAttributesRule::class,
				Rules\PhpDoc\DeprecatedAttributeForAnnotationRule::class,
				Rules\PhpDoc\AssertForInlineVarRule::class,
				Rules\PhpDoc\ForbiddenAnnotationsRule::class,
				Rules\PhpDoc\ForbiddenPhpdocLinesRule::class,
				Rules\PhpDoc\NoConsecutivePhpdocsRule::class,
				Rules\PhpDoc\NoEmptyPhpdocsRule::class,
				Rules\PhpDoc\PhpdocAlignmentRule::class,
				Rules\PhpDoc\NoInvalidAnnotationsRule::class,
				Rules\PhpDoc\PhpdocBlankLinesRule::class,
				Rules\PhpDoc\PhpdocTypeNotationRule::class,
				Rules\PhpDoc\PromotedPropertyAnnotationPositionRule::class,
				Rules\PhpDoc\NoPlainPropertyCommentsRule::class,
				Rules\PhpDoc\SinglelinePropertyPhpdocRule::class,
				Rules\PhpDoc\UselessConstantVarAnnotationRule::class,
				Rules\PhpDoc\UselessFunctionPhpdocRule::class,
				Rules\PhpDoc\UselessInheritdocRule::class,
				Rules\Types\NeverForThrowingFunctionRule::class,
				Rules\Types\NullableTypeForDefaultNullRule::class,
				Rules\Types\NativeTypeRequiredRule::class,
				Rules\Types\ConstantTypeRequiredRule::class,
				Rules\Types\TypeDeclarationSpacingRule::class,
				Rules\Types\TypeNotationRule::class,
				// what a map replaces is written first, so that what it forbids is reported of what remains
				Rules\Upgrading\ReplacedClassesRule::class,
				Rules\Upgrading\ReplacedFunctionsRule::class,
				Rules\Upgrading\ReplacedMembersRule::class,
				Rules\Upgrading\ReplacedCallsRule::class,
				Rules\Upgrading\ForbiddenClassesRule::class,
				Rules\Upgrading\ForbiddenFunctionsRule::class,
				Rules\Upgrading\ForbiddenMembersRule::class,
				Rules\Upgrading\AttributeForAnnotationRule::class,
				Rules\Upgrading\NoDeprecatedClassesRule::class,
				Rules\Upgrading\NoDeprecatedMembersRule::class,
				Rules\Upgrading\NoDeprecatedPhpCallsRule::class,
				Rules\Variables\NoSeparateIssetsRule::class,
				Rules\Variables\NoSeparateUnsetsRule::class,
				Rules\Variables\NoRepeatedAssignmentsRule::class,
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
				...self::createImportDecisions(),

				// how far a global function is written out, which decides the arguments of its optimized call too
				Rules\Namespaces\QualificationPolicy::createGlobalDecision(
					'qualification.globalFunction',
					'A global function called in a namespace, `strlen()`, those the compiler optimizes included unless `optimizedFunction` requires a form for them',
					'`strlen()`',
					'`use function strlen;` and `strlen()`',
					'`\strlen()`',
					'Not a function of the namespace of the file, nor a name in a file without a namespace, which `inFileWithoutNamespace` decides.',
				),
				Rules\Namespaces\QualificationPolicy::createOptimizedDecision(
					'qualification.optimizedFunction',
					'A global function whose call PHP compiles to one opcode once it knows the function is global, `count()`, `strlen()`, `is_int()` and the others; where this key requires a form, it decides such a function over `globalFunction`, and a call of it written imported or with the backslash, by either key, has its arguments passed positionally and an unpacked one reported',
					'`strlen()`',
					'`use function strlen;`',
					'`\strlen()`',
					'optimizes it',
					'A function is optimized in a namespace where one of its calls there is, an unpacked or named argument aside, and every reference to it in that namespace is then written so.',
				),

				// the classes a native type takes as iterable
				new Decision('types.traversableClasses', new Names, 'Classes treated like `array` and `iterable`, whose annotation says what their items are', parameter: true, default: ['Traversable']),

				// the maps of what the libraries retired, those the project writes and those the upgrading files of the installed
				// packages lay under them, each read by its grammar
				new Decision('upgrading.libraries.packages', new Words([
					'adopted' => 'the maps of the upgrading files of the installed packages lie under those the project writes',
					'ignored' => 'the maps are those the project writes alone',
				]), 'Whether what the upgrading files of the installed packages say is written as they say', parameter: true, default: 'ignored'),
				new Decision('upgrading.libraries.replacedClasses', new Map(new GrammarEntry, grammar: self::createReplacedClassesGrammar(), caseInsensitive: true), 'A class written instead of another one, both fully qualified (`Acme\\Old\\Mailer: Acme\\Mail\\Mailer`)'),
				new Decision('upgrading.libraries.replacedFunctions', new Map(new GrammarEntry, grammar: self::createReplacedFunctionsGrammar(), caseInsensitive: true), 'A function written instead of another one (`acme_send: Acme\\Mail\\send`)'),
				new Decision('upgrading.libraries.replacedMembers', new Map(new GrammarEntry, grammar: self::createReplacedMembersGrammar()), 'A constant, a method or a property written instead of another one of the class (`Acme\\Mail\\Mailer::send(): sendMessage()`)'),
				new Decision('upgrading.libraries.replacedCalls', new Map(new GrammarEntry, grammar: self::createReplacedCallsGrammar()), 'A call written as the template says (`Acme\\Mail\\Mailer::send($to, $body): send(new Message($to, $body))`)'),
				new Decision('upgrading.libraries.forbiddenClasses', new Map(new GrammarEntry, grammar: self::createForbiddenClassesGrammar(), caseInsensitive: true), 'A class that may not be used, with what to do instead (`Acme\\Legacy\\Db: "use the repository"`)'),
				new Decision('upgrading.libraries.forbiddenFunctions', new Map(new GrammarEntry, grammar: self::createForbiddenFunctionsGrammar(), caseInsensitive: true), 'A function that may not be called, with what to do instead'),
				new Decision('upgrading.libraries.forbiddenMembers', new Map(new GrammarEntry, grammar: self::createForbiddenMembersGrammar()), 'A constant, a method or a property that may not be used, with what to do instead'),
				new Decision('upgrading.libraries.attributeForAnnotation', new Map(new GrammarEntry, grammar: self::createAttributeForAnnotationGrammar()), 'An attribute written for an annotation (`@ORM\\Entity: ORM\\Entity`)'),

				// the newer constructs, decided once for every rule writing them
				new Decision('upgrading.functions.arraySearchFunctions', Domain::adopted(), '`array_any()`, `array_all()`, `array_find()` and `array_find_key()` for a `foreach` or an `array_filter()` that only asks what they answer'),
				new Decision('upgrading.syntax.firstClassCallables', Domain::adopted(), '`foo(...)` for `Closure::fromCallable()`, a forwarding closure and `\'self::foo\'`; from PHP 8.6 a partial application'),
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


	/** @return list<Decision>  the shape of the imports, which an import a rule adds takes too */
	private static function createImportDecisions(): array
	{
		$shapes = new Words([
			'separate' => 'a `use` of its own for every name, `use Foo; use Bar;`',
			'combined' => 'all names of the kind in one `use`, `use Foo, Bar;`, one per namespace declaration',
		]);
		return [
			new Decision('imports.class', $shapes, 'How the imports of classes are spread over `use` statements'),
			new Decision('imports.function', $shapes, 'How the imports of functions are spread over `use` statements'),
			new Decision('imports.constant', $shapes, 'How the imports of constants are spread over `use` statements'),
			new Decision(ImportStyle::GroupUse, new Words([
				'forbidden' => 'a group use of a kind decided above is expanded into the shape of that kind',
				'required' => 'the imports of one namespace are written as one group use, `use Acme\Shop\{Order, Cart};`',
			]), 'The group use, a kind written `combined` never grouped and a name of the global namespace standing apart'),
		];
	}


	private static function createReplacedClassesGrammar(): Schema
	{
		$name = fn() => Expect::string()->pattern('\\\\?\w+(\\\\\w+)*');
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
			Expect::string()->pattern('\\\\?\w+(\\\\\w+)*'),
			Expect::string()->pattern('\\\\?\w+(\\\\\w+)*'),
		)
			->description('The function, global or of a namespace → the function written instead')
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
		return MemberMaps::createMapSchema(
			MemberMaps::createCodeSchema(),
			'The replaced member, `Class::name` (a constant or a method), `Class::name()` (a method) or `Class::$name` (a property) → the member written instead: its name alone in the same class, `Other::name` in another one, or `\function` for the global function a method becomes',
			MemberTarget::fromCode(...),
		);
	}


	private static function createReplacedCallsGrammar(): Schema
	{
		return MemberMaps::createMapSchema(
			MemberMaps::createCodeSchema(),
			'The replaced use, `Class::name($a, true)`, `Class::name(...$args)` with any arguments, `Class::name()` without any, `Class::__construct($a)`, `Class::$name::get`, `Class::$name::set` or a magic method for the syntax PHP calls it by → the expression written instead, with the placeholders of the key, `$value` what is assigned',
			CallTemplate::fromEntry(...),
		)->transform(CallTemplate::checkCycles(...));
	}


	private static function createForbiddenClassesGrammar(): Schema
	{
		return Expect::arrayOf(Expect::string()->nullable(), Expect::string()->pattern('\\\\?\w+(\\\\\w+)*'))
			->description('The forbidden class, fully qualified → what to do instead, as the end of the message, or null for none');
	}


	private static function createForbiddenFunctionsGrammar(): Schema
	{
		return Expect::arrayOf(Expect::string()->nullable())
			->description('The forbidden function or a pattern with `*`, a name without a backslash meaning the global function → what to do instead, as the end of the message, or null for none');
	}


	private static function createForbiddenMembersGrammar(): Schema
	{
		return MemberMaps::createMapSchema(
			Expect::string()->nullable(),
			'The forbidden member, `Class::name` (a constant or a method), `Class::name(...$args)` (a method), `Class::$name` (a property), `Class::$name::get` or `::set` (a read or a write of it), `Class::__construct(...$args)`, or a call with the shape of its arguments, `Class::name()` being one without any → what to do instead, as the end of the message, or null for none',
		);
	}


	private static function createAttributeForAnnotationGrammar(): Schema
	{
		return Expect::arrayOf(MemberMaps::createCodeSchema(), Expect::string()->pattern('@?[\w-]+|\\\\?\w+(?:\\\\\w+)+|\\\\\w+|\\\\?\w+(?:\\\\\w+)*\\\\\*'))
			->description('The annotation, without the `@`, the class of an attribute, fully qualified, or a namespace of annotations, `Acme\Validation\*` → the attribute written instead, its class fully qualified, with its arguments where it has any, or the namespace of the attributes, `Acme\Validation\*`')
			->transform(function (array $options, Context $context): array {
				foreach ($options as $key => $code) {
					if (str_ends_with((string) $key, '*')) {
						if ($code !== MemberMaps::Keep && AttributeTarget::findNamespace($code) === null) {
							$context->addError("The namespace `$key` is written instead as " . Violation::formatCode($code) . ', which is not a namespace ending with `\\*`.', 'dresscode.attributeCode');
						}
					} elseif ($code !== MemberMaps::Keep && AttributeTarget::fromCode($code) === null) {
						$old = str_contains((string) $key, '\\') ? '#[' . ltrim((string) $key, '\\') . ']' : '@' . ltrim((string) $key, '@');
						$context->addError('The attribute ' . Violation::formatCode($code) . " written instead of `$old` is not a class with its arguments, `Class` or `Class(arguments)`.", 'dresscode.attributeCode');
					}
				}

				return $options;
			});
	}
}
