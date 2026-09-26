<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Config\{ResolvedConfig, ResolvedRule};
use DressCode\RuleInfo;
use function count, is_bool, is_string, strlen;


/**
 * Explains the rules that run in Markdown: what the configuration is composed of and, for every rule, what it
 * asks for, the options this project gives it and the examples chosen for it. What no rule covers is not here.
 * @internal
 */
final class ExplainMarkdownPrinter
{
	public function __construct(
		private readonly ResolvedConfig $config,
	) {
	}


	/** The whole document: the configuration and every rule that runs, grouped by area. */
	public function print(): string
	{
		$out = "# Rules this project enforces\n\n";
		$out .= "Written by `dresscode explain`. What no rule covers is not here.\n\n";
		$out .= '- Composed of: ' . (implode(', ', array_map(fn(string $p) => "`$p`", $this->config->presets)) ?: 'no preset') . "\n";
		$out .= '- Indentation: ' . ($this->config->indent === "\t" ? 'a tab' : strlen($this->config->indent) . ' spaces') . "\n";
		$out .= '- Line ending: ' . match ($this->config->eol) {
			"\n" => 'LF',
			"\r\n" => 'CRLF',
			default => 'the one each file mostly has',
		} . "\n";
		$out .= '- Line length: ' . ($this->config->lineLength === null ? 'none' : $this->config->lineLength . ' characters') . "\n";
		$out .= '- Written for PHP ' . $this->config->phpVersion . "\n";

		$byCategory = [];
		foreach ($this->config->getActiveRules() as $rule) {
			$byCategory[self::categoryOf($rule)][] = $rule;
		}

		ksort($byCategory);
		$out .= "\n" . count($this->config->getActiveRules()) . " rules run.\n";
		foreach ($byCategory as $category => $rules) {
			$out .= "\n## $category\n";
			foreach ($rules as $rule) {
				$out .= $this->printRule($rule);
			}
		}

		return $out;
	}


	/** One rule: its description, the options this project gives it and its examples. */
	public function printRule(ResolvedRule $rule): string
	{
		$info = RuleInfo::of($rule->class);
		$out = "\n### $rule->name\n\n";
		$out .= ($info->description === '' ? '' : "$info->description.\n\n");

		$options = [];
		foreach ($rule->getOrigins() as $path => $layers) {
			$options[] = "`$path`: " . self::format($layers[count($layers) - 1][1]);
		}

		// a rule that is one decision says its value even when nobody changed it: the value is what the project writes
		if (!$options && $info->decision !== null) {
			$options[] = "`$info->decision`: " . self::format($rule->options[$info->decision] ?? null);
		}

		if ($options) {
			$out .= 'As this project has it: ' . implode(', ', $options) . ".\n\n";
		}

		foreach (new ExplainPrinter($rule)->findExamples() as [$before, $after]) {
			$out .= "```php\n" . self::strip($before) . "```\n";
			if ($after !== null && $after !== $before) {
				$out .= "becomes\n\n```php\n" . self::strip($after) . "```\n";
			}
		}

		return $out;
	}


	/** The area a rule belongs to, that is the directory of its class, as the reference groups them too. */
	private static function categoryOf(ResolvedRule $rule): string
	{
		$parts = explode('\\', $rule->class);
		return $parts[count($parts) - 2];
	}


	private static function strip(string $code): string
	{
		$code = (string) preg_replace('~^<\?php\r?\n(?://[^\r\n]*\r?\n)*~', '', $code);
		return rtrim($code, "\r\n") . "\n";
	}


	private static function format(mixed $value): string
	{
		return match (true) {
			is_bool($value) => $value ? '`true`' : '`false`',
			$value === null => '`null`',
			is_string($value) => "`$value`",
			default => '`' . json_encode($value, JSON_UNESCAPED_SLASHES) . '`',
		};
	}
}
