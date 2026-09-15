<?php declare(strict_types=1);

/**
 * The maps of the upgrading rules reach one and the same use, and which of them decides is part of their contract.
 * A pair is run over one file with the types of the code, because that is what tells a rule whose member a use reaches.
 */

use DressCode\{Analyses, Config, Style, Violation};
use DressCode\Config\PresetResolver;
use DressCode\Engine\PassRunner;
use DressCode\Rules\Upgrading;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\{Parser, Printer};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/**
 * Runs the rules over the code with the types of the declarations beside it, and returns what they say.
 * @param  array<class-string<DressCode\Rule>, array<string, mixed>>  $rules
 * @return list<string>  `line: message` each
 */
function upgrade(array $rules, string $code, string $expected): array
{
	$instances = [];
	foreach ($rules as $class => $options) {
		$instances[] = PresetResolver::createRule($class, $options);
	}

	$stubs = __DIR__ . '/fixtures/upgrading-interplay.php';
	$registry = new Analyses\Registry;
	$registry->register(Analyses\Types::class, function (FileNode $file) use ($stubs): Analyses\Types {
		// PHPStan reads the declarations of a file from the disk, so the code goes to a file named by its text
		$dir = sys_get_temp_dir() . '/dresscode-tests/upgrading';
		@mkdir($dir, recursive: true); // @ - the directory may exist
		$text = Printer::print($file);
		$path = $dir . '/' . hash('xxh128', $text) . '.php';
		if (!is_file($path)) {
			file_put_contents($path, $text);
		}

		static $phpstan = [];
		$phpstan[$path] ??= new Analyses\PhpStan(dirname($stubs), [$stubs, $path], "$dir/cache");
		return new Analyses\Types($file, $path, $phpstan[$path]);
	});

	$file = new Parser()->parse($code);
	$runner = new PassRunner($instances, $registry, fn(string $rule) => [$rule], strict: true);
	$result = $runner->run($file, $code, 'upgrading.php', new Style("\t", "\n"), Config::DefaultPhpVersion);
	Assert::same($expected, Printer::print($file));
	return array_map(fn(Violation $v) => "$v->line: $v->message", $result->violations);
}


test('a member nothing can be written instead of keeps the class it is reached through', function () {
	// the ban says the member has no place in the class written instead, so the access and the import that carries
	// it stay as they are, while every other reference of the class is rewritten
	$code = "<?php\n\nnamespace App;\n\nuse Old\\Router;\n\nfunction test(): void\n{\n\t\$a = Router::Secured;\n\t\$b = Router::OneWay;\n}\n";
	$expected = "<?php\n\nnamespace App;\n\nuse Old\\Router;\n\nfunction test(): void\n{\n\t\$a = Router::Secured;\n\t\$b = \\Fresh\\Router::OneWay;\n}\n";
	Assert::same([
		'9: Constant Old\Router::Secured is forbidden: write the protocol into the mask',
		'10: Class Old\Router is replaced by Fresh\Router',
	], upgrade([
		Upgrading\ReplacedClassesRule::class => ['Old\Router' => 'Fresh\Router'],
		Upgrading\ForbiddenMembersRule::class => ['Old\Router::Secured' => 'write the protocol into the mask'],
	], $code, $expected));
});


test('a member written under another name takes the class written instead with it', function () {
	// replaced-members writes the name and replaced-classes the class, so the two together give the whole access
	$code = "<?php\n\nnamespace App;\n\nuse Old\\Legacy;\n\nfunction test(): void\n{\n\t\$a = Legacy::OldName;\n}\n";
	$expected = "<?php\n\nnamespace App;\n\nuse Fresh\\Modern;\n\nfunction test(): void\n{\n\t\$a = Modern::NewName;\n}\n";
	Assert::same([
		'5: Class Old\Legacy is replaced by Fresh\Modern',
		'9: Class Old\Legacy is replaced by Fresh\Modern',
		'9: Constant Old\Legacy::OldName is replaced by Legacy::NewName',
	], upgrade([
		Upgrading\ReplacedClassesRule::class => ['Old\Legacy' => 'Fresh\Modern'],
		Upgrading\ReplacedMembersRule::class => ['Old\Legacy::OldName' => 'NewName'],
	], $code, $expected));
});
