<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Config\{PluginRegistry, ResolvedConfig, ResolvedDecision};
use DressCode\{DecisionKind, RuleInfo, Value, Violation};
use function is_bool, is_string, strlen;


/**
 * Explains the decisions in Markdown, which the console draws: one decision in detail, what it is for, the values it
 * takes, its value here with the layer that set it, the values of the standards and the rule that owns it; the
 * decisions under a section; or the whole configuration, what it is composed of and every decision a layer makes,
 * by section.
 * @internal
 */
final readonly class ExplainPrinter
{
	public function __construct(
		private PluginRegistry $registry,
		/** @var array<string, array<string, ResolvedDecision>>  standard => its decisions */
		private array $standards = [],
	) {
	}


	/** The whole configuration as Markdown: what it is composed of and every decision a layer makes, by section. */
	public function printConfig(ResolvedConfig $config): string
	{
		$out = "# Decisions of this project\n\n";
		$out .= "Written by `dresscode explain`. What no layer decides is not here.\n\n";
		$out .= '- Composed of: ' . (implode(', ', array_map(fn(string $p) => "`$p`", $config->use)) ?: 'no preset') . "\n";
		$out .= '- Indentation: ' . ($config->indent === "\t" ? 'a tab' : strlen($config->indent) . ' spaces') . "\n";
		$out .= '- Line ending: ' . match ($config->lineEnding) {
			"\n" => 'LF',
			"\r\n" => 'CRLF',
			default => 'the one each file mostly has',
		} . "\n";
		$out .= '- Line length: ' . ($config->lineLength === null ? 'none' : $config->lineLength . ' characters') . "\n";
		$out .= '- Written for PHP ' . $config->phpVersion . "\n";

		$bySection = [];
		foreach ($config->decisions as $path => $decision) {
			if ($decision->layers !== [] && !$decision->value->isKept()) {
				$bySection[explode('.', $path)[0]][$path] = $decision;
			}
		}

		foreach ($bySection as $section => $decisions) {
			$out .= "\n## $section\n\n" . self::printList($decisions);
		}

		return $out;
	}


	/** The decisions under a section or a structure as Markdown, each with its value, the layer that set it and its description. */
	public function printSection(string $prefix, ResolvedConfig $config): string
	{
		$decisions = array_filter($config->decisions, fn(string $path) => str_starts_with($path, "$prefix."), ARRAY_FILTER_USE_KEY);
		return "## `$prefix`\n\n" . self::printList($decisions, detailed: true);
	}


	/** One decision as Markdown: what it is for, the values it takes, its value here, the values of the standards and its rule. */
	public function printDecision(ResolvedDecision $resolved): string
	{
		$decision = $resolved->decision;
		$out = "## `$decision->path`\n\n";
		$out .= self::escape("$decision->description.") . "\n\n";
		foreach ($decision->notes as $note) {
			$out .= self::escape($note) . "\n\n";
		}

		$out .= match ($decision->kind) {
			DecisionKind::Parameter => 'A parameter, which refines a requirement and turns nothing on.',
			DecisionKind::Fact => 'A fact of the project, which the rule guards.',
			DecisionKind::Requirement => 'A requirement, which turns its rule on where it is not `keep`.',
		} . "\n\n";
		$out .= 'Takes: ' . self::escape($decision->describeValues()) . "\n\n";

		$top = $resolved->findTopLayer();
		$out .= 'Here ' . self::formatValue($resolved->value) . ', ' . ($top === null ? 'which no layer sets' : 'set by ' . $top->origin?->describe())
			. ($resolved->inactive === null ? '' : ", taking no effect ({$resolved->inactive->value})") . ".\n\n";
		if ($this->standards !== []) {
			$values = [];
			foreach ($this->standards as $standard => $decisions) {
				$value = $decisions[$decision->path]->value ?? null;
				$values[] = "$standard " . ($value === null ? 'none' : self::formatValue($value));
			}

			$out .= 'The standards: ' . implode(', ', $values) . ".\n\n";
		}

		foreach ($resolved->rules as $rule) {
			$out .= 'Rule `' . $rule . '`, ' . implode(', ', self::collectFacts(RuleInfo::of($rule))) . ".\n\n";
		}

		$url = $this->registry->findUrl($decision->path);
		$out .= $url === null ? '' : "See <$url>\n";
		return rtrim($out) . "\n";
	}


	/**
	 * The decisions with their values as a Markdown list; with the layer that set each and its description in detail.
	 * @param  array<string, ResolvedDecision>  $decisions
	 */
	private static function printList(array $decisions, bool $detailed = false): string
	{
		$out = '';
		foreach ($decisions as $path => $decision) {
			$top = $decision->findTopLayer();
			$out .= "- `$path`: " . self::formatValue($decision->value) . ' _(' . ($top?->origin?->describe() ?? 'default') . ')_' . "\n";
			$out .= $detailed ? "\n  " . self::escape($decision->decision->description) . "\n\n" : '';
		}

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


	private static function formatValue(Value $value): string
	{
		$data = $value->toWrittenData();
		return Violation::formatCode(match (true) {
			is_bool($data) => $data ? 'yes' : 'no',
			$data === null => 'null',
			is_string($data) => $data,
			default => (string) json_encode($data, JSON_UNESCAPED_SLASHES),
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
