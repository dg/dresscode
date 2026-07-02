<?php declare(strict_types=1);

/**
 * Rewrites the expected output of failed dump fixtures from their .actual files. Review the diff before committing.
 */

$updated = 0;
foreach (glob(__DIR__ . '/PhpSyntax/Parser/dump/output/*.actual') as $actualFile) {
	$testFile = dirname($actualFile, 2) . '/' . basename($actualFile, '.actual') . '.phpt';
	if (!is_file($testFile)) {
		continue;
	}

	$test = (string) file_get_contents($testFile);
	$marker = "\n__halt_compiler();";
	$position = strrpos($test, $marker);
	if ($position === false) {
		echo "No __halt_compiler() in $testFile\n";
		continue;
	}

	$test = substr($test, 0, $position) . $marker . "\n" . file_get_contents($actualFile);
	file_put_contents($testFile, $test);
	unlink($actualFile);
	@unlink(substr($actualFile, 0, -7) . '.expected');
	echo "Updated $testFile\n";
	$updated++;
}

echo "$updated fixtures updated\n";
