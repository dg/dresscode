<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Config\{Catalogue, PluginRegistry, ResolvedConfig, ResolvedDecision};
use DressCode\Engine\Helpers;
use Nette\CommandLine\{Ansi, Console};
use Nette\Neon\Neon;
use Nette\Utils\Json;
use function count, sprintf, strlen;


/**
 * Writes a resolved configuration out: the environment, then every decision a layer set in the shape of the file,
 * with the layer that set it and why it takes no effect where it does not, and the same as JSON for whoever reads by
 * machine.
 * @internal
 */
final readonly class ConfigPrinter
{
	private const Version = 1;


	public function __construct(
		private ResolvedConfig $config,
	) {
	}


	/** The report as text for the console, with the ANSI colors of `$console` where it draws any. */
	public function print(Console $console): string
	{
		$out = $console->color('gray', 'Use        ') . (implode(', ', [...$this->config->plugins, ...$this->config->use]) ?: '(none)') . "\n";
		$out .= $console->color('gray', 'Targets    ') . self::describeTargets($this->config) . "\n";
		$out .= $console->color('gray', 'Style      ') . self::describeStyle($this->config) . "\n";
		$out .= $console->color('gray', 'Names      ') . self::describeNamespaces($this->config) . "\n";
		foreach ([[$this->config->namespacedFunctions, '()'], [$this->config->namespacedConstants, '']] as [$names, $suffix]) {
			foreach ($names as $name => $source) {
				$out .= '      ' . self::pad($name . $suffix, 56) . $console->color('gray', $source) . "\n";
			}
		}

		if ($this->config->suppressionComments !== []) {
			$out .= $console->color('gray', 'Comments   ') . 'silence decisions on their line' . "\n";
			foreach ($this->config->suppressionComments as $pattern => $names) {
				$out .= '      ' . self::pad($pattern, 56) . $console->color('gray', implode(', ', array_map(PluginRegistry::abbreviate(...), $names))) . "\n";
			}
		}

		$set = array_filter($this->config->decisions, fn(ResolvedDecision $decision) => $decision->layers !== []);
		$out .= $console->color('gray', 'Decisions  ')
			. sprintf('%d of %d set by a layer, the others asking for nothing', count($set), count($this->config->decisions)) . "\n\n";
		// a decision the execution names stands there too, so that a name doing nothing shows
		$named = array_filter(
			$this->config->decisions,
			fn(ResolvedDecision $decision, string $path) => isset($this->config->fixRisky[$path]) || isset($this->config->warnOnly[$path]),
			ARRAY_FILTER_USE_BOTH,
		);
		return $out . $this->printTree($console, $set + $named);
	}


	/**
	 * The decisions as a configuration writes them, by section and structure, each with the layer that set it, as text
	 * for the console.
	 * @param  array<string, ResolvedDecision>  $decisions
	 */
	private function printTree(Console $console, array $decisions): string
	{
		// the sections of the core in their order, those of the plugins and of the project after them
		$tree = array_fill_keys(Catalogue::CoreSections, []);
		foreach ($decisions as $path => $decision) {
			$tree = Helpers::placeValue($tree, $path, $decision);
		}

		$lines = [];
		/** @param  array<string, mixed>  $tree */
		$walk = function (array $tree, int $depth) use (&$walk, &$lines): void {
			foreach ($tree as $key => $node) {
				$indent = str_repeat("\t", $depth);
				if ($node instanceof ResolvedDecision) {
					$lines[] = [$indent . $key . ': ' . Neon::encode($node->value->toWrittenData()), $this->describeOrigin($node)];
				} else {
					$lines[] = [$indent . $key . ':', null];
					$walk($node, $depth + 1);
				}
			}
		};
		$walk(array_filter($tree, fn($node) => $node !== []), 0);

		$out = '';
		foreach ($lines as [$line, $comment]) {
			// a tab of the nesting counts as four columns, as an editor shows it
			$width = strlen(str_replace("\t", '    ', $line));
			$out .= $comment === null ? "$line\n" : $line . str_repeat(' ', max(1, 48 - $width)) . $console->color('gray', "# $comment") . "\n";
		}

		return $out;
	}


	/** The layer that set the decision, what keeps it from taking effect, whether the run selects it and what the project says of its fixes. */
	private function describeOrigin(ResolvedDecision $decision): string
	{
		$path = $decision->decision->path;
		return (($decision->layers[count($decision->layers) - 1] ?? null)?->origin?->describe() ?? 'no layer')
			. ($decision->inactive === null || $decision->value->isKept() ? '' : ", no effect: {$decision->inactive->value}")
			. ($decision->decision->isRequirement() && !$decision->value->isKept() && !$this->config->values->isSelected($path) ? ', outside --only' : '')
			. (isset($this->config->fixRisky[$path]) ? ', risky fixes accepted' : '')
			. (isset($this->config->warnOnly[$path]) ? ', only warns' : '');
	}


	/** The same report as JSON. */
	public function printJson(): string
	{
		$rules = [];
		foreach ($this->config->rules as $rule) {
			$rules[$rule->class] = [
				'active' => $rule->isActive(),
				'inactive' => $rule->inactiveReason === null ? null : ['reason' => $rule->inactiveReason->value, 'message' => $rule->inactiveMessage],
				'fixRisky' => $rule->fixRisky,
				'warnOnly' => $rule->warnOnly,
			];
		}

		// the keys and the values as a configuration writes them
		return Json::encode([
			'version' => self::Version,
			'use' => [...$this->config->plugins, ...array_map(PluginRegistry::abbreviate(...), $this->config->use)],
			'targets' => ['php' => $this->config->phpVersion, ...$this->config->packageTargets],
			'typeAnalysis' => $this->config->typeAnalysis,
			'indent' => $this->config->indent === "\t" ? 'tab' : strlen($this->config->indent),
			'lineEnding' => match ($this->config->lineEnding) {
				"\n" => 'LF',
				"\r\n" => 'CRLF',
				default => 'majority',
			},
			'lineLength' => $this->config->lineLength,
			'nameResolution' => $this->config->nameResolution,
			'namespaces' => [
				'functions' => $this->config->namespacedFunctions ?: new \stdClass,
				'constants' => $this->config->namespacedConstants ?: new \stdClass,
			],
			'suppressionComments' => array_map(fn(array $names) => array_map(PluginRegistry::abbreviate(...), $names), $this->config->suppressionComments) ?: new \stdClass,
			'rules' => $rules,
			'decisions' => array_map(fn(ResolvedDecision $decision) => [
				'value' => $decision->value->toData(),
				'layer' => $decision->value->origin?->describe(),
				'inactive' => $decision->inactive?->value,
				'selected' => !$decision->decision->parameter && $this->config->values->isSelected($decision->decision->path),
			], $this->config->decisions) ?: new \stdClass,
		], pretty: true) . "\n";
	}


	/** The version of PHP and of the packages the code is written for. */
	private static function describeTargets(ResolvedConfig $config): string
	{
		$targets = ['php' => $config->phpVersion, ...$config->packageTargets];
		return implode(', ', array_map(fn(string $package, string $version) => "$package $version", array_keys($targets), $targets));
	}


	private static function describeStyle(ResolvedConfig $config): string
	{
		$indent = $config->indent === "\t" ? 'a tab' : strlen($config->indent) . ' spaces';
		return $indent . ', ' . match ($config->lineEnding) {
			"\n" => 'LF',
			"\r\n" => 'CRLF',
			default => 'the line ending each file mostly has',
		} . ', ' . ($config->lineLength === null ? 'no line length' : "lines of up to $config->lineLength characters");
	}


	/** What an unqualified function or constant in a namespace is taken for, and what the namespaces are said to declare. */
	private static function describeNamespaces(ResolvedConfig $config): string
	{
		$count = count($config->namespacedFunctions) + count($config->namespacedConstants);
		return match (true) {
			$config->nameResolution === 'certain' && $count === 0 => 'certain, the namespaces declare no function and no constant',
			$config->nameResolution === 'certain' => "certain, the namespaces declare these $count and nothing else",
			default => 'uncertain, so an unqualified function or constant in a namespace is taken as global and a fix resting on it is risky'
				. ($count === 0 ? '' : "; $count declared"),
		};
	}


	/** Unlike `Ansi::pad()`, a column always ends with a space, so a name too long does not run into the next one. */
	private static function pad(string $text, int $width): string
	{
		return $text . str_repeat(' ', max(1, $width - Ansi::measure($text)));
	}
}
