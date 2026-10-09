<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Config\{ConfigResolver, PluginRegistry};
use DressCode\Console\{Application, ExplainPrinter, Markup};
use Nette\CommandLine\{ColorDepth, Console};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$registry = new PluginRegistry;
$resolved = new ConfigResolver($registry)->resolve(new Config(use: ['dresscode/nette']), Config::DefaultPhpVersion);
$root = createTempDir('explain');
file_put_contents("$root/dresscode.neon", "use: [dresscode/nette]\npaths: [src]\n");


/**
 * @param  list<string>  $args
 * @return array{int, string, string}  the exit code, the output and the errors
 */
function explainApp(string $root, array $args): array
{
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$code = new Application($out, $err, cwd: $root)->run(['dresscode', 'explain', ...$args]);
	rewind($out);
	rewind($err);
	return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
}


test('every decision can be explained', function () use ($registry, $resolved) {
	$console = new Console;
	$console->setColorDepth(ColorDepth::None);
	$printer = new ExplainPrinter($registry);
	foreach ($resolved->decisions as $path => $decision) {
		Assert::contains("`$path`", Markup::renderMarkdown($console, $printer->printDecision($decision)), $path);
	}
});


test('a decision in detail is Markdown, and the console draws it', function () use ($registry, $resolved) {
	$markdown = new ExplainPrinter($registry, ['nette' => $resolved->decisions])->printDecision($resolved->decisions['qualification.otherNamespace.class']);
	Assert::match(<<<'XX'
		## `qualification.otherNamespace.class`

		A class, interface, trait or enum of a namespace other than that of the file.

		A name relative to an import or to the namespace, `Shop\Order`, stays as it is.

		A requirement, which turns its rule on where it is not `keep`.

		Takes: `imported` (%A%; `keep`

		Here `imported`, set by dresscode/nette.

		The standards: nette `imported`.

		Rule `DressCode\Rules\Namespaces\ForeignNameQualificationRule`, stage Structure.

		See <https://dresscode.run/decisions/qualification.otherNamespace.class>

		XX, $markdown);

	$console = new Console;
	$console->setColorDepth(ColorDepth::None);
	Assert::match(<<<'XX'
		`qualification.otherNamespace.class`

		%A%

		Here `imported`, set by dresscode/nette.

		%A%

		See https://dresscode.run/decisions/qualification.otherNamespace.class

		XX, Markup::renderMarkdown($console, $markdown));
});


test('a pipe is written as it is, a line opening a list or a heading outside a code span is escaped', function () use ($registry, $resolved) {
	$markdown = new ExplainPrinter($registry)->printDecision($resolved->decisions['expressions.wordLogicalOperators']);
	Assert::contains('`&&`, `||`', $markdown);
	Assert::notContains('\|', $markdown);
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


test('explain of a decision writes what it is, its value here and those of the standards', function () use ($root) {
	[$code, $text] = explainApp($root, ['spacing.call']);
	Assert::same(0, $code);
	Assert::contains('`spacing.call`', $text);
	Assert::contains('Here `compact`, set by dresscode/psr12.', $text);
	Assert::contains('The standards: perCs `compact`, psr12 `compact`, nette `compact`, symfony `compact`.', $text);
	Assert::contains('See https://dresscode.run/decisions/spacing.call', $text);
});


test('explain of a section writes the decisions under it', function () use ($root) {
	[$code, $text] = explainApp($root, ['controlFlow', '--format', 'markdown']);
	Assert::same(0, $code);
	Assert::contains("## `controlFlow`\n", $text);
	Assert::contains('- `controlFlow.elseif`: `oneWord` _(dresscode/psr12)_', $text);
	Assert::notContains('# Decisions of this project', $text);
});


test('explain without a decision writes every decision a layer makes, in Markdown into the output', function () use ($root) {
	[$code, $text] = explainApp($root, ['--format', 'markdown']);
	Assert::same(0, $code);
	Assert::contains("# Decisions of this project\n", $text);
	Assert::contains('- Composed of: `dresscode/psr12`, `dresscode/perCs`, `dresscode/cleanup`, `dresscode/types`, `dresscode/correctness`, `dresscode/nette`', $text);
	Assert::contains('- Indentation: a tab', $text);
	Assert::contains("## controlFlow\n", $text);
	Assert::contains('- `controlFlow.elseif`: `oneWord` _(dresscode/psr12)_', $text);
	// what no layer decides is not explained
	Assert::notContains('controlFlow.trailingIf`', $text);
});


test('explain without a decision draws the document that the markdown format writes', function () use ($root) {
	[$code, $text] = explainApp($root, []);
	Assert::same(0, $code);
	Assert::contains('Decisions of this project', $text);
	Assert::contains('Composed of:', $text);
	Assert::contains('imports.unused', $text);
});


test('explain of a path no decision has', function () use ($root) {
	[$code, , $err] = explainApp($root, ['spacing.cal']);
	Assert::same(3, $code);
	Assert::contains('Unknown decision `spacing.cal`. Did you mean `spacing.call`?', $err);
});
