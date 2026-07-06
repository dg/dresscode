<?php declare(strict_types=1);

namespace DressCode\Engine;

use function count;


/**
 * Unified diff of two texts, line by line.
 * @internal
 */
final class Diff
{
	public static function unified(string $old, string $new, string $path, int $context = 3): string
	{
		if ($old === $new) {
			return '';
		}

		$a = preg_split('~(?<=\n)~', $old, -1, PREG_SPLIT_NO_EMPTY);
		$b = preg_split('~(?<=\n)~', $new, -1, PREG_SPLIT_NO_EMPTY);
		$edits = self::edits($a, $b);
		$output = "--- $path\n+++ $path\n";
		$count = count($edits);
		$i = 0;
		while ($i < $count) {
			if ($edits[$i][0] === ' ') {
				$i++;
				continue;
			}

			$start = max(0, $i - $context);
			$end = $i;
			while ($end < $count) {
				if ($edits[$end][0] !== ' ') {
					$end++;
					continue;
				}

				$next = $end;
				while ($next < $count && $edits[$next][0] === ' ' && $next - $end < $context * 2) {
					$next++;
				}

				if ($next >= $count || $edits[$next][0] === ' ') {
					break;
				}

				$end = $next;
			}

			$end = min($count, $end + $context);
			$oldLines = $newLines = 0;
			$oldStart = $newStart = 0;
			for ($j = 0; $j < $start; $j++) {
				$oldStart += $edits[$j][0] !== '+' ? 1 : 0;
				$newStart += $edits[$j][0] !== '-' ? 1 : 0;
			}

			$body = '';
			for ($j = $start; $j < $end; $j++) {
				[$kind, $line] = $edits[$j];
				$oldLines += $kind !== '+' ? 1 : 0;
				$newLines += $kind !== '-' ? 1 : 0;
				$body .= $kind . rtrim($line, "\r\n") . "\n";
				if (!str_ends_with($line, "\n")) {
					$body .= "\\ No newline at end of file\n";
				}
			}

			$output .= sprintf("@@ -%d,%d +%d,%d @@\n", $oldStart + 1, $oldLines, $newStart + 1, $newLines) . $body;
			$i = $end;
		}

		return $output;
	}


	/**
	 * Edit script as [' ' | '-' | '+', line]; the common prefix and suffix are matched directly, the rest
	 * by the longest common subsequence.
	 * @param  list<string>  $a
	 * @param  list<string>  $b
	 * @return list<array{string, string}>
	 */
	private static function edits(array $a, array $b): array
	{
		$prefix = 0;
		while ($prefix < count($a) && $prefix < count($b) && $a[$prefix] === $b[$prefix]) {
			$prefix++;
		}

		$suffix = 0;
		while (
			$suffix < count($a) - $prefix
			&& $suffix < count($b) - $prefix
			&& $a[count($a) - 1 - $suffix] === $b[count($b) - 1 - $suffix]
		) {
			$suffix++;
		}

		$middleA = array_slice($a, $prefix, count($a) - $prefix - $suffix);
		$middleB = array_slice($b, $prefix, count($b) - $prefix - $suffix);
		$n = count($middleA);
		$m = count($middleB);
		$lengths = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
		for ($i = $n - 1; $i >= 0; $i--) {
			for ($j = $m - 1; $j >= 0; $j--) {
				$lengths[$i][$j] = $middleA[$i] === $middleB[$j]
					? $lengths[$i + 1][$j + 1] + 1
					: max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
			}
		}

		$edits = [];
		for ($i = 0; $i < $prefix; $i++) {
			$edits[] = [' ', $a[$i]];
		}

		$i = $j = 0;
		while ($i < $n || $j < $m) {
			if ($i < $n && $j < $m && $middleA[$i] === $middleB[$j]) {
				$edits[] = [' ', $middleA[$i++]];
				$j++;
			} elseif ($j < $m && ($i >= $n || $lengths[$i][$j + 1] > $lengths[$i + 1][$j])) {
				$edits[] = ['+', $middleB[$j++]];
			} else {
				$edits[] = ['-', $middleA[$i++]];
			}
		}

		for ($i = count($a) - $suffix; $i < count($a); $i++) {
			$edits[] = [' ', $a[$i]];
		}

		return $edits;
	}
}
