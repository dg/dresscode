<?php declare(strict_types=1);

// the project and the run of init that the tests of init.*.phpt share

use DressCode\Console\Application;


/**
 * A project root of its own with the files given, relative path → content.
 * @param  array<string, string>  $files
 */
function createProject(string $name, array $files): string
{
	$root = createTempDir($name);
	foreach ($files as $path => $content) {
		@mkdir(dirname("$root/$path"), recursive: true); // @ directory may already exist
		file_put_contents("$root/$path", $content);
	}

	return $root;
}


/**
 * @param  list<string>  $args
 * @param  ?int  $jobs  1 keeps the probes in the process, where they cost a fraction; null starts them as a user's run does
 * @return array{int, string, string}
 */
function runInit(string $root, array $args = [], ?int $jobs = 1): array
{
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	if ($jobs !== null) {
		$args = [...$args, '--jobs', (string) $jobs];
	}

	$code = new Application($out, $err, cwd: $root)->run(['dresscode', 'init', ...$args]);
	rewind($out);
	rewind($err);
	return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
}


/** @return array<string, string>  twenty files, three of them without the blank line the others have after the tag */
function stragglers(): array
{
	$files = ['composer.json' => '{}'];
	foreach (range(1, 20) as $i) {
		$files[sprintf('src/f%02d.php', $i)] = $i <= 3 ? "<?php\n\$a = $i;\n" : "<?php\n\n\$a = $i;\n";
	}

	return $files;
}
