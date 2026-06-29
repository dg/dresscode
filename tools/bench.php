<?php declare(strict_types=1);

/**
 * Measures parse + print over a directory of PHP files: time, peak memory, the slowest files,
 * and optionally the same with nikic/php-parser for comparison.
 *
 * Usage: php tools/bench.php <dir> [--php-parser=<path to PHP-Parser checkout>] [--cold=<file>]
 * --cold parses a single file and reports the time since the process started (autoload included).
 */

use PhpSyntax\Parser\Parser;
use PhpSyntax\Printer;

require __DIR__ . '/../vendor/autoload.php';

$options = [];
$dir = null;
foreach (array_slice($_SERVER['argv'], 1) as $arg) {
	if (preg_match('~^--(\w[\w-]*)=(.*)$~', $arg, $m)) {
		$options[$m[1]] = $m[2];
	} else {
		$dir = $arg;
	}
}

if (isset($options['cold'])) {
	$parser = new Parser;
	$code = (string) file_get_contents($options['cold']);
	Printer::print($parser->parse($code));
	printf("cold start: %.1f ms, %.1f MB peak\n", (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, memory_get_peak_usage() / 1e6);
	exit;
}

if ($dir === null || !is_dir($dir)) {
	fwrite(STDERR, "Usage: php tools/bench.php <dir> [--php-parser=<path>] [--cold=<file>]\n");
	exit(1);
}

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
	if ($file->getExtension() === 'php') {
		$files[] = $file->getPathname();
	}
}

sort($files);
$sizes = array_map(filesize(...), $files);
printf("%d files, %.1f MB\n\n", count($files), array_sum($sizes) / 1e6);


/**
 * @param  list<string>  $files
 * @param  callable(string): mixed  $process
 * @return array{float, float, array<string, float>}  total seconds, peak MB, seconds per file
 */
function measure(array $files, callable $process): array
{
	$times = [];
	$peak = 0;
	$errors = 0;
	$start = hrtime(true);
	foreach ($files as $file) {
		$code = (string) file_get_contents($file);
		$before = memory_get_usage();
		$t = hrtime(true);
		try {
			$process($code);
		} catch (Throwable) {
			$errors++;
		}

		$times[$file] = (hrtime(true) - $t) / 1e9;
		$peak = max($peak, memory_get_peak_usage() - $before);
		gc_collect_cycles();
	}

	$total = (hrtime(true) - $start) / 1e9;
	if ($errors) {
		echo "  ($errors files rejected)\n";
	}

	return [$total, $peak / 1e6, $times];
}


/**
 * @param  list<string>  $files
 * @param  callable(string): mixed  $process
 */
function report(string $label, array $files, callable $process): void
{
	[$total, $peak, $times] = measure($files, $process);
	printf("%s: %.2f s total, %.1f files/s, %.1f MB peak above baseline\n", $label, $total, count($files) / $total, $peak);
	arsort($times);
	foreach (array_slice($times, 0, 10, preserve_keys: true) as $file => $time) {
		printf("  %6.1f ms  %7.1f kB  %s\n", $time * 1000, filesize($file) / 1e3, $file);
	}

	echo "\n";
}


$parser = new Parser;
report('DressCode parse', $files, fn(string $code) => $parser->parse($code));
report('DressCode parse + print', $files, fn(string $code) => Printer::print($parser->parse($code)));

if (isset($options['php-parser'])) {
	$lib = rtrim($options['php-parser'], '/\\') . '/lib/';
	spl_autoload_register(function (string $class) use ($lib) {
		if (str_starts_with($class, 'PhpParser\\')) {
			require $lib . strtr($class, '\\', '/') . '.php';
		}
	});
	$phpParser = (new PhpParser\ParserFactory)->createForNewestSupportedVersion();
	report('php-parser parse', $files, fn(string $code) => $phpParser->parse($code));
}
