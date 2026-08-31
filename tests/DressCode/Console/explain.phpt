<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Config\{ConfigResolver, RuleRegistry};
use DressCode\Console\{Application, ExplainPrinter, Markup};
use Nette\CommandLine\{ColorDepth, Console};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$registry = new RuleRegistry;
$resolved = new ConfigResolver($registry)->resolve(new Config(presets: ['dresscode/nette']), Config::DefaultPhpVersion);
$root = createTempDir('explain');
file_put_contents("$root/dresscode.neon", "presets: [dresscode/nette]\npaths: [src]\n");


test('every rule can be explained', function () use ($registry, $resolved) {
	$console = new Console;
	$console->setColorDepth(ColorDepth::None);
	$printer = new ExplainPrinter($registry);
	foreach (array_keys($registry->rules) as $name) {
		$rule = $resolved->getRule($name);
		Assert::type(DressCode\Config\ResolvedRule::class, $rule, $name);
		Assert::contains($name, Markup::renderMarkdown($console, $printer->printRule($rule)), $name);
	}
});


test('a rule in detail is Markdown, and the console draws it', function () use ($registry, $resolved) {
	$markdown = new ExplainPrinter($registry)->printRule($resolved->getRule('dresscode/stringQuotes') ?? throw new LogicException);
	Assert::match(<<<'XX'
		## dresscode/stringQuotes

		%A%.

		_stage %a%_

		_It runs in this project, set by %a%._

		### Options

		- `quotes`: `single` _(%a%)_
		%A%
		See <https://dresscode.run/rules/stringQuotes>

		XX, $markdown);

	$console = new Console;
	$console->setColorDepth(ColorDepth::None);
	Assert::match(<<<'XX'
		dresscode/stringQuotes

		%A%.

		stage %a%

		It runs in this project, set by %a%.

		Options

		  `quotes`: `single` (%a%)
		%A%
		See https://dresscode.run/rules/stringQuotes

		XX, Markup::renderMarkdown($console, $markdown));
});


test('a pipe is written as it is, a line opening a list or a heading outside a code span is escaped', function () use ($registry, $resolved) {
	$markdown = new ExplainPrinter($registry)->printRule($resolved->getRule('dresscode/symbolicLogicalOperators') ?? throw new LogicException);
	Assert::contains('`&&` and `||`', $markdown);
	Assert::notContains('\|', $markdown);

	$markdown = new ExplainPrinter($registry)->printRule($resolved->getRule('dresscode/stringQuotes') ?? throw new LogicException);
	Assert::notContains("\n\n\n", $markdown);

	$escape = new ReflectionMethod(ExplainPrinter::class, 'escape')->invoke(...);
	Assert::same("\\- a `- b | c`\n\\- d `#`\n\\# e", $escape(null, "- a `- b | c`\n- d `#`\n# e"));
});


test('what Markdown would read as a list or a heading is escaped, and the console draws it as written', function () {
	$console = new Console;
	$console->setColorDepth(ColorDepth::None);
	Assert::same("a | b\n- c\n# d\n", Markup::renderMarkdown($console, "a | b\n\\- c\n\\# d"));
	Assert::same("  `x` y (default)\n      text\n", Markup::renderMarkdown($console, "- `x` y _(default)_\n  text"));
	Assert::same("name (https://acme.dev)\n", Markup::renderMarkdown($console, '### [name](https://acme.dev)'));
});


test('explain writes what the rule is and what it does here', function () use ($root) {
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$code = new Application($out, $err, cwd: $root)->run(['dresscode', 'explain', 'uselessReturn']);
	rewind($out);
	$text = (string) stream_get_contents($out);
	Assert::same(0, $code);
	Assert::contains('dresscode/uselessReturn', $text);
	Assert::contains('It runs in this project, set by group cleanup.', $text);
	Assert::contains('See https://dresscode.run/rules/uselessReturn', $text);
});


test('explain in the markdown format of a rule writes the rule in detail', function () use ($root) {
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$code = new Application($out, $err, cwd: $root)->run(['dresscode', 'explain', 'uselessReturn', '--format', 'markdown']);
	rewind($out);
	$text = (string) stream_get_contents($out);
	Assert::same(0, $code);
	Assert::contains("## dresscode/uselessReturn\n", $text);
	Assert::notContains('# Rules this project enforces', $text);
});


test('explain without a rule writes every rule that runs, in Markdown into the output', function () use ($root) {
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$code = new Application($out, $err, cwd: $root)->run(['dresscode', 'explain', '--format', 'markdown']);
	rewind($out);
	$text = (string) stream_get_contents($out);
	Assert::same(0, $code);

	Assert::contains("# Rules this project enforces\n", $text);
	Assert::contains('- Composed of: `dresscode/psr12`, `dresscode/perCs`, `dresscode/nette`', $text);
	Assert::contains('- Indentation: a tab', $text);
	Assert::contains("### [dresscode/uselessReturn](https://dresscode.run/rules/uselessReturn)\n", $text);
	// what does not run is not explained
	Assert::notContains('dresscode/lineLength', $text);
});


test('explain without a rule draws the document that the markdown format writes', function () use ($root) {
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$code = new Application($out, $err, cwd: $root)->run(['dresscode', 'explain']);
	rewind($out);
	$text = (string) stream_get_contents($out);
	Assert::same(0, $code);
	Assert::contains('Rules this project enforces', $text);
	Assert::contains('Composed of:', $text);
	Assert::contains('dresscode/uselessReturn', $text);
	Assert::notContains('It runs in this project', $text);
	Assert::notContains('It does not run in this project', $text);
});


test('explain of a name no rule owns', function () {
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$code = new Application($out, $err, cwd: __DIR__)->run(['dresscode', 'explain', 'useless-retrn']);
	rewind($err);
	Assert::same(3, $code);
	Assert::contains('Unknown rule `useless-retrn`. Did you mean `uselessReturn`?', (string) stream_get_contents($err));
});
