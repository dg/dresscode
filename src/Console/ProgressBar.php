<?php declare(strict_types=1);

namespace DressCode\Console;

use Nette\CommandLine\Console;
use function sprintf, strlen;


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

	/** a line wrapped by the terminal would survive the carriage return only in half */
	private const LineWidth = 78;

	/** @var resource */
	private $stream;

	private readonly float $started;
	private float $drawn = 0.0;

	/** visible length of the line on the screen */
	private int $width = 0;

	/** the line names a large file, which must be gone as soon as the next one starts */
	private bool $namesLarge = false;


	/** @param resource $stream */
	public function __construct(
		$stream,
		private readonly Console $console,
		private readonly int $total,
	) {
		$this->stream = $stream;
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
		$counter = sprintf('  %d/%d', $done, $this->total);
		$line = '  ' . $this->console->color('gray', '[' . str_repeat('=', $filled) . str_repeat(' ', self::BarWidth - $filled) . ']')
			. $counter;
		$width = 2 + self::BarWidth + 2 + strlen($counter);

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
				$path = self::shorten($slowest, self::LineWidth - $width - strlen($note) - 4);
				$line .= $this->console->color('gray', "  $path") . $this->console->color('yellow', "  $note");
			}
		}

		$this->draw($line);
	}


	/** Erases the line; the next advance draws it again. */
	public function clear(): void
	{
		$this->draw('');
	}


	private function draw(string $line): void
	{
		$visible = strlen((string) preg_replace('~\e\[[\d;]*m~', '', $line));
		if ($visible === 0 && $this->width === 0) {
			return;
		}

		fwrite($this->stream, "\r" . $line . str_repeat(' ', max(0, $this->width - $visible)) . "\r");
		$this->width = $visible;
	}


	/** Keeps the end of the path, where the file name is. */
	private static function shorten(string $path, int $width): string
	{
		return strlen($path) > $width
			? '...' . substr($path, -max(1, $width - 3))
			: $path;
	}


	private static function formatSize(int $bytes): string
	{
		return $bytes >= 1_000_000
			? sprintf('%.1f MB', $bytes / 1_000_000)
			: sprintf('%d kB', intdiv($bytes, 1000));
	}
}
