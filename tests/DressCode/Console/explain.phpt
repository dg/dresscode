<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Config\{PresetResolver, RuleRegistry};
use DressCode\Console\{Application, ExplainPrinter};
use Nette\CommandLine\{ColorDepth, Console};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$registry = new RuleRegistry;
$resolved = new PresetResolver($registry)->resolve(new Config(presets: ['dresscode/nette']), Config::DefaultPhpVersion);
$root = createTempDir('explain');
file_put_contents("$root/dresscode.neon", "presets: [dresscode/nette]\npaths: [src]\n");


test('every rule can be explained', function () use ($registry, $resolved) {
	$console = new Console;
	$console->setColorDepth(ColorDepth::None);
	foreach (array_keys($registry->getRules()) as $name) {
		$rule = $resolved->getRule($name);
		Assert::type(DressCode\Config\ResolvedRule::class, $rule, $name);
		Assert::contains($name, new ExplainPrinter($rule)->print($console), $name);
	}
});


test('explain writes what the rule is and what it does here', function () use ($root) {
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$code = new Application($out, $err, cwd: $root)->run(['dresscode', 'explain', 'useless-return']);
	rewind($out);
	$text = (string) stream_get_contents($out);
	Assert::same(0, $code);
	Assert::contains('dresscode/useless-return', $text);
	Assert::contains('It runs in this project, set by group cleanup.', $text);
});


test('explain without a rule writes every rule that runs, in Markdown into the output', function () use ($root) {
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$code = new Application($out, $err, cwd: $root)->run(['dresscode', 'explain', '--output', "$root/rules.md"]);
	Assert::same(0, $code);

	$text = (string) file_get_contents("$root/rules.md");
	Assert::contains("# Rules this project enforces\n", $text);
	Assert::contains('- Composed of: `dresscode/psr12`, `dresscode/per`, `dresscode/nette-style`', $text);
	Assert::contains('- Indentation: a tab', $text);
	Assert::contains("### dresscode/useless-return\n", $text);
	// what does not run is not explained
	Assert::notContains('dresscode/line-length', $text);
});


test('explain without a rule and without an output prints every rule that runs', function () use ($root) {
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$code = new Application($out, $err, cwd: $root)->run(['dresscode', 'explain']);
	rewind($out);
	$text = (string) stream_get_contents($out);
	Assert::same(0, $code);
	Assert::contains('dresscode/useless-return', $text);
	Assert::contains('It runs in this project, set by group cleanup.', $text);
	Assert::notContains('It does not run in this project', $text);
});


test('explain of a name no rule owns', function () {
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$code = new Application($out, $err, cwd: __DIR__)->run(['dresscode', 'explain', 'useless-retrn']);
	rewind($err);
	Assert::same(2, $code);
	Assert::contains('Unknown rule `useless-retrn`. Did you mean `useless-return`?', (string) stream_get_contents($err));
});
