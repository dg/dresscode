<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\Config\PluginRegistry;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\{ParseException, Parser, Trivia};


/**
 * Rewrites the phpcs suppression comments of a file to the dresscode form with the decisions of DressCode:
 * `phpcs:ignore|disable|enable|ignoreFile` become `dresscode:ignore|disable|enable|ignoreFile`, and a `@phpcsSuppress`
 * tag keeps its name and gets the decisions. Names nothing covers stay as they are and are listed.
 * @internal
 */
final class SuppressionMigration
{
	/** a directive ends its comment or line, with a reason after `--`, as Suppression reads it; one inside prose is no directive */
	private const Directive = '~phpcs:(ignoreFile|ignore|disable|enable)((?:[ \t]+[\w/][\w/.-]*(?:[ \t]*,[ \t]*[\w/][\w/.-]*)*)?)(?=[ \t]*(?:--(?:[ \t].*)?)?[ \t]*(?:\*/|$))~m';

	private const Tag = '~@phpcsSuppress[ \t]+([\w/][\w/.-]*(?:[ \t]*,[ \t]*[\w/][\w/.-]*)*)~';

	/** the directives and tags the file being migrated rewrote */
	private int $count = 0;

	/** @var array<string, list<int>>  name => lines, in the file being migrated */
	private array $unknownNames = [];

	/** @var list<int>  in the file being migrated */
	private array $widened = [];


	public function __construct(
		/** @var \Closure(string): list<string> */
		private readonly \Closure $expandName,
	) {
	}


	/**
	 * The suppression comments of PHP_CodeSniffer the files hold that `migrate()` would rewrite, writing nothing; a file
	 * that does not parse is migrated neither.
	 * @param  list<string>  $files  relative to the root
	 */
	public function countInFiles(string $root, array $files): int
	{
		$count = 0;
		$parser = new Parser;
		foreach ($files as $file) {
			$code = (string) @file_get_contents("$root/$file"); // @ file may have been deleted meanwhile
			try {
				$count += str_contains($code, 'phpcs') ? $this->migrate($parser->parse($code))->count : 0;
			} catch (ParseException) {
			}
		}

		return $count;
	}


	/** Rewrites the comments of the file and says what it did to them. */
	public function migrate(FileNode $file): SuppressionRewrite
	{
		[$this->count, $this->unknownNames, $this->widened] = [0, [], []];
		foreach ($file->getTokens() as $token) {
			// whether a comment has a line of its own is read before the first of them is rewritten
			$comments = [];
			foreach ([$token->leadingTrivia, $token->trailingTrivia] as $trivias) {
				foreach ($trivias as $index => $trivia) {
					if ($trivia->isComment()) {
						$comments[] = [$trivia, Suppression::isAlone($trivias, $index, $token)];
					}
				}
			}

			foreach ($comments as [$trivia, $ownLine]) {
				$text = $this->rewrite($trivia, $ownLine);
				if ($text !== $trivia->text) {
					$token->replaceTrivia($trivia, $trivia->withText($text));
				}
			}
		}

		return new SuppressionRewrite($this->count, $this->unknownNames, $this->widened);
	}


	private function rewrite(Trivia $trivia, bool $ownLine): string
	{
		// the rewrites keep the line endings, so the line is counted in the text as it stands
		$lineOf = fn(string $text, int $offset) => $trivia->line + substr_count($text, "\n", 0, $offset);
		$text = (string) preg_replace_callback(
			self::Directive,
			function (array $m) use ($ownLine, $lineOf, $trivia): string {
				$this->count++;
				$kind = $m[1][0];
				$line = $lineOf($trivia->text, $m[0][1]);
				if ($kind === 'ignore' && $ownLine) {
					$this->widened[] = $line;
				}

				$names = preg_split('~[,\s]+~', trim($m[2][0]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
				return "dresscode:$kind"
					. ($names ? ' ' . implode(', ', array_map(fn(string $name) => $this->getCanonicalName($name, $line), $names)) : '');
			},
			$trivia->text,
			flags: PREG_OFFSET_CAPTURE,
		);
		return (string) preg_replace_callback(
			self::Tag,
			function (array $m) use ($lineOf, $text): string {
				$line = $lineOf($text, $m[0][1]);
				$names = (array) preg_split('~[,\s]+~', $m[1][0], -1, PREG_SPLIT_NO_EMPTY);
				$canonical = implode(', ', array_map(fn(string $name) => $this->getCanonicalName($name, $line), $names));
				if ($canonical !== implode(', ', $names)) {
					$this->count++;
				}

				return "@phpcsSuppress $canonical";
			},
			$text,
			flags: PREG_OFFSET_CAPTURE,
		);
	}


	private function getCanonicalName(string $name, int $line): string
	{
		$resolved = ($this->expandName)($name);
		if (!$resolved) {
			$this->unknownNames[$name][] = $line;
			return $name;
		}

		return implode(', ', array_map(PluginRegistry::abbreviate(...), $resolved));
	}
}
