<?php declare(strict_types=1);

/**
 * Every example of a rule is a fixture of that rule, so the suite runs it against the rule itself and an
 * example that stopped being true cannot survive; this asks the other half, that explain renders every
 * rule and that no example belongs to nothing.
 */

use DressCode\Config;
use DressCode\Config\PresetResolver;
use DressCode\Config\RuleRegistry;
use DressCode\Console\Application;
use DressCode\Console\ExplainPrinter;
use DressCode\PresetContext;
use Nette\CommandLine\Console;
use Tester\Assert;


require __DIR__ . '/../../bootstrap.php';


$fixtures = __DIR__ . '/../Rules/fixtures';
$registry = new RuleRegistry;
$resolved = new PresetResolver($registry)->resolveConfig(
	Config::create()->preset('dresscode/nette'),
	new PresetContext(Config::DefaultPhpVersion),
);


test('every example belongs to a rule and every rule can be explained', function () use ($fixtures, $registry, $resolved) {
	$console = new Console;
	$console->useColors(false);
	$examples = 0;
	foreach ($registry->getRules() as $name => $class) {
		$rule = $resolved->getRule($name);
		Assert::type(DressCode\Config\ResolvedRule::class, $rule, $name);
		$printer = new ExplainPrinter($rule, $fixtures);
		Assert::contains($name, $printer->print($console), $name);
		foreach ($printer->findExamples() as [$before, $after, $options]) {
			$examples++;
			Assert::contains('<?php', $before, $name);
		}
	}

	Assert::true($examples > 0);

	// a showcase in a directory no rule owns would never be run against anything
	$slugs = [];
	foreach (array_keys($registry->getRules()) as $name) {
		$slugs[substr($name, strpos($name, '/') + 1)] = true;
	}

	foreach (glob("$fixtures/*/showcase*.code") ?: [] as $file) {
		Assert::true(isset($slugs[basename(dirname($file))]), $file);
	}
});


test('explain writes what the rule is, what it does here, and its example', function () {
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$root = __DIR__ . '/../../temp/explain';
	@mkdir($root, recursive: true); // @ - may exist
	file_put_contents("$root/dresscode.neon", "presets: [dresscode/nette]\npaths: [src]\n");
	$code = new Application($out, $err, cwd: $root)->run(['dresscode', 'explain', 'useless-return']);
	rewind($out);
	$text = (string) stream_get_contents($out);
	Assert::same(0, $code);
	Assert::contains('dresscode/useless-return', $text);
	Assert::contains('It runs in this project, set by dresscode/nette.', $text);
	Assert::contains("\techo \$message;\n  \treturn;", $text);
	Assert::contains('becomes', $text);
});


test('standard writes what is enforced, and says that it is not the whole standard', function () {
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$root = __DIR__ . '/../../temp/explain';
	@mkdir($root, recursive: true); // @ - may exist
	file_put_contents("$root/dresscode.neon", "presets: [dresscode/nette]\npaths: [src]\n");
	$code = new Application($out, $err, cwd: $root)->run(['dresscode', 'standard', '--output', "$root/standard.md"]);
	Assert::same(0, $code);

	$text = (string) file_get_contents("$root/standard.md");
	Assert::contains('which is a part of a', $text);
	Assert::contains('- Composed of: `dresscode/psr12`, `dresscode/per`, `dresscode/nette`', $text);
	Assert::contains('- Indentation: a tab', $text);
	Assert::contains("### dresscode/useless-return\n", $text);
	Assert::contains("```php\nfunction announce(string \$message): void", $text);
	Assert::contains("becomes\n", $text);
	// what does not run is not part of the standard it writes
	Assert::notContains('dresscode/line-length', $text);
});


test('explain of a name no rule owns', function () {
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$code = new Application($out, $err, cwd: __DIR__)->run(['dresscode', 'explain', 'useless-retrn']);
	rewind($err);
	Assert::same(2, $code);
	Assert::contains("Unknown rule 'useless-retrn'. Did you mean 'useless-return'?", (string) stream_get_contents($err));
});
