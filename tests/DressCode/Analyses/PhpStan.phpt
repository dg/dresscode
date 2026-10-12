<?php declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\{Assert, Environment};

require __DIR__ . '/../../bootstrap.php';


/**
 * The file each class of phpdoc-parser came from once `check` ran over the project in a process of its own, and whether
 * the process opened the archive of PHPStan.
 * @return array{array<string, string>, bool}
 */
function inspectPhpDocParserOrigin(string $root): array
{
	$script = <<<'PHP'
		<?php declare(strict_types=1);
		require $argv[1];
		ob_start();
		(new DressCode\Console\Application)->run(['dresscode', 'check', '--no-cache', '--jobs', '1', '--no-color']);
		ob_end_clean();
		$classes = [];
		foreach ([...get_declared_classes(), ...get_declared_interfaces()] as $class) {
			if (str_starts_with($class, 'PHPStan\PhpDocParser\\')) {
				$classes[$class] = (string) (new ReflectionClass($class))->getFileName();
			}
		}
		$phar = array_any(get_included_files(), fn($file) => str_starts_with($file, 'phar://'));
		file_put_contents($argv[2], json_encode([$classes, $phar]));
		PHP;
	file_put_contents("$root/inspect.php", $script);
	$process = proc_open(
		[PHP_BINARY, "$root/inspect.php", __DIR__ . '/../../../vendor/autoload.php', "$root/origin.json"],
		[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
		$pipes,
		$root,
	) ?: throw new RuntimeException('Cannot start DressCode.');
	$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
	proc_close($process);
	Assert::true(is_file("$root/origin.json"), $output);
	return json_decode(FileSystem::read("$root/origin.json"), associative: true);
}


/** A project whose code has doc comments, checked by the standard nette with or without the types. */
function createPhpDocProject(string $name, bool $types): string
{
	$root = createTempDir($name);
	mkdir("$root/src");
	file_put_contents("$root/composer.json", "{}\n");
	file_put_contents("$root/dresscode.neon", "use: [nette]\n" . ($types ? "typeAnalysis: phpstan\n" : '') . "paths: [src]\n");
	file_put_contents("$root/src/Order.php", <<<'PHP'
		<?php declare(strict_types=1);

		namespace App;


		/**
		 * @deprecated  use Invoice
		 */
		class Order
		{
			/** @param  array<string, int>  $items  name => count */
			public function add(array $items): void
			{
			}
		}
		PHP);
	return $root;
}


test('with the types every class of phpdoc-parser comes from the archive of PHPStan', function () {
	[$classes] = inspectPhpDocParserOrigin(createPhpDocProject('phpdoc-origin-types', types: true));
	Assert::contains(PHPStan\PhpDocParser\Parser\TypeParser::class, array_keys($classes));
	Assert::contains(PHPStan\PhpDocParser\Parser\TokenIterator::class, array_keys($classes));
	Assert::contains(PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode::class, array_keys($classes));
	Assert::same([], array_filter($classes, fn(string $file) => !str_starts_with($file, 'phar://')));
});


test('without the types phpdoc-parser comes from the vendor and the archive of PHPStan stays closed', function () {
	[$classes, $phar] = inspectPhpDocParserOrigin(createPhpDocProject('phpdoc-origin-plain', types: false));
	Assert::contains(PHPStan\PhpDocParser\Parser\TokenIterator::class, array_keys($classes));
	Assert::same([], array_filter($classes, fn(string $file) => str_starts_with($file, 'phar://')));
	Assert::false($phar);
});


test('a class the project lacks, which PHPStan looks for through the autoloaders, stays whole in the file cache of opcache', function () {
	$probe = shell_exec(escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=1 -r "echo (int) function_exists(\'opcache_get_status\');"');
	if ($probe !== '1') {
		Environment::skip('opcache is not loaded');
	}

	$root = createTempDir('phpstan-opcache');
	mkdir("$root/src");
	mkdir("$root/opcache");
	file_put_contents("$root/composer.json", "{}\n");
	file_put_contents("$root/dresscode.neon", "use: [nette]\ntypeAnalysis: phpstan\npaths: [src]\ncacheDir: cache\n");
	// code naming classes of DressCode itself, some of which the process loads only after PHPStan has looked for them
	$code = FileSystem::read(__DIR__ . '/../../../src/Rules/NodeHelpers.php');
	file_put_contents("$root/src/Helpers.php", str_replace('namespace DressCode\Rules;', 'namespace App;', $code));
	$command = [
		PHP_BINARY,
		'-d', 'opcache.enable_cli=1',
		'-d', "opcache.file_cache=$root/opcache",
		'-d', 'opcache.file_cache_only=1',
		__DIR__ . '/../../../bin/dresscode',
		'check', '--no-cache', '--jobs', '1', '--no-color',
	];
	foreach ([1, 2] as $run) {
		$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root)
			?: throw new RuntimeException('Cannot start DressCode.');
		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		$exit = proc_close($process);
		Assert::true($exit < 2, "run $run exits $exit: $output");
	}
});
