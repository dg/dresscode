<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use Nette\CommandLine\{Console, HelpRenderer};


/**
 * Draws what a message of the tool marks: the code in backticks, the page of the manual it points to, a diff.
 * Without colors the text stays as it is, so a log or a pipe reads the backticks and the address.
 * @internal
 */
final class Markup
{
	private const DocsUrl = 'https://dresscode.run/';

	private const CodeColor = '#87D7FF';


	/**
	 * Draws the code spans of Markdown in their own color and without the backticks, the rest of the text in the given
	 * color; code holding a backtick is written in a longer run of them (`Violation::formatCode()`).
	 */
	public static function highlightCode(Console $console, string $text, ?string $color = null): string
	{
		if (!$console->hasColors()) {
			return $text;
		}

		$out = '';
		foreach (HelpRenderer::splitCodeSpans($text) as [$piece, $code]) {
			$out .= $console->color($code ? self::CodeColor : $color, $piece);
		}

		return $out;
	}


	/**
	 * Returns the line that points to the page of the manual, as `page#anchor`.
	 */
	public static function formatDocsLink(Console $console, string $docs): string
	{
		return self::formatLink($console, self::DocsUrl . $docs);
	}


	private static function formatLink(Console $console, string $url): string
	{
		return $console->color('gray', 'See ' . $console->link($url));
	}


	/** The path of a decision, in a terminal with colors a link to its page. */
	public static function formatDecision(Console $console, string $path, ?string $url): string
	{
		return $url !== null && $console->isTerminal() && $console->hasColors() ? $console->link($url, $path) : $path;
	}


	public static function highlightDiff(Console $console, string $diff): string
	{
		return implode('', array_map(
			fn(string $line) => match ($line[0] ?? '') {
				'-' => $console->color('red', $line),
				'+' => $console->color('green', $line),
				'@' => $console->color('teal', $line),
				default => $line,
			},
			preg_split('~(?<=\n)~', $diff, -1, PREG_SPLIT_NO_EMPTY) ?: [],
		));
	}
}
