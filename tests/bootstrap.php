<?php declare(strict_types=1);

if (@!include __DIR__ . '/../vendor/autoload.php') {
	echo 'Install Nette Tester using `composer install`';
	exit(1);
}


Tester\Environment::setup();
Tester\Environment::setupFunctions();


/**
 * A new empty directory named after `$name`, numbered when the name comes again, in a directory that belongs to
 * this test process alone. It lies outside tests/, so the runner never takes a file a test writes for a test. A
 * test writes each path once and deletes nothing: on Windows a file another process holds, such as a scanner, can
 * be neither deleted nor created again at once. The directories of earlier runs go when no test process holds one.
 */
function createTempDir(string $name): string
{
	static $dir, $lock, $counts = [];
	if ($dir === null) {
		$base = __DIR__ . '/../temp/tests';
		@mkdir($base, recursive: true); // @ - may exist
		$lock = fopen("$base.lock", 'c') ?: throw new RuntimeException("Cannot open $base.lock.");
		if (flock($lock, LOCK_EX | LOCK_NB)) {
			try {
				@Tester\Helpers::purge($base); // @ - what cannot be deleted now goes next time
			} catch (UnexpectedValueException) {
			}
			flock($lock, LOCK_UN);
		}

		flock($lock, LOCK_SH);
		$dir = $base . '/' . getmypid() . '-' . bin2hex(random_bytes(4));
		mkdir($dir);
	}

	$count = $counts[$name] = ($counts[$name] ?? 0) + 1;
	$path = $dir . '/' . $name . ($count > 1 ? "-$count" : '');
	mkdir($path, recursive: true);
	return str_replace('\\', '/', (string) realpath($path));
}
