<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Config\PluginRegistry;
use DressCode\Engine\Helpers;
use DressCode\Interop\Translation;
use Nette\CommandLine\Console;
use function count, strlen;


/**
 * Writes how a foreign configuration was translated, in whole sentences, which the labels of a report say too little
 * for: the rules counted by what became of them, what to do next and last that the file was written; the details are
 * in the closing comment of the configuration.
 * @internal
 */
final readonly class MigrationPrinter
{
	/** how many of the rules left to the project are named */
	private const ListedRules = 5;


	public function __construct(
		private Translation $translation,
		/** the foreign configuration */
		private string $source,
		/** the file written, as the output names it; null for a dry run */
		private ?string $written,
		/** the absolute path of the file */
		private string $target,
	) {
	}


	public function print(Console $console): string
	{
		return implode("\n", array_filter([
			$this->formatCounts($console),
			$this->formatAdvice($console),
			$this->formatVerdict($console),
		]));
	}


	private function formatCounts(Console $console): string
	{
		$counts = $this->translation->countRules();
		$out = '';
		if ($this->translation->ruleCount > 0) {
			$digits = strlen((string) max($counts));
			$out = Markup::highlightCode($console, $this->formatHeading()) . "\n";
			foreach ([
				[
					$counts['whole'],
					'green',
					'carried over exactly' . ($counts['off'] === 0 ? '' : " (including the {$counts['off']} you have turned off)"),
				],
				[$counts['difference'], null, "carried over, but DressCode's result may differ a little"],
				[$counts['request'], 'yellow', 'you need to finish by hand: ' . $this->formatRequestedRules()],
				[$counts['uncovered'], null, 'dropped, DressCode has no such rule, so nothing checks them now'],
			] as [$count, $color, $text]) {
				if ($count > 0) {
					$out .= Markup::wrapLines(
						$console,
						$console->color($color, str_pad((string) $count, $digits, ' ', STR_PAD_LEFT))
						. '  '
						. Markup::highlightCode($console, $text),
						$digits + 2,
					) . "\n";
				}
			}
		}

		return $out . ($counts['general'] === 0
			? ''
			: Helpers::formatCount($counts['general'], 'thing') . " to settle by hand about the configuration as a whole.\n");
	}


	private function formatHeading(): string
	{
		$presets = array_map(PluginRegistry::abbreviate(...), $this->translation->presets);
		return Helpers::formatCount($this->translation->ruleCount, 'rule') . " in your `$this->source`"
			. ($presets === [] ? '' : ', on the preset' . (count($presets) > 1 ? 's' : '') . ' `' . implode('`, `', $presets) . '`')
			. ':';
	}


	private function formatRequestedRules(): string
	{
		$rules = $this->translation->listRequestedRules();
		$more = count($rules) - self::ListedRules;
		return '`' . implode('`, `', array_slice($rules, 0, self::ListedRules)) . '`' . ($more > 0 ? " and $more more" : '');
	}


	private function formatAdvice(Console $console): string
	{
		$counts = $this->translation->countRules();
		$first = match (true) {
			$counts['general'] > 0 => 'settle what is left by hand; then ',
			$counts['request'] === 1 => 'finish that rule; then ',
			$counts['request'] > 1 => "finish those {$counts['request']} rules; then ",
			default => '',
		};
		$location = $this->written === null ? 'the printed configuration' : "`$this->written`";
		$lines = array_filter([
			$this->translation->formatNotes() === '' ? '' : "The details are at the end of $location.",
			$this->written === null ? '' : "Next, $first`dresscode check` shows what the configuration finds in your code.",
		]);
		return $lines === [] ? '' : Markup::highlightCode($console, implode("\n", $lines)) . "\n";
	}


	private function formatVerdict(Console $console): string
	{
		return $this->written === null
			? Markup::highlightCode($console, "Nothing written, as `--dry-run` asks.\n")
			: $console->color('white/green', 'WRITTEN  ' . Markup::formatFileLink($console, $this->written, $this->target)) . "\n";
	}
}
