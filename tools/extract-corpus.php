<?php declare(strict_types=1);

/**
 * Extracts parser inputs from the nikic/php-parser test suite into tests/PhpSyntax/Parser/corpus/php-parser/.
 * Usage: php tools/extract-corpus.php path/to/PHP-Parser/test/code/parser
 *
 * A .test file holds a name and pairs of code and expected dump separated by "-----" lines; the first line
 * of a code section may be a "!!version=X.Y" or "!!positions" mode. Only code PHP 8 accepts is kept;
 * inputs for a version below 8.0 and the errorHandling/ directory are skipped, "@@{ expr }@@" is evaluated.
 */

$source = rtrim($argv[1] ?? '', '/\\');
$target = __DIR__ . '/../tests/PhpSyntax/Parser/corpus/php-parser';
if (!is_dir($source)) {
	fwrite(STDERR, "Usage: php tools/extract-corpus.php path/to/PHP-Parser/test/code/parser\n");
	exit(1);
}

$kept = $skipped = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
	$relative = strtr(substr($file->getPathname(), strlen($source) + 1), '\\', '/');
	if (!str_ends_with($relative, '.test') || str_starts_with($relative, 'errorHandling/')) {
		continue;
	}

	$parts = array_map(trim(...), explode('-----', (string) file_get_contents($file->getPathname())));
	array_shift($parts); // name
	$inputs = [];
	for ($i = 0; $i + 1 < count($parts); $i += 2) {
		$code = $parts[$i];
		$version = null;
		if (preg_match('~^!!(\S+)\n?~', $code, $m)) {
			$code = substr($code, strlen($m[0]));
			if (preg_match('~^version=(\d+\.\d+)$~', $m[1], $v)) {
				$version = $v[1];
				if (version_compare($version, '8.0', '<')) {
					$skipped++;
					continue;
				}
			}
		}

		$code = preg_replace_callback('~@@\{(.*?)\}@@~s', fn($m) => eval("return $m[1];"), $code);
		$inputs[] = [$code, $version];
	}

	foreach ($inputs as $index => [$code, $version]) {
		$path = $target . '/' . substr($relative, 0, -5) . (count($inputs) > 1 ? '-' . ($index + 1) : '') . '.php';
		$temp = tempnam(sys_get_temp_dir(), 'dc');
		file_put_contents($temp, $code);
		exec(escapeshellarg(PHP_BINARY) . ' -n -l ' . escapeshellarg($temp) . ' 2>&1', $output, $exitCode);
		unlink($temp);
		if ($exitCode !== 0) {
			$skipped++;
			continue;
		}

		if ($version !== null) {
			$code = preg_replace('~^(<\?php)?~', "\$1// @phpVersion $version\n", $code, 1);
		}

		@mkdir(dirname($path), 0o777, true);
		file_put_contents($path, $code);
		$kept++;
	}
}

echo "$kept inputs written, $skipped skipped\n";
