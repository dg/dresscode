<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Interop;

use DressCode\Config\ComposerJson;
use DressCode\Engine\Helpers;
use Nette\Utils\FileSystem;
use function in_array, strlen;


/**
 * Where a project keeps the configuration of its formatter and how sure it is that the project runs it: the file a
 * script of composer.json runs the formatter with, read as the words of the shell and through the scripts it calls by
 * `@name`, the files of the root in the order the formatter looks for them.
 * @internal
 */
final class FormatterSearch
{
	/**
	 * The configurations of formatters the project runs by the strongest evidence of it, a script of composer.json
	 * running the formatter before a package of it the project requires and that before the file alone, the first the
	 * one to take where nobody chooses.
	 * @return list<FormatterCandidate>
	 */
	public static function find(string $root): array
	{
		$candidates = self::collectCandidates($root);
		$strongest = max(array_map(fn(FormatterCandidate $candidate) => $candidate->evidence->value, $candidates) ?: [0]);
		return array_values(array_filter($candidates, fn(FormatterCandidate $candidate) => $candidate->evidence->value === $strongest));
	}


	/**
	 * The configurations of formatters the project has, a file the scripts run before those in the root.
	 * @return list<FormatterCandidate>
	 */
	private static function collectCandidates(string $root): array
	{
		$composer = ComposerJson::read("$root/composer.json") ?? [];
		$scripts = array_map(fn($commands) => array_filter((array) $commands, is_string(...)), (array) ($composer['scripts'] ?? []));
		$required = [...array_keys((array) ($composer['require'] ?? [])), ...array_keys((array) ($composer['require-dev'] ?? []))];
		$candidates = [];
		foreach (array_keys($scripts) as $name) {
			foreach (self::findRunConfigs($root, $scripts, (string) $name) as [$formatter, $file]) {
				$candidates[$file] ??= new FormatterCandidate($formatter, $file, "composer $name", Evidence::Script);
			}
		}

		foreach (Formatter::cases() as $formatter) {
			$file = self::findRootFile($root, $formatter);
			if ($file !== null) {
				$candidates[$file] ??= new FormatterCandidate(
					$formatter,
					$file,
					null,
					array_intersect($formatter->getPackages(), $required) ? Evidence::Package : Evidence::File,
				);
			}
		}

		return array_values($candidates);
	}


	/**
	 * The configurations the script runs a formatter with, through the scripts it calls by `@name` too: the file its
	 * option names, or the one the formatter finds in the root.
	 * @param  array<array<string>>  $scripts
	 * @param  array<string, true>  $visited
	 * @return list<array{Formatter, string}>
	 */
	private static function findRunConfigs(string $root, array $scripts, string $name, array $visited = []): array
	{
		$found = [];
		$visited[$name] = true;
		foreach ($scripts[$name] ?? [] as $command) {
			foreach (self::splitShellCommands($command) as $words) {
				$called = substr($words[0], 1);
				if (str_starts_with($words[0], '@') && isset($scripts[$called]) && !isset($visited[$called])) {
					array_push($found, ...self::findRunConfigs($root, $scripts, $called, $visited));
					continue;
				}

				$i = self::findProgram($words);
				$formatter = Formatter::fromCommand($words[$i] ?? '');
				if ($formatter === null) {
					continue;
				}

				$option = $formatter->getConfigOption();
				$file = self::findRootFile($root, $formatter);
				foreach (array_slice($words, $i + 1) as $j => $argument) {
					$file = match (true) {
						str_starts_with($argument, "$option=") => substr($argument, strlen($option) + 1),
						$argument === $option => $words[$i + $j + 2] ?? null,
						default => $file,
					};
				}

				if ($file !== null && is_file("$root/$file")) {
					$found[] = [$formatter, Helpers::canonicalizePath(FileSystem::normalizePath($file))];
				}
			}
		}

		return $found;
	}


	/**
	 * Where among the words of a command the program it runs stands, past the variables of its environment and a PHP
	 * or `composer exec` running it, so that a formatter a command only names, `echo pint`, is not run by it.
	 * @param  non-empty-list<string>  $words
	 */
	private static function findProgram(array $words): int
	{
		$i = 0;
		while (preg_match('~^\w+=~', $words[$i] ?? '')) {
			$i++;
		}

		$launcher = basename(strtr($words[$i] ?? '', '\\', '/'));
		if ($launcher === '@php' || preg_match('~^php[\d.]*(\.exe)?$~', $launcher)) {
			$i++;
		} elseif (in_array($launcher, ['@composer', 'composer'], true) && ($words[$i + 1] ?? null) === 'exec') {
			$i += 2;
		} else {
			return $i;
		}

		while (str_starts_with($words[$i] ?? '', '-')) {
			$i += in_array($words[$i], ['-d', '-c'], true) ? 2 : 1; // the options of PHP that take a value apart
		}

		return $i;
	}


	private static function findRootFile(string $root, Formatter $formatter): ?string
	{
		return array_find($formatter->getRootFiles(), fn(string $file) => is_file("$root/$file"));
	}


	/**
	 * The commands of a line of the shell, which `&&`, `||`, `;` and `|` separate, as their words with the quotes taken
	 * off; a quote is not escaped inside quotes, which a script of composer.json running a formatter does not need.
	 * @return list<non-empty-list<string>>
	 */
	private static function splitShellCommands(string $line): array
	{
		$commands = $words = [];
		$word = null;
		for ($i = 0, $length = strlen($line); $i <= $length; $i++) {
			$char = $line[$i] ?? ' ';
			if ($char === "'" || $char === '"') {
				$end = strpos($line, $char, $i + 1);
				$end = $end === false ? $length : $end;
				$word .= substr($line, $i + 1, $end - $i - 1);
				$i = $end;
				continue;
			} elseif (!ctype_space($char) && !str_contains('&|;', $char)) {
				$word .= $char;
				continue;
			} elseif ($word !== null) {
				$words[] = $word;
				$word = null;
			}

			if ($words && (!ctype_space($char) || $i === $length)) {
				$commands[] = $words;
				$words = [];
			}
		}

		return $commands;
	}
}
