<?php declare(strict_types=1);

if (@!include __DIR__ . '/../vendor/autoload.php') { // @ dependencies may not be installed
	echo 'Install Nette Tester using `composer install`';
	exit(1);
}


spl_autoload_register(function (string $class): void {
	if (str_starts_with($class, 'Acme\DressCode\\')) {
		require __DIR__ . '/fixtures/plugin/src/' . strtr(substr($class, 15), '\\', '/') . '.php';
	}
});

Tester\Environment::setup();
Tester\Environment::setupFunctions();

putenv('GITHUB_ACTIONS'); // the format of a report depends on it
putenv('GITHUB_WORKSPACE');


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
		@mkdir($base, recursive: true); // @ directory may already exist
		$lock = fopen("$base.lock", 'c') ?: throw new RuntimeException("Cannot open $base.lock.");
		if (flock($lock, LOCK_EX | LOCK_NB)) {
			try {
				@Tester\Helpers::purge($base); // @ what cannot be deleted now goes next time
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


/** A rule of a test that decides one requirement of the project, `project.<its slug>`. */
trait ProjectDecision
{
	public static function getDecisions(): array
	{
		return [new DressCode\Decision('project.' . lcfirst(ruleSlug(static::class)), DressCode\Domain::state(), 'What the rule of the test checks')];
	}
}


/** The class of a rule as its fixtures and the lists of the tests name it: without the suffix, the first letter in lower case. */
function ruleSlug(DressCode\Rule|string $rule): string
{
	$class = is_string($rule) ? $rule : $rule::class;
	$short = substr($class, (int) strrpos('\\' . $class, '\\'));
	return str_ends_with($short, 'Rule') && $short !== 'Rule' ? lcfirst(substr($short, 0, -4)) : $short;
}
