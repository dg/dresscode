<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Config\{ResolvedConfig, ResolvedRule, RuleRegistry};
use DressCode\{ConfigurableRule, RuleInfo, Violation};
use Nette\Schema\Elements\{AnyOf, Structure, Type};
use Nette\Schema\Processor;
use function count, is_bool, is_string, strlen;


/**
 * Explains the rules in Markdown, which the console draws: one rule in detail, what it is for, whether and why it runs
 * and every option with its value, the layer that set it and its description, or the whole configuration, what it is
 * composed of and every rule that runs, grouped by area, with the options the project gives it. What no rule covers
 * is not there.
 * @internal
 */
final class ExplainPrinter
{
	public function __construct(
		private readonly RuleRegistry $registry,
	) {
	}


	/** The whole configuration: what it is composed of and every rule that runs, grouped by area. */
	public function printConfig(ResolvedConfig $config): string
	{
		$out = "# Rules this project enforces\n\n";
		$out .= "Written by `dresscode explain`. What no rule covers is not here.\n\n";
		$out .= '- Composed of: ' . (implode(', ', array_map(fn(string $p) => "`$p`", $config->presets)) ?: 'no preset') . "\n";
		$out .= '- Indentation: ' . ($config->indent === "\t" ? 'a tab' : strlen($config->indent) . ' spaces') . "\n";
		$out .= '- Line ending: ' . match ($config->lineEnding) {
			"\n" => 'LF',
			"\r\n" => 'CRLF',
			default => 'the one each file mostly has',
		} . "\n";
		$out .= '- Line length: ' . ($config->lineLength === null ? 'none' : $config->lineLength . ' characters') . "\n";
		$out .= '- Written for PHP ' . $config->phpVersion . "\n";

		$byCategory = [];
		foreach ($config->getActiveRules() as $rule) {
			$byCategory[self::categoryOf($rule)][] = $rule;
		}

		ksort($byCategory);
		$out .= "\n" . count($config->getActiveRules()) . " rules run.\n";
		foreach ($byCategory as $category => $rules) {
			$out .= "\n## $category\n";
			foreach ($rules as $rule) {
				$out .= "\n" . $this->printRule($rule, detailed: false);
			}
		}

		return $out;
	}


	/**
	 * One rule: its description and the options it runs with, in detail the facts of the rule, whether and why it
	 * runs and every option it has with its description too.
	 */
	public function printRule(ResolvedRule $rule, bool $detailed = true): string
	{
		$info = RuleInfo::of($rule->class);
		$url = $this->registry->getRuleUrl($rule->name);
		// the detailed one ends with the address, the other one links its heading
		$title = !$detailed && $url !== null ? "[$rule->name]($url)" : $rule->name;
		$out = ($detailed ? '## ' : '### ') . $title . "\n\n";
		$out .= $info->description === '' ? '' : self::escape("$info->description.") . "\n\n";
		if ($detailed) {
			$out .= '_' . implode(', ', self::collectFacts($info)) . "_\n\n";
			$out .= $rule->isActive()
				? '_It runs in this project' . ($rule->getSource() === null ? '' : ', set by ' . $rule->getSource()) . "._\n\n"
				: '_It does not run in this project:_ ' . self::escape((string) $rule->inactive) . ".\n\n";
		}

		$options = $detailed ? $this->printAllOptions($rule) : $this->printSetOptions($rule, $info);
		$out .= $options === '' ? '' : ($detailed ? "### Options\n\n" : '') . rtrim($options) . "\n\n";
		$out .= $detailed && $url !== null ? "See <$url>\n" : '';
		return rtrim($out) . "\n";
	}


	/** @return list<string> */
	private static function collectFacts(RuleInfo $info): array
	{
		$facts = ['stage ' . $info->stage->name];
		if (($info->requires['php'] ?? '*') !== '*') {
			$facts[] = 'needs PHP ' . $info->requires['php'];
		}

		foreach ($info->getRequiredPackages() as $package => $constraint) {
			$facts[] = "needs $package" . ($constraint === '*' ? '' : " $constraint");
		}

		if ($info->modifiesComments) {
			$facts[] = 'modifies comments';
		}

		return $facts;
	}


	/** Every option of the rule with its value, the layer that set it and its description. */
	private function printAllOptions(ResolvedRule $rule): string
	{
		$schema = is_subclass_of($rule->class, ConfigurableRule::class) ? $rule->class::getOptionsSchema() : null;
		if (!$schema instanceof Structure) {
			return '';
		}

		$defaults = (array) (new Processor)->process($schema, []);
		$origins = $rule->getOrigins();
		$out = '';
		foreach ($schema->getShape() as $option => $element) {
			$layers = $origins[(string) $option] ?? [];
			$out .= "- `$option`: " . self::formatValue($rule->options[$option] ?? $defaults[$option] ?? null)
				. ' _(' . ($layers === [] ? 'default' : $layers[count($layers) - 1][0]) . ')_' . "\n";
			$description = $element instanceof Type || $element instanceof AnyOf || $element instanceof Structure
				? $element->describe()['description'] ?? null
				: null;
			$out .= is_string($description) && $description !== '' ? "\n  " . self::escape($description) . "\n\n" : '';
		}

		return $out;
	}


	/** The options a layer of the project set, and the value of a rule that is one decision even where none did. */
	private function printSetOptions(ResolvedRule $rule, RuleInfo $info): string
	{
		$out = '';
		foreach ($rule->getOrigins() as $path => $layers) {
			$out .= "- `$path`: " . self::formatValue($layers[count($layers) - 1][1]) . "\n";
		}

		// the value of a decision is what the project writes, so it is said even when nobody changed it
		return $out === '' && $info->decision !== null
			? "- `$info->decision`: " . self::formatValue($rule->options[$info->decision] ?? null) . "\n"
			: $out;
	}


	/** The area a rule belongs to, that is the directory of its class, as the reference groups them too. */
	private static function categoryOf(ResolvedRule $rule): string
	{
		$parts = explode('\\', $rule->class);
		return $parts[count($parts) - 2];
	}


	private static function formatValue(mixed $value): string
	{
		return Violation::formatCode(match (true) {
			is_bool($value) => $value ? 'true' : 'false',
			$value === null => 'null',
			is_string($value) => $value,
			default => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
		});
	}


	/** Text that Markdown reads as it is written: a line outside a code span does not open a list or a heading. */
	private static function escape(string $text): string
	{
		return (string) preg_replace_callback(
			'~(?<!`)(`+)(?!`)[^\n]*?[^`\n]\1(?!`)|(?<![^\n])(\s*)([-#])~',
			fn(array $m) => isset($m[3]) ? $m[2] . '\\' . $m[3] : $m[0],
			$text,
		);
	}
}
