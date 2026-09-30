<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\Config\PluginRegistry;
use PhpSyntax\Nodes\FileNode;


/**
 * Rewrites the phpcs suppression comments of a file to the dresscode form with the decisions of DressCode:
 * `phpcs:ignore|disable|enable|ignoreFile` become `dresscode:ignore|disable|enable|ignoreFile`, and a `@phpcsSuppress`
 * tag keeps its name and gets the decisions. Names nothing covers stay as they are and are listed.
 * @internal
 */
final class SuppressionMigration
{
	public private(set) int $count = 0;

	/** @var array<string, true> */
	public private(set) array $unknownNames = [];

	/** an ignore on a line of its own was migrated; its scope grows from the next line to the whole next statement */
	public private(set) bool $ownLineIgnore = false;


	public function __construct(
		/** @var \Closure(string): list<string> */
		private readonly \Closure $expandName,
	) {
	}


	/** Whether the file changed. */
	public function migrate(FileNode $file): bool
	{
		$changed = false;
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
				$text = $this->rewrite($trivia->text, $ownLine);
				if ($text !== $trivia->text) {
					$token->replaceTrivia($trivia, $trivia->withText($text));
					$changed = true;
				}
			}
		}

		return $changed;
	}


	private function rewrite(string $text, bool $ownLine): string
	{
		$text = (string) preg_replace_callback(
			'~phpcs:(ignoreFile|ignore|disable|enable)((?:[ \t]+[\w/][\w/.-]*(?:[ \t]*,[ \t]*[\w/][\w/.-]*)*)?)~',
			function (array $m) use ($ownLine): string {
				$this->count++;
				$kind = $m[1];
				$this->ownLineIgnore = $this->ownLineIgnore || ($kind === 'ignore' && $ownLine);
				$names = preg_split('~[,\s]+~', trim($m[2]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
				return "dresscode:$kind" . ($names ? ' ' . implode(', ', array_map($this->getCanonicalName(...), $names)) : '');
			},
			$text,
		);
		return (string) preg_replace_callback(
			'~@phpcsSuppress[ \t]+([\w/][\w/.-]*)~',
			function (array $m): string {
				$name = $this->getCanonicalName($m[1]);
				if ($name !== $m[1]) {
					$this->count++;
				}

				return "@phpcsSuppress $name";
			},
			$text,
		);
	}


	private function getCanonicalName(string $name): string
	{
		$resolved = ($this->expandName)($name);
		if (!$resolved) {
			$this->unknownNames[$name] = true;
			return $name;
		}

		return implode(', ', array_map(PluginRegistry::abbreviate(...), $resolved));
	}
}
