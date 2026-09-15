<?php declare(strict_types=1);

namespace DressCode\Console;

use Nette\CommandLine\Ansi;
use Nette\CommandLine\Console;
use function sprintf;


/**
 * One line rewritten in place: how far the run got and which file it waits for, when that one takes unusually long
 * or, where nothing is heard of it until it is done, when it is large.
 * Nothing is drawn until the run proves slow enough to be worth watching, and the line is erased at the end.
 * @internal
 */
final class ProgressBar
{
	private const BarWidth = 20;

	/** the run is over before this and the bar would only flash */
	private const Delay = 0.3;

	private const RedrawInterval = 0.1;

	/** a file processed longer than this is worth naming */
	private const SlowFile = 2.0;

	/** bytes; a file this large is worth naming at once when its time cannot be watched */
	private const LargeFile = 30_000;

	private readonly float $started;
	private float $drawn = 0.0;

	/** the line names a large file, which must be gone as soon as the next one starts */
	private bool $namesLarge = false;


	public function __construct(
		private readonly Console $console,
		private readonly int $total,
	) {
		$this->started = microtime(as_float: true);
	}


	/**
	 * @param array<string, float> $running  path in progress → the time it started
	 * @param ?int $size  of the file in progress when nothing is heard of it until it is done
	 */
	public function advance(int $done, array $running = [], ?int $size = null): void
	{
		if ($done >= $this->total) {
			$this->clear();
			return;
		}

		$now = microtime(as_float: true);
		$large = $size !== null && $size > self::LargeFile;
		if (
			!$large
			&& !$this->namesLarge
			&& ($now - $this->started < self::Delay || $now - $this->drawn < self::RedrawInterval)
		) {
			return;
		}

		$this->drawn = $now;
		$this->namesLarge = $large;
		$filled = (int) round(self::BarWidth * $done / $this->total);
		$line = '  ' . $this->console->color('gray', '[' . str_repeat('=', $filled) . str_repeat(' ', self::BarWidth - $filled) . ']')
			. sprintf('  %d/%d', $done, $this->total);

		asort($running);
		$slowest = array_key_first($running);
		if ($slowest !== null) {
			$elapsed = $now - $running[$slowest];
			$note = match (true) {
				$large => self::formatSize($size),
				$elapsed > self::SlowFile => sprintf('%ds', (int) $elapsed),
				default => null,
			};
			if ($note !== null) {
				$room = $this->console->getWidth() - Ansi::measure($line) - Ansi::measure($note) - 4;
				$line .= $this->console->color('gray', '  ' . Ansi::truncate($slowest, max(1, $room), keepEnd: true))
					. $this->console->color('yellow', "  $note");
			}
		}

		$this->console->setStatus($line);
	}


	/** Erases the line; the next advance draws it again. */
	public function clear(): void
	{
		$this->console->clearStatus();
	}


	private static function formatSize(int $bytes): string
	{
		return $bytes >= 1_000_000
			? sprintf('%.1f MB', $bytes / 1_000_000)
			: sprintf('%d kB', intdiv($bytes, 1000));
	}
}
