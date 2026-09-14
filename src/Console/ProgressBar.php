<?php declare(strict_types=1);

namespace DressCode\Console;

use Nette\CommandLine\Ansi;
use Nette\CommandLine\Console;
use function sprintf;


/**
 * One line rewritten in place: how far the run got and, when a file takes unusually long, which one it waits for.
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

	private readonly float $started;
	private float $drawn = 0.0;


	public function __construct(
		private readonly Console $console,
		private readonly int $total,
	) {
		$this->started = microtime(as_float: true);
	}


	/**
	 * @param array<string, float> $running  path in progress → the time it started
	 */
	public function advance(int $done, array $running = []): void
	{
		if ($done >= $this->total) {
			$this->finish();
			return;
		}

		$now = microtime(as_float: true);
		if ($now - $this->started < self::Delay || $now - $this->drawn < self::RedrawInterval) {
			return;
		}

		$this->drawn = $now;
		$filled = (int) round(self::BarWidth * $done / $this->total);
		$line = '  ' . $this->console->color('gray', '[' . str_repeat('=', $filled) . str_repeat(' ', self::BarWidth - $filled) . ']')
			. sprintf('  %d/%d', $done, $this->total);

		asort($running);
		$slowest = array_key_first($running);
		if ($slowest !== null && $now - $running[$slowest] > self::SlowFile) {
			$seconds = sprintf('%ds', (int) ($now - $running[$slowest]));
			$room = $this->console->getWidth() - Ansi::measure($line) - Ansi::measure($seconds) - 4;
			$line .= $this->console->color('gray', '  ' . Ansi::truncate($slowest, max(1, $room), keepEnd: true))
				. $this->console->color('yellow', "  $seconds");
		}

		$this->console->setStatus($line);
	}


	public function finish(): void
	{
		$this->console->clearStatus();
	}
}
