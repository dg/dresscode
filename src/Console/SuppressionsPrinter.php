<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Engine\Helpers;
use Nette\CommandLine\Console;
use function count, strlen;


/**
 * Writes how the suppression comments were rewritten: what a person has to look at among them, in whole sentences,
 * what to do next and last the result.
 * @internal
 */
final readonly class SuppressionsPrinter
{
	/** how many of the unknown names are listed */
	private const ListedNames = 5;


	public function __construct(
		private int $count,
		private int $files,
		private bool $dryRun,
		/** @var array<string, list<string>>  name no decision answers to => the places of the comments naming it */
		private array $unknown,
		/** @var list<string>  the places of the comments that reach further */
		private array $widened,
		/** @var array<string, string>  the file skipped => why */
		private array $skipped,
	) {
	}


	public function print(Console $console): string
	{
		return implode("\n", array_filter([
			$this->formatFindings($console),
			$this->formatAdvice($console),
			$this->formatVerdict($console),
		]));
	}


	private function formatFindings(Console $console): string
	{
		$out = '';
		if ($this->unknown !== [] || $this->widened !== []) {
			$unknown = (int) array_sum(array_map(count(...), $this->unknown));
			$widened = count($this->widened);
			$digits = strlen((string) max($unknown, $widened));
			$out = 'Of the ' . Helpers::formatCount($this->count, 'comment') . ($this->dryRun ? ' to rewrite' : ' rewritten') . ":\n";
			$one = $widened === 1;
			foreach ([
				[
					$unknown,
					($unknown === 1 ? 'names' : 'name')
					. ' a rule DressCode does not know, which silences nothing: '
					. $this->formatUnknownNames(),
				],
				[
					$widened,
					($one ? 'now silences' : 'now silence') . ' the whole statement below ' . ($one ? 'it' : 'them')
					. ', not just the next line, so ' . ($one ? 'it' : 'they') . ' may hide more than you meant ('
					. ($this->widened === [] ? '' : self::formatPlaces($this->widened)) . ')',
				],
			] as [$count, $text]) {
				if ($count > 0) {
					$out .= Markup::wrapLines(
						$console,
						str_pad((string) $count, $digits, ' ', STR_PAD_LEFT) . '  ' . Markup::highlightCode($console, $text),
						$digits + 2,
					) . "\n";
				}
			}
		}

		foreach ($this->skipped as $path => $why) {
			$out .= Markup::formatLine($console, 'Skipped', "$path, its comments left as they are", ": $why") . "\n";
		}

		return $out;
	}


	private function formatUnknownNames(): string
	{
		$names = array_slice($this->unknown, 0, self::ListedNames, preserve_keys: true);
		$more = count($this->unknown) - count($names);
		return implode(', ', array_map(
			fn(string $name, array $places) => "`$name` (" . self::formatPlaces($places) . ')',
			array_keys($names),
			$names,
		)) . ($more > 0 ? " and $more more" : '');
	}


	/** @param  list<string>  $places */
	private static function formatPlaces(array $places): string
	{
		return $places[0] . (count($places) > 1 ? ' and ' . (count($places) - 1) . ' more' : '');
	}


	private function formatAdvice(Console $console): string
	{
		$todo = match (true) {
			$this->unknown !== [] && $this->widened !== [] => 'replace or remove the unknown names and check what the others hide',
			$this->unknown !== [] => 'replace or remove those names',
			$this->widened !== [] => 'check what those comments hide',
			default => null,
		};
		$shortened = count($this->widened) > 1 || count($this->unknown) > self::ListedNames
			|| array_any($this->unknown, fn(array $places) => count($places) > 1);
		return Markup::highlightCode(
			$console,
			($shortened ? "`--format json` lists all the places.\n" : '')
			. ($todo === null || $this->dryRun ? '' : "Next, $todo; then `dresscode check` shows the violations left.\n"),
		);
	}


	private function formatVerdict(Console $console): string
	{
		$done = Helpers::formatCount($this->count, 'comment') . ' in ' . Helpers::formatCount($this->files, 'file');
		return match (true) {
			$this->dryRun => "Would rewrite $done, writing nothing.\n",
			$this->skipped !== [] => $console->color(
				'white/red',
				'FAILED  ' . Helpers::formatCount(count($this->skipped), 'file') . " skipped, $done rewritten",
			) . "\n",
			$this->count === 0 => $console->color('white/green', 'OK  no phpcs comments to rewrite') . "\n",
			default => $console->color('white/green', "REWRITTEN  $done") . "\n",
		};
	}
}
