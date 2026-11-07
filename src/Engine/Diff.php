<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use function count;


/**
 * Unified diff of two texts, line by line.
 * @internal
 */
final class Diff
{
	private const Context = 3;


	/** @param ?string $newPath  the label of the new side, the same as the old one when null */
	public static function unified(string $old, string $new, string $path, ?string $newPath = null): string
	{
		if ($old === $new) {
			return '';
		}

		$output = "--- $path\n+++ " . ($newPath ?? $path) . "\n";
		foreach (self::computeHunks($old, $new) as $hunk) {
			$output .= $hunk->header . "\n" . implode("\n", $hunk->lines) . "\n";
		}

		return $output;
	}


	/**
	 * The changes of the second text against the first, each with three lines of context.
	 * @return list<DiffHunk>
	 */
	public static function computeHunks(string $old, string $new): array
	{
		$a = preg_split('~(?<=\n)~', $old, -1, PREG_SPLIT_NO_EMPTY);
		$b = preg_split('~(?<=\n)~', $new, -1, PREG_SPLIT_NO_EMPTY);
		$edits = self::computeEdits($a, $b);
		$hunks = [];
		$count = count($edits);
		$i = 0;
		while ($i < $count) {
			if ($edits[$i][0] === ' ') {
				$i++;
				continue;
			}

			$start = max(0, $i - self::Context);
			$end = $i;
			while ($end < $count) {
				if ($edits[$end][0] !== ' ') {
					$end++;
					continue;
				}

				$next = $end;
				while ($next < $count && $edits[$next][0] === ' ' && $next - $end < self::Context * 2) {
					$next++;
				}

				if ($next >= $count || $edits[$next][0] === ' ') {
					break;
				}

				$end = $next;
			}

			$end = min($count, $end + self::Context);
			$oldLines = $newLines = 0;
			$oldStart = $newStart = 0;
			for ($j = 0; $j < $start; $j++) {
				$oldStart += $edits[$j][0] !== '+' ? 1 : 0;
				$newStart += $edits[$j][0] !== '-' ? 1 : 0;
			}

			$lines = [];
			for ($j = $start; $j < $end; $j++) {
				[$kind, $line] = $edits[$j];
				$oldLines += $kind !== '+' ? 1 : 0;
				$newLines += $kind !== '-' ? 1 : 0;
				$lines[] = $kind . rtrim($line, "\r\n");
				if (!str_ends_with($line, "\n")) {
					$lines[] = '\ No newline at end of file';
				}
			}

			// a side without lines names the line after which they would stand
			$hunks[] = new DiffHunk(
				$oldLines ? $oldStart + 1 : $oldStart,
				$oldLines,
				$newLines ? $newStart + 1 : $newStart,
				$newLines,
				$lines,
			);
			$i = $end;
		}

		return $hunks;
	}


	/**
	 * The change of the second text nearest a line of the first, as the lines of a unified diff with the line above it and
	 * the line below it; none where the texts are the same.
	 * @return list<string>
	 */
	public static function findChangeNear(string $old, string $new, int $line): array
	{
		$nearest = [];
		$distance = PHP_INT_MAX;
		foreach (self::computeHunks($old, $new) as $hunk) {
			$at = $hunk->oldStart; // of the old side, which a line added does not move
			$start = $above = null;
			$change = [];
			foreach ([...$hunk->lines, null] as $text) { // null ends the last change
				if ($text !== null && ($text[0] === '-' || $text[0] === '+')) {
					$start ??= $at;
					$change[] = $text;
					$at += $text[0] === '-' ? 1 : 0;
					continue;
				} elseif ($text !== null && $text[0] !== ' ') { // no newline at the end of the file
					continue;
				} elseif ($start !== null) {
					$away = $line < $start ? $start - $line : max(0, $line - $at + 1);
					if ($away < $distance) {
						$nearest = [...($above === null ? [] : [$above]), ...$change, ...($text === null ? [] : [$text])];
						$distance = $away;
					}

					[$change, $start] = [[], null];
				}

				$above = $text;
				$at++;
			}
		}

		return $nearest;
	}


	/**
	 * Edit script as [`' '` | `'-'` | `'+'`, line], the removed lines of a change before the added ones. A line
	 * found on one side only cannot match, so it is left out of the search, which keeps a file indented anew cheap.
	 * @param  list<string>  $a
	 * @param  list<string>  $b
	 * @return list<array{string, string}>
	 */
	private static function computeEdits(array $a, array $b): array
	{
		$ids = $idsA = $idsB = [];
		foreach ($a as $line) {
			$idsA[] = $ids[$line] ??= count($ids);
		}

		foreach ($b as $line) {
			$idsB[] = $ids[$line] ??= count($ids);
		}

		$inA = array_flip($idsA);
		$inB = array_flip($idsB);
		$keptA = array_keys(array_filter($idsA, fn($id) => isset($inB[$id])));
		$keptB = array_keys(array_filter($idsB, fn($id) => isset($inA[$id])));
		$pairs = [];
		self::collectPairs(
			array_map(fn($i) => $idsA[$i], $keptA),
			0,
			count($keptA),
			array_map(fn($j) => $idsB[$j], $keptB),
			0,
			count($keptB),
			$pairs,
		);

		$edits = [];
		$i = $j = 0;
		foreach ([...$pairs, null] as $pair) {
			$x = $pair === null ? count($a) : $keptA[$pair[0]];
			$y = $pair === null ? count($b) : $keptB[$pair[1]];
			for (; $i < $x; $i++) {
				$edits[] = ['-', $a[$i]];
			}

			for (; $j < $y; $j++) {
				$edits[] = ['+', $b[$j]];
			}

			if ($pair !== null) {
				$edits[] = [' ', $a[$i++]];
				$j++;
			}
		}

		return $edits;
	}


	/**
	 * Collects the pairs of indexes of a longest common subsequence of the ranges, in order (Myers, in linear space).
	 * @param  list<int>  $a
	 * @param  list<int>  $b
	 * @param  list<array{int, int}>  $pairs
	 */
	private static function collectPairs(array $a, int $aLo, int $aHi, array $b, int $bLo, int $bHi, array &$pairs): void
	{
		while ($aLo < $aHi && $bLo < $bHi && $a[$aLo] === $b[$bLo]) {
			$pairs[] = [$aLo++, $bLo++];
		}

		$suffix = [];
		while ($aLo < $aHi && $bLo < $bHi && $a[$aHi - 1] === $b[$bHi - 1]) {
			$suffix[] = [--$aHi, --$bHi];
		}

		if ($aLo < $aHi && $bLo < $bHi) {
			[$x, $y, $u, $v] = self::findMiddleSnake($a, $aLo, $aHi, $b, $bLo, $bHi);
			self::collectPairs($a, $aLo, $x, $b, $bLo, $y, $pairs);
			while ($x < $u) {
				$pairs[] = [$x++, $y++];
			}

			self::collectPairs($a, $u, $aHi, $b, $v, $bHi, $pairs);
		}

		array_push($pairs, ...array_reverse($suffix));
	}


	/**
	 * The diagonal run where the paths of fewest edits from both ends meet, as [x, y, end x, end y].
	 * @param  list<int>  $a
	 * @param  list<int>  $b
	 * @return array{int, int, int, int}
	 */
	private static function findMiddleSnake(array $a, int $aLo, int $aHi, array $b, int $bLo, int $bHi): array
	{
		$n = $aHi - $aLo;
		$m = $bHi - $bLo;
		$delta = $n - $m;
		$odd = ($delta & 1) === 1;
		$forward = $backward = [1 => 0];
		for ($d = 0;; $d++) {
			for ($k = -$d; $k <= $d; $k += 2) {
				$x = $k === -$d || ($k !== $d && $forward[$k - 1] < $forward[$k + 1]) ? $forward[$k + 1] : $forward[$k - 1] + 1;
				$x0 = $x;
				$y0 = $y = $x - $k;
				while ($x < $n && $y < $m && $a[$aLo + $x] === $b[$bLo + $y]) {
					$x++;
					$y++;
				}

				$forward[$k] = $x;
				if ($odd && abs($delta - $k) < $d && $x + $backward[$delta - $k] >= $n) {
					return [$aLo + $x0, $bLo + $y0, $aLo + $x, $bLo + $y];
				}
			}

			for ($k = -$d; $k <= $d; $k += 2) {
				$x = $k === -$d || ($k !== $d && $backward[$k - 1] < $backward[$k + 1]) ? $backward[$k + 1] : $backward[$k - 1] + 1;
				$x0 = $x;
				$y0 = $y = $x - $k;
				while ($x < $n && $y < $m && $a[$aHi - 1 - $x] === $b[$bHi - 1 - $y]) {
					$x++;
					$y++;
				}

				$backward[$k] = $x;
				if (!$odd && abs($delta - $k) <= $d && $x + $forward[$delta - $k] >= $n) {
					return [$aHi - $x, $bHi - $y, $aHi - $x0, $bHi - $y0];
				}
			}
		}
	}
}
