<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\ConfigurationException;
use DressCode\Preset;
use DressCode\PresetInfo;
use DressCode\Presets;
use DressCode\Rule;
use DressCode\RuleInfo;
use DressCode\Rules;


/**
 * Rule and preset classes known to a run, by name, alias or class; a name may belong to one class only.
 * @internal
 */
final class RuleRegistry
{
	private const BuiltInRules = [
		Rules\Expressions\OffsetBracketSpacingRule::class,
		Rules\Arrays\ArraySpacingRule::class,
		Rules\Arrays\ShortArraySyntaxRule::class,
		Rules\Arrays\TrailingCommaRule::class,
		Rules\ControlFlow\ContinuationPositionRule::class,
		Rules\ControlFlow\ControlStructureBracesRule::class,
		Rules\ControlFlow\UselessBracesRule::class,
		Rules\Classes\ClassDefinitionSpacingRule::class,
		Rules\Namespaces\ClassReferenceNameCasingRule::class,
		Rules\Classes\ModernClassNameReferenceRule::class,
		Rules\Classes\UselessNullPropertyInitializationRule::class,
		Rules\Classes\OrderedMembersRule::class,
		Rules\Classes\SelfForCurrentClassRule::class,
		Rules\Classes\VisibilityRequiredRule::class,
		Rules\Comments\CommentedOutFunctionRule::class,
		Rules\Comments\NoEmptyCommentRule::class,
		Rules\Comments\NoHashCommentRule::class,
		Rules\ControlFlow\ElseifKeywordRule::class,
		Rules\ControlFlow\NoAlternativeSyntaxRule::class,
		Rules\ControlFlow\FallThroughCommentRule::class,
		Rules\ControlFlow\NoEmptyStatementRule::class,
		Rules\ControlFlow\UselessConstructParenthesesRule::class,
		Rules\ControlFlow\SwitchCaseColonRule::class,
		Rules\ControlFlow\SwitchCaseSpacingRule::class,
		Rules\Files\NoByteOrderMarkRule::class,
		Rules\Files\FullOpeningTagRule::class,
		Rules\Files\LineEndingRule::class,
		Rules\Files\NoClosingTagRule::class,
		Rules\Functions\NamedArgumentSpacingRule::class,
		Rules\Functions\NativeFunctionCasingRule::class,
		Rules\Functions\NoAliasFunctionsRule::class,
		Rules\Functions\FunctionNameSpacingRule::class,
		Rules\Functions\UselessParameterDefaultRule::class,
		Rules\Functions\NoSettypeRule::class,
		Rules\Functions\StrictCallRule::class,
		Rules\Namespaces\NoLeadingBackslashInImportRule::class,
		Rules\Namespaces\ReferenceUsedNamesOnlyRule::class,
		Rules\Namespaces\UnusedImportsRule::class,
		Rules\Namespaces\UseFromSameNamespaceRule::class,
		Rules\Namespaces\UselessAliasRule::class,
		Rules\Literals\ConstantCasingRule::class,
		Rules\Literals\KeywordCasingRule::class,
		Rules\Namespaces\NoLeadingBackslashInGlobalNamespaceRule::class,
		Rules\Expressions\BinaryOperatorSpacingRule::class,
		Rules\Functions\NoConversionFunctionsRule::class,
		Rules\Expressions\CastSpacingRule::class,
		Rules\Expressions\ConcatSpacingRule::class,
		Rules\Expressions\DoubleColonSpacingRule::class,
		Rules\Functions\NoIsNullRule::class,
		Rules\Expressions\NoShortBoolCastRule::class,
		Rules\Expressions\NotEqualsOperatorRule::class,
		Rules\Expressions\ObjectOperatorSpacingRule::class,
		Rules\Expressions\ReferenceSpacingRule::class,
		Rules\Expressions\SpreadOperatorSpacingRule::class,
		Rules\Expressions\IncrementOperatorRule::class,
		Rules\Expressions\SymbolicLogicalOperatorsRule::class,
		Rules\Expressions\TernaryOperatorSpacingRule::class,
		Rules\Expressions\UnaryOperatorSpacingRule::class,
		Rules\PhpDoc\NoEmptyPhpDocRule::class,
		Rules\PhpDoc\PhpDocTrimRule::class,
		Rules\Literals\NoBacktickOperatorRule::class,
		Rules\Literals\ComplexStringVariableRule::class,
		Rules\Literals\NoImplicitBackslashRule::class,
		Rules\Literals\NowdocWithoutInterpolationRule::class,
		Rules\Literals\MagicConstantCasingRule::class,
		Rules\Literals\NoTrailingWhitespaceInStringRule::class,
		Rules\Literals\SingleQuotedStringsRule::class,
		Rules\Types\NullableTypeForDefaultNullRule::class,
		Rules\Types\TypeHintSpacingRule::class,
		Rules\Variables\CombinedIssetsRule::class,
		Rules\Variables\CombinedUnsetsRule::class,
		Rules\Variables\NoGlobalKeywordRule::class,
		Rules\Whitespace\CommaSpacingRule::class,
		Rules\Files\DeclareSpacingRule::class,
		Rules\Whitespace\ParenthesesSpacingRule::class,
		Rules\Files\NoTrailingWhitespaceRule::class,
		Rules\ControlFlow\SingleStatementPerLineRule::class,
		Rules\Files\EofNewlineRule::class,
		Rules\Whitespace\ConstructSpacingRule::class,
	];

	/** @var array<string, class-string<Rule>>  name → class */
	private array $rules = [];

	/** @var array<string, string>  alias → name */
	private array $aliases = [];

	/** @var array<string, class-string<Preset>>  name → class */
	private array $presets = [];


	public function __construct()
	{
		$this->registerPreset(Presets\Per::class);
		foreach (self::BuiltInRules as $class) {
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
		foreach ($info->aliases as $alias) {
			$owner = $this->aliases[$alias] ?? null;
			if ($owner !== null && $owner !== $info->name) {
				throw new ConfigurationException("Alias '$alias' is used by both rules $owner and $info->name.");
			}

			$this->aliases[$alias] = $info->name;
		}

		return $info->name;
	}


	/**
	 * Class of the rule given by name, alias or class; a class is registered on the way.
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

		return $this->rules[$this->resolveName($rule) ?? '']
			?? throw new ConfigurationException("Unknown rule '$rule'.");
	}


	/**
	 * Canonical name of a rule given by name or alias; null when unknown.
	 */
	public function resolveName(string $rule): ?string
	{
		return isset($this->rules[$rule]) ? $rule : ($this->aliases[$rule] ?? null);
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

		return $this->presets[$preset] ?? throw new ConfigurationException("Unknown preset '$preset'.");
	}


	/** @return array<string, class-string<Preset>>  name → class */
	public function getPresets(): array
	{
		return $this->presets;
	}
}
