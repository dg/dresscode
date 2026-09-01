<?php declare(strict_types=1);

/**
 * Parity harness: runs today's v3 `ecs fix` and the new `dresscode fix` (the nette/php preset from
 * the Coding-Standard v4 checkout) over two copies of a repository and groups the differences by the
 * DressCode rules that disagree with the v3 result. The verdict on each difference is manual triage:
 * v3 is known to misbehave in places, so a difference is not automatically a DressCode bug.
 * Usage: php tools/parity.php path/to/repo [--paths src,tests] [--out temp/parity/<name>]
 *
 * Requires the v3 install in temp/ecs-v3 (composer create-project nette/coding-standard temp/ecs-v3 "^3");
 * the v4 preset classes are autoloaded straight from the Coding-Standard checkout, so no reinstall is needed.
 */

use DressCode\AnalysisRegistry;
use DressCode\Config;
use DressCode\Config\EngineFactory;
use DressCode\Config\PresetResolver;
use DressCode\Config\RuleRegistry;
use DressCode\Engine\Diff;
use DressCode\Engine\FileProcessor;
use DressCode\PresetContext;
use PhpSyntax\PhpVersion;

require __DIR__ . '/../vendor/autoload.php';

$codingStandardDir = 'W:/libs/Coding-Standard';
spl_autoload_register(function (string $class) use ($codingStandardDir): void {
	if (str_starts_with($class, 'Nette\CodingStandard\\')) {
		require $codingStandardDir . '/src/' . strtr(substr($class, strlen('Nette\CodingStandard\\')), '\\', '/') . '.php';
	}
});


// arguments
$repo = null;
$paths = ['src', 'tests'];
$out = null;
$argv = $_SERVER['argv'];
for ($i = 1; $i < count($argv); $i++) {
	if ($argv[$i] === '--paths') {
		$paths = explode(',', $argv[++$i]);
	} elseif ($argv[$i] === '--out') {
		$out = $argv[++$i];
	} else {
		$repo = rtrim($argv[$i], '/\\');
	}
}

if ($repo === null || !is_dir($repo)) {
	fwrite(STDERR, "Usage: php tools/parity.php path/to/repo [--paths src,tests] [--out temp/parity/<name>]\n");
	exit(1);
}

$v3 = __DIR__ . '/../temp/ecs-v3/ecs';
if (!is_file($v3)) {
	fwrite(STDERR, "Missing the v3 install; run: composer create-project nette/coding-standard temp/ecs-v3 \"^3\"\n");
	exit(1);
}

$out ??= __DIR__ . '/../temp/parity/' . basename($repo);
$oldDir = "$out/old";
$newDir = "$out/new";
foreach ([$oldDir, $newDir, "$out/diffs"] as $dir) {
	if (is_dir($dir)) {
		removeDir($dir);
	}

	mkdir($dir, recursive: true);
}


// the file list, filtered the way v3 filters it, so both sides see the same files
$files = [];
foreach ($paths as $path) {
	if (!is_dir("$repo/$path")) {
		continue;
	}

	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$repo/$path", FilesystemIterator::SKIP_DOTS));
	foreach ($iterator as $file) {
		$relative = strtr(substr((string) $file, strlen($repo) + 1), '\\', '/');
		if (
			preg_match('~\.(php|phpt)$~D', $relative)
			&& !preg_match('~(^|/)(expected|temp|tmp|vendor)(/|$)~', $relative)
			&& !preg_match('~(^|/)fixtures[^/]*(/|$)~', $relative)
			&& (!preg_match('~@phpVersion\s+([0-9.]+)~i', (string) file_get_contents((string) $file), $m)
				|| version_compare(PHP_VERSION, $m[1], '>='))
		) {
			$files[] = $relative;
		}
	}
}

sort($files);
echo count($files), " files\n";

foreach ($files as $relative) {
	foreach ([$oldDir, $newDir] as $dir) {
		@mkdir(dirname("$dir/$relative"), recursive: true);
		copy("$repo/$relative", "$dir/$relative");
	}
}

foreach (['composer.json', 'ncs.xml', 'ncs.php'] as $config) {
	if (is_file("$repo/$config")) {
		copy("$repo/$config", "$oldDir/$config");
	}
}


// v3 over the old copy
echo "running v3 ecs fix...\n";
$command = 'cd ' . escapeshellarg($oldDir)
	. ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg((string) realpath($v3)) . ' fix --no-progress ' . implode(' ', array_map(escapeshellarg(...), $paths));
exec($command . ' 2>&1', $v3Output, $v3Exit);
file_put_contents("$out/v3-output.txt", implode("\n", $v3Output) . "\n");
echo "v3 exit: $v3Exit (output in v3-output.txt)\n";


// DressCode over the new copy, in-process with the live sources
$phpVersion = EngineFactory::detectPhpVersion("$repo/composer.json") ?? PhpVersion::lowest();

echo "running dresscode fix (php version $phpVersion)...\n";
$registry = new RuleRegistry;
$rules = (new PresetResolver($registry))->resolve(Config::create()->preset('Nette\CodingStandard\Presets\Php'), new PresetContext($phpVersion));
$processor = new FileProcessor($rules, new AnalysisRegistry, $registry->resolveNames(...), $phpVersion);

$errors = [];
foreach ($files as $relative) {
	$code = (string) file_get_contents("$newDir/$relative");
	try {
		$result = $processor->process($relative, $code);
	} catch (Throwable $e) {
		$errors[$relative] = $e->getMessage();
		continue;
	}

	if ($result->error !== null) {
		$errors[$relative] = $result->error;
	} elseif ($result->output !== null) {
		file_put_contents("$newDir/$relative", $result->output);
	}
}


// compare and attribute: rules that still fix the v3 result are the rules disagreeing with v3
echo "comparing...\n";
$byRule = [];
$same = 0;
foreach ($files as $relative) {
	$old = (string) file_get_contents("$oldDir/$relative");
	$new = (string) file_get_contents("$newDir/$relative");
	if ($old === $new) {
		$same++;
		continue;
	}

	$culprits = [];
	try {
		$overOld = $processor->process($relative, $old);
		foreach ($overOld->violations as $violation) {
			if ($violation->fixable) {
				$culprits[$violation->ruleName] = true;
			}
		}
	} catch (Throwable) {
	}

	$culprits = $culprits === [] ? ['(v3-only change)'] : array_keys($culprits);
	foreach ($culprits as $rule) {
		$byRule[$rule][] = $relative;
	}

	$diffPath = "$out/diffs/" . strtr($relative, '/', '~') . '.diff';
	file_put_contents($diffPath, Diff::unified($old, $new, $relative, context: 2));
}

$differing = count($files) - $same - count($errors);


// report
$report = '# Parity: ' . basename($repo) . "\n\n";
$report .= 'Files: ' . count($files) . ", same: $same, differing: $differing, failed: " . count($errors) . "\n";
$report .= "v3 exit code: $v3Exit\n\n";
$report .= "Old = v3 result, new = DressCode result; a rule is listed when it still wants to change\n";
$report .= "the v3 result, `(v3-only change)` when v3 changed something no DressCode rule cares about.\n\n";

ksort($byRule);
foreach ($byRule as $rule => $ruleFiles) {
	$report .= '## ' . $rule . ' (' . count($ruleFiles) . ")\n\n";
	foreach (array_unique($ruleFiles) as $file) {
		$report .= "- $file\n";
	}

	$report .= "\n";
}

if ($errors) {
	$report .= "## failed files\n\n";
	foreach ($errors as $file => $message) {
		$report .= "- $file: $message\n";
	}
}

file_put_contents("$out/report.md", $report);
echo "\nFiles: ", count($files), ", same: $same, differing: $differing, failed: ", count($errors), "\n";
foreach ($byRule as $rule => $ruleFiles) {
	echo str_pad($rule, 50), ' ', count(array_unique($ruleFiles)), "\n";
}

echo "\nReport: $out/report.md, diffs in $out/diffs/\n";
exit($differing === 0 && $errors === [] ? 0 : 1);


function removeDir(string $dir): void
{
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST,
	);
	foreach ($iterator as $file) {
		$file->isDir() ? rmdir((string) $file) : unlink((string) $file);
	}

	rmdir($dir);
}
