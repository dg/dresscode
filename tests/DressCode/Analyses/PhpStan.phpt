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
		[getPhpCliBinary(), "$root/inspect.php", __DIR__ . '/../../../vendor/autoload.php', "$root/origin.json"],
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


/**
 * A project whose `bootstrapFiles` register the autoloader of an older copy of phpdoc-parser in front, as Composer
 * registers that of a project, serving the class given, which lacks what PHPStan calls; with `$load` the bootstrap loads
 * the class through it.
 */
function createBootstrappedProject(string $name, string $class, bool $load): string
{
	$root = createPhpDocProject($name, types: true);
	// a rule asks for the types before any reads a doc comment, so the classes of phpdoc-parser load after the bootstrap
	file_put_contents("$root/dresscode.neon", "use: [nette]\ntypeAnalysis: phpstan\npaths: [src]\ntargets:\n\tphp: '8.5'\n");
	file_put_contents("$root/src/Order.php", <<<'PHP'
		<?php declare(strict_types=1);

		namespace App;


		final class Order
		{
			/** @return list<string> */
			public function items(): array
			{
				return ['a', 'b'];
			}
		}

		PHP);
	file_put_contents("$root/phpstan.neon", "parameters:\n\tbootstrapFiles:\n\t\t- bootstrap.php\n");
	$cut = (int) strrpos($class, '\\');
	file_put_contents("$root/Old.php", '<?php namespace ' . substr($class, 0, $cut) . '; class ' . substr($class, $cut + 1) . " {}\n");
	$quoted = var_export($class, return: true);
	file_put_contents(
		"$root/bootstrap.php",
		"<?php\nspl_autoload_register(fn(string \$class) => \$class === $quoted ? require __DIR__ . '/Old.php' : null, prepend: true);\n"
		. ($load ? "class_exists($quoted);\n" : ''),
	);
	return $root;
}


/** @return array{int, string}  the exit code and the output of `check` over the project in a process of its own */
function runCheck(string $root): array
{
	$command = [getPhpCliBinary(), __DIR__ . '/../../../bin/dresscode', 'check', '--no-cache', '--jobs', '1', '--no-color'];
	$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root)
		?: throw new RuntimeException('Cannot start DressCode.');
	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
	return [proc_close($process), $output];
}


test('the autoloader the bootstrap files of the project register in front leaves phpdoc-parser to the archive', function () {
	$root = createBootstrappedProject('phpdoc-bootstrap-loader', PHPStan\PhpDocParser\Parser\TokenIterator::class, load: false);
	[$classes] = inspectPhpDocParserOrigin($root);
	Assert::contains(PHPStan\PhpDocParser\Parser\TokenIterator::class, array_keys($classes));
	Assert::same([], array_filter($classes, fn(string $file) => !str_starts_with($file, 'phar://')));
	[$exit, $output] = runCheck($root);
	Assert::true($exit < 2, $output);
	Assert::notContains('Warning', $output);
});


test('a class of phpdoc-parser the bootstrap files of the project load by autoloading comes from the archive', function () {
	$class = PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineArgument::class;
	$root = createBootstrappedProject('phpdoc-bootstrap-class', $class, load: true);
	[$classes] = inspectPhpDocParserOrigin($root);
	Assert::contains($class, array_keys($classes));
	Assert::same([], array_filter($classes, fn(string $file) => !str_starts_with($file, 'phar://')));
	[$exit, $output] = runCheck($root);
	Assert::true($exit < 2, $output);
	Assert::notContains('Warning', $output);
});


test('a class of phpdoc-parser the archive lacks, which the bootstrap files of the project load, is a warning of the run', function () {
	$class = 'PHPStan\PhpDocParser\Ast\PhpDoc\NewerTagValueNode';
	[$exit, $output] = runCheck(createBootstrappedProject('phpdoc-bootstrap-newer', $class, load: true));
	Assert::true($exit < 2, $output);
	Assert::same(1, substr_count($output, "Warning: The `bootstrapFiles` of PHPStan load `$class` from `"), $output);
	Assert::contains('`scanDirectories` or `scanFiles` give PHPStan the declarations without loading them.', $output);
});


test('without the types phpdoc-parser comes from the vendor and the archive of PHPStan stays closed', function () {
	[$classes, $phar] = inspectPhpDocParserOrigin(createPhpDocProject('phpdoc-origin-plain', types: false));
	Assert::contains(PHPStan\PhpDocParser\Parser\TokenIterator::class, array_keys($classes));
	Assert::same([], array_filter($classes, fn(string $file) => str_starts_with($file, 'phar://')));
	Assert::false($phar);
});


test('a class the project lacks, which PHPStan looks for through the autoloaders, stays whole in the file cache of opcache', function () {
	$probe = shell_exec(escapeshellarg(getPhpCliBinary()) . ' -d opcache.enable_cli=1 -r "echo (int) function_exists(\'opcache_get_status\');"');
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
		getPhpCliBinary(),
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
