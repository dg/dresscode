<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\ConfigurationException;
use DressCode\Interop\Translator;
use DressCode\Preset;
use DressCode\PresetInfo;
use DressCode\Presets;
use DressCode\Rule;
use DressCode\RuleInfo;
use DressCode\Rules;
use Nette\Utils\Helpers;
use function strlen;


/**
 * Rule and preset classes known to a run, by name, alias or class; a name may belong to one class only.
 * A name without a vendor is the built-in one of that name, so 'per' is 'dresscode/per'.
 * @internal
 */
final class RuleRegistry
{
	private const Vendor = 'dresscode/';

	private const BuiltinRules = [
		Rules\Expressions\OffsetBracketSpacingRule::class,
		Rules\Arrays\ArraySpacingRule::class,
		Rules\Arrays\ShortArraySyntaxRule::class,
		Rules\Arrays\TrailingCommaRule::class,
		Rules\Arrays\MultiLineArrayRule::class,
		Rules\Whitespace\AttributeSpacingRule::class,
		Rules\Expressions\UselessAttributeParenthesesRule::class,
		Rules\Whitespace\AttributePositionRule::class,
		Rules\Whitespace\BracesPositionRule::class,
		Rules\ControlFlow\ControlStructureBracesRule::class,
		Rules\ControlFlow\UselessBracesRule::class,
		Rules\Classes\ClassDefinitionSpacingRule::class,
		Rules\Namespaces\ClassReferenceNameCasingRule::class,
		Rules\Classes\FinalInternalClassRule::class,
		Rules\Classes\ModernClassNameReferenceRule::class,
		Rules\Classes\NameCasingRule::class,
		Rules\Expressions\UselessParenthesesAroundNewRule::class,
		Rules\Expressions\NewArgumentParenthesesRule::class,
		Rules\Classes\UselessNullPropertyInitializationRule::class,
		Rules\Classes\KindInClassNameRule::class,
		Rules\Classes\OrderedMembersRule::class,
		Rules\Classes\SelfForCurrentClassRule::class,
		Rules\Classes\SingleMemberPerDeclarationRule::class,
		Rules\Classes\SingleMemberPerLineRule::class,
		Rules\Classes\UselessModifierRule::class,
		Rules\Classes\VisibilityRequiredRule::class,
		Rules\Comments\CommentedOutFunctionRule::class,
		Rules\Comments\CommentSpacingRule::class,
		Rules\Comments\NoEmptyCommentRule::class,
		Rules\Comments\NoHashCommentRule::class,
		Rules\ControlFlow\NoUnreachableCatchRule::class,
		Rules\ControlFlow\EarlyExitRule::class,
		Rules\ControlFlow\ElseifKeywordRule::class,
		Rules\ControlFlow\TernaryForSimpleBranchRule::class,
		Rules\ControlFlow\MultiLineConditionRule::class,
		Rules\ControlFlow\NoAlternativeSyntaxRule::class,
		Rules\ControlFlow\FallThroughCommentRule::class,
		Rules\ControlFlow\NoEmptyStatementRule::class,
		Rules\ControlFlow\UselessConstructParenthesesRule::class,
		Rules\ControlFlow\UselessElseRule::class,
		Rules\ControlFlow\UselessReturnRule::class,
		Rules\ControlFlow\UselessCatchVariableRule::class,
		Rules\ControlFlow\ReferenceThrowableOnlyRule::class,
		Rules\ControlFlow\SwitchCaseColonRule::class,
		Rules\ControlFlow\SwitchCaseSpacingRule::class,
		Rules\ControlFlow\NoContinueInSwitchRule::class,
		Rules\ControlFlow\UselessIfConditionWithReturnRule::class,
		Rules\Files\NoBomRule::class,
		Rules\Files\NoInvisibleCharactersRule::class,
		Rules\Files\FullOpeningTagRule::class,
		Rules\Files\LineEndingRule::class,
		Rules\Files\LineLengthRule::class,
		Rules\Files\NoClosingTagRule::class,
		Rules\Files\StrictTypesRequiredRule::class,
		Rules\Functions\ArrowFunctionRule::class,
		Rules\Functions\ForbiddenFunctionsRule::class,
		Rules\Functions\MultiLineCallRule::class,
		Rules\Functions\NoDirnameOfFileRule::class,
		Rules\Functions\MultiLineSignatureRule::class,
		Rules\Functions\NamedArgumentSpacingRule::class,
		Rules\Functions\NativeFunctionCasingRule::class,
		Rules\Functions\NoAliasFunctionsRule::class,
		Rules\Functions\NoDeprecatedFunctionsRule::class,
		Rules\Functions\NoDirectInvokeCallRule::class,
		Rules\Functions\NoInnerFunctionsRule::class,
		Rules\Functions\FunctionNameSpacingRule::class,
		Rules\Functions\UselessParameterDefaultRule::class,
		Rules\Functions\NoUnpackingInOptimizedCallRule::class,
		Rules\Functions\NoSettypeRule::class,
		Rules\Functions\StaticClosureRule::class,
		Rules\Functions\StrictCallRule::class,
		Rules\Namespaces\GlobalImportsRule::class,
		Rules\Namespaces\ImportNotationRule::class,
		Rules\Namespaces\NoLeadingBackslashInImportRule::class,
		Rules\Namespaces\OrderedImportsRule::class,
		Rules\Namespaces\ReferenceUsedNamesOnlyRule::class,
		Rules\Namespaces\UnusedImportsRule::class,
		Rules\Namespaces\UseFromSameNamespaceRule::class,
		Rules\Namespaces\UselessAliasRule::class,
		Rules\Literals\ConstantCasingRule::class,
		Rules\Literals\KeywordCasingRule::class,
		Rules\Namespaces\NoLeadingBackslashInGlobalNamespaceRule::class,
		Rules\Literals\NumericLiteralSeparatorRule::class,
		Rules\Literals\OctalNotationRule::class,
		Rules\Expressions\BinaryOperatorSpacingRule::class,
		Rules\Functions\NoConversionFunctionsRule::class,
		Rules\Expressions\CastSpacingRule::class,
		Rules\Expressions\CastCanonicalTypeRule::class,
		Rules\Expressions\CombinedAssignmentOperatorRule::class,
		Rules\Expressions\ConcatSpacingRule::class,
		Rules\Expressions\DoubleColonSpacingRule::class,
		Rules\Expressions\ExplicitOperatorPrecedenceRule::class,
		Rules\Functions\NoIsNullRule::class,
		Rules\Expressions\NoShortBoolCastRule::class,
		Rules\Expressions\YodaRule::class,
		Rules\Expressions\NotEqualsOperatorRule::class,
		Rules\Expressions\NullCoalescingOperatorRule::class,
		Rules\Expressions\ObjectOperatorSpacingRule::class,
		Rules\Expressions\MultiLineChainRule::class,
		Rules\Expressions\ReferenceSpacingRule::class,
		Rules\Expressions\SpreadOperatorSpacingRule::class,
		Rules\Expressions\IncrementOperatorRule::class,
		Rules\Expressions\StrictComparisonRule::class,
		Rules\Expressions\SymbolicLogicalOperatorsRule::class,
		Rules\Expressions\TernaryOperatorSpacingRule::class,
		Rules\Expressions\MultiLineTernaryRule::class,
		Rules\Expressions\ShortTernaryOperatorRule::class,
		Rules\Expressions\UnaryOperatorSpacingRule::class,
		Rules\Expressions\UselessTernaryOperatorRule::class,
		Rules\PhpDoc\AnnotationNameRule::class,
		Rules\PhpDoc\AttributeAfterPhpDocRule::class,
		Rules\PhpDoc\PhpDocAlignmentRule::class,
		Rules\PhpDoc\ForbiddenAnnotationsRule::class,
		Rules\PhpDoc\ForbiddenPhpDocLinesRule::class,
		Rules\PhpDoc\NoDuplicateReturnAnnotationRule::class,
		Rules\PhpDoc\NoEmptyPhpDocRule::class,
		Rules\PhpDoc\NoUnknownParamAnnotationRule::class,
		Rules\PhpDoc\PhpDocNullLastRule::class,
		Rules\PhpDoc\PropertyPhpDocSingleLineRule::class,
		Rules\PhpDoc\PhpDocCanonicalTypesRule::class,
		Rules\PhpDoc\PhpDocTrimRule::class,
		Rules\PhpDoc\PropertyPhpDocRequiredRule::class,
		Rules\PhpDoc\PropertyVarAnnotationRule::class,
		Rules\PhpDoc\PromotedPropertyAnnotationPositionRule::class,
		Rules\PhpDoc\NoEmptyVarAnnotationRule::class,
		Rules\PhpDoc\UselessConstantVarAnnotationRule::class,
		Rules\PhpDoc\UselessFunctionPhpDocRule::class,
		Rules\PhpDoc\UselessInheritDocRule::class,
		Rules\Literals\NoBacktickOperatorRule::class,
		Rules\Literals\ComplexStringVariableRule::class,
		Rules\Literals\NoImplicitBackslashRule::class,
		Rules\Literals\HeredocIndentationRule::class,
		Rules\Literals\NowdocWithoutInterpolationRule::class,
		Rules\Literals\MagicConstantCasingRule::class,
		Rules\Literals\NoTrailingWhitespaceInStringRule::class,
		Rules\Literals\UselessStringConcatRule::class,
		Rules\Literals\StringQuotesRule::class,
		Rules\PhpDoc\ExplicitAssertionRule::class,
		Rules\Types\NullableTypeForDefaultNullRule::class,
		Rules\Types\TypeHintRequiredRule::class,
		Rules\Types\TypeHintSpacingRule::class,
		Rules\Types\UnionTypeFormatRule::class,
		Rules\Variables\CombinedIssetsRule::class,
		Rules\Variables\CombinedUnsetsRule::class,
		Rules\Variables\NoDuplicateAssignmentRule::class,
		Rules\Variables\NoGlobalKeywordRule::class,
		Rules\Classes\NoThisInStaticContextRule::class,
		Rules\Whitespace\CommaSpacingRule::class,
		Rules\Files\DeclareSpacingRule::class,
		Rules\Whitespace\BlankLinesRule::class,
		Rules\Whitespace\SemicolonSpacingRule::class,
		Rules\Whitespace\ParenthesesSpacingRule::class,
		Rules\Files\NoTrailingWhitespaceRule::class,
		Rules\ControlFlow\SingleStatementPerLineRule::class,
		Rules\Files\EofNewlineRule::class,
		Rules\Whitespace\ConstructSpacingRule::class,
		Rules\Whitespace\IndentationRule::class,
		Rules\Whitespace\SingleLevelIndentationRule::class,
	];

	/** @var array<string, class-string<Rule>>  name → class */
	private array $rules = [];

	/** @var array<string, class-string<Preset>>  name → class */
	private array $presets = [];


	public function __construct(
		private readonly Translator $translator = new Translator,
	) {
		$this->registerPreset(Presets\Per::class);
		$this->registerPreset(Presets\Psr12::class);
		$this->registerPreset(Presets\Nette::class);
		$this->registerPreset(Presets\Symfony::class);
		$this->registerPreset(Presets\NetteStyle::class);
		$this->registerPreset(Presets\Cleanup::class);
		$this->registerPreset(Presets\Modern::class);
		$this->registerPreset(Presets\Types::class);
		$this->registerPreset(Presets\PhpDoc::class);
		$this->registerPreset(Presets\Imports::class);
		$this->registerPreset(Presets\Classes::class);
		foreach (self::BuiltinRules as $class) {
			$this->registerRule($class);
		}
	}


	/**
	 * Returns the name of the rule.
	 * @param  class-string<Rule>  $class
	 * @throws ConfigurationException  when the name or an alias belongs to another rule
	 */
	public function registerRule(string $class): string
	{
		if (!is_subclass_of($class, Rule::class)) {
			throw new ConfigurationException("Class $class is not a rule.");
		}

		$info = RuleInfo::of($class);
		$existing = $this->rules[$info->name] ?? null;
		if ($existing !== null && $existing !== $class) {
			throw new ConfigurationException("Rule name '$info->name' is used by both $existing and $class.");
		}

		$this->rules[$info->name] = $class;
		return $info->name;
	}


	/**
	 * Class of the rule given by name or class; a class is registered on the way. A name of another tool
	 * is not a name here: it is translated together with its options by `dresscode import`.
	 * @return class-string<Rule>
	 * @throws ConfigurationException
	 */
	public function resolveRule(string $rule): string
	{
		if (class_exists($rule)) {
			/** @var class-string<Rule> $rule */
			$this->registerRule($rule);
			return $rule;
		}

		$class = $this->rules[$rule] ?? $this->rules[self::Vendor . $rule] ?? null;
		if ($class !== null) {
			return $class;
		}

		$covered = $this->translator->findRules($rule);
		$hint = $covered
			? ' It is covered by ' . implode(' and ', $covered) . '; run `dresscode import` to translate a configuration of another tool.'
			: self::suggest($rule, array_keys($this->rules));
		throw new ConfigurationException("Unknown rule '$rule'.$hint");
	}


	/**
	 * " Did you mean 'x'?" for the nearest of the known names, empty when none is near enough; the name
	 * is compared without its vendor as well, so that a slug typed alone finds its rule.
	 * @param  list<string>  $known
	 */
	private static function suggest(string $name, array $known): string
	{
		$bare = array_map(fn(string $item) => substr($item, strlen(self::Vendor)), $known);
		$hint = Helpers::getSuggestion($known, $name) ?? Helpers::getSuggestion($bare, $name);
		return $hint === null ? '' : " Did you mean '$hint'?";
	}


	/**
	 * Rules a name in a suppression comment stands for: its own, or those covering it when it belongs
	 * to another tool; empty when nothing does.
	 * @return list<string>
	 */
	public function resolveNames(string $rule): array
	{
		return match (true) {
			isset($this->rules[$rule]) => [$rule],
			isset($this->rules[self::Vendor . $rule]) => [self::Vendor . $rule],
			default => $this->translator->findRules($rule),
		};
	}


	/** @return array<string, class-string<Rule>>  name → class */
	public function getRules(): array
	{
		return $this->rules;
	}


	/**
	 * Returns the name of the preset.
	 * @param  class-string<Preset>  $class
	 * @throws ConfigurationException
	 */
	public function registerPreset(string $class): string
	{
		if (!is_subclass_of($class, Preset::class)) {
			throw new ConfigurationException("Class $class is not a preset.");
		}

		$name = PresetInfo::of($class)->name;
		$existing = $this->presets[$name] ?? null;
		if ($existing !== null && $existing !== $class) {
			throw new ConfigurationException("Preset name '$name' is used by both $existing and $class.");
		}

		$this->presets[$name] = $class;
		return $name;
	}


	/**
	 * @return class-string<Preset>
	 * @throws ConfigurationException
	 */
	public function resolvePreset(string $preset): string
	{
		if (class_exists($preset)) {
			/** @var class-string<Preset> $preset */
			$this->registerPreset($preset);
			return $preset;
		}

		$class = $this->presets[$preset] ?? $this->presets[self::Vendor . $preset] ?? null;
		return $class ?? throw new ConfigurationException(
			"Unknown preset '$preset'." . self::suggest($preset, array_keys($this->presets)),
		);
	}


	/**
	 * Class of the rule or of the preset given by name or class, for a place that takes either; a name that
	 * belongs to both is an ambiguity, not a preference.
	 * @return class-string<Rule>|class-string<Preset>
	 * @throws ConfigurationException
	 */
	public function resolveRuleOrPreset(string $name): string
	{
		$isClass = class_exists($name);
		$rule = $isClass
			? (is_subclass_of($name, Rule::class) ? $name : null)
			: $this->rules[$name] ?? $this->rules[self::Vendor . $name] ?? null;
		$preset = $isClass
			? (is_subclass_of($name, Preset::class) ? $name : null)
			: $this->presets[$name] ?? $this->presets[self::Vendor . $name] ?? null;
		return match (true) {
			$rule !== null && $preset !== null => throw new ConfigurationException("'$name' names both a rule and a preset; name it by its class."),
			$rule !== null => $this->resolveRule($rule),
			$preset !== null => $this->resolvePreset($preset),
			default => throw new ConfigurationException(
				"Unknown rule or preset '$name'." . self::suggest($name, [...array_keys($this->rules), ...array_keys($this->presets)]),
			),
		};
	}


	/** @return array<string, class-string<Preset>>  name → class */
	public function getPresets(): array
	{
		return $this->presets;
	}


	public function getTranslator(): Translator
	{
		return $this->translator;
	}
}
