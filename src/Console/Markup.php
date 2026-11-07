<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Violation;
use Nette\CommandLine\{Ansi, Console, HelpRenderer};


/**
 * Draws what a message of the tool marks: the code in backticks, the page of the manual it points to, a diff.
 * Without colors the text stays as it is, so a log or a pipe reads the backticks and the address.
 * @internal
 */
final class Markup
{
	/** the width of the label a line of the output opens with */
	private const LabelWidth = 11;

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


	/** What the fact before it rests on, or what to do about it, in gray with its code drawn, so that the eye reads the facts. */
	public static function formatAside(Console $console, string $text): string
	{
		return self::highlightCode($console, $text, 'gray');
	}


	/** A question of a prompt with its code drawn, and in gray after it why it is asked. */
	public static function formatQuestion(Console $console, string $question, string $reason): string
	{
		return self::highlightCode($console, $question) . ' ' . self::formatAside($console, $reason);
	}


	/**
	 * Draws the Markdown the explanations are written in: a heading in white without its marks, a bullet without its
	 * dash and the paragraph nested under it in gray, below it, the code, an emphasis in gray and a link as a link of
	 * the terminal; without colors the code keeps its backticks and a link shows its address.
	 */
	public static function formatMarkdown(Console $console, string $markdown): string
	{
		$out = '';
		foreach (explode("\n", $markdown) as $line) {
			$out .= match (true) {
				(bool) preg_match('~^#+ (.*)$~', $line, $m) => self::formatInline($console, $m[1], 'white'),
				str_starts_with($line, '- ') => '  ' . self::formatInline($console, substr($line, 2)),
				str_starts_with($line, '  ') && trim($line) !== '' => '      ' . self::formatInline($console, trim($line), 'gray'),
				default => self::formatInline($console, $line),
			} . "\n";
		}

		return rtrim($out, "\n") . "\n";
	}


	/** One line of Markdown: its code spans, emphasis, links and escaped characters. */
	private static function formatInline(Console $console, string $text, ?string $color = null): string
	{
		$out = '';
		foreach (HelpRenderer::splitCodeSpans($text) as [$piece, $code]) {
			$out .= $code
				? ($console->hasColors() ? $console->color(self::CodeColor, $piece) : Violation::formatCode($piece))
				: (string) preg_replace_callback(
					'~\[([^\]]+)\]\(([^)\s]+)\)|<(https?://[^>\s]+)>|(?<![\w\\\\])_([^_]+)_(?!\w)|\\\\([#-])|([^[<_\\\\]+|.)~s',
					fn(array $m) => match (true) {
						$m[1] !== null && $m[2] !== null => $console->link($m[2], $m[1]),
						$m[3] !== null => $console->link($m[3]),
						$m[4] !== null => $console->color('gray', $m[4]),
						default => $console->color($color, (string) ($m[5] ?? $m[6])),
					},
					$piece,
					flags: PREG_UNMATCHED_AS_NULL,
				);
		}

		return $out;
	}


	/**
	 * Returns the line that points to the page of the manual, as `page#anchor`.
	 */
	public static function formatDocsLink(Console $console, string $docs): string
	{
		return $console->color('gray', 'See ' . $console->link(self::DocsUrl . $docs));
	}


	/** The path of a decision, in a terminal with colors a link to its page. */
	public static function formatDecision(Console $console, string $path, ?string $url): string
	{
		return $url !== null && $console->isTerminal() && $console->hasColors() ? $console->link($url, $path) : $path;
	}


	/** The name of a file, in a terminal with colors a link that opens it. */
	public static function formatFileLink(Console $console, string $name, string $path): string
	{
		$url = 'file:///' . str_replace(['%2F', '%3A'], ['/', ':'], rawurlencode(ltrim(strtr($path, '\\', '/'), '/')));
		return $console->isTerminal() && $console->hasColors() ? $console->link($url, $name) : $name;
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


	/**
	 * A line of a report: the label, the text with its code drawn and in gray what it rests on, broken under the values in
	 * a terminal.
	 */
	public static function formatLine(Console $console, string $label, string $text, string $aside = ''): string
	{
		return self::wrapLines(
			$console,
			self::formatLabel($console, $label) . self::highlightCode($console, $text)
			. ($aside === '' ? '' : self::formatAside($console, $aside)),
		);
	}


	/** The label a line of the output opens with, in gray and as wide as the others, so that the values line up. */
	public static function formatLabel(Console $console, string $label): string
	{
		return $console->color('gray', mb_str_pad($label, self::LabelWidth));
	}


	/**
	 * Breaks the lines wider than the terminal at spaces, each line it adds indented to the values of the labels, so
	 * that the terminal does not break them under the labels; outside a terminal the text stays as it is, a line of a
	 * log or a pipe being as long as it needs.
	 */
	public static function wrapLines(Console $console, string $text, int $indent = self::LabelWidth): string
	{
		$width = $console->getWidth();
		if (!$console->isTerminal() || $width <= 2 * $indent) {
			return $text;
		}

		$lines = [];
		foreach (explode("\n", $text) as $line) {
			$wrapped = '';
			$column = 0;
			// the even pieces are the words, the odd ones the spaces between them, which a break takes the place of
			foreach (preg_split('~( +)~', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $i => $piece) {
				$size = Ansi::measure($piece);
				if ($i % 2 === 0 && $column > $indent && $column + $size > $width) {
					$wrapped = rtrim($wrapped, ' ') . "\n" . str_repeat(' ', $indent);
					$column = $indent;
				}

				$wrapped .= $piece;
				$column += $size;
			}

			$lines[] = $wrapped;
		}

		return implode("\n", $lines);
	}
}
