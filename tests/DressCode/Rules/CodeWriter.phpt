<?php declare(strict_types=1);

use DressCode\Analyses\Registry;
use DressCode\Engine\{Fingerprints, Suppression};
use DressCode\{RuleContext, Style};
use DressCode\Rules\CodeWriter;
use PhpSyntax\{Node, Parser, SymbolKind};
use PhpSyntax\Nodes\{AttributeAwareNode, FileNode, ParameterNode};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Scalar\IntegerNode;
use PhpSyntax\Nodes\Statement\NamespaceNode;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('findImportScope()', function () {
	$file = (new Parser)->parse("<?php\nfunction f() { return 1; }\n");
	[$number] = $file->find(IntegerNode::class);
	Assert::same($file, CodeWriter::findImportScope($number));

	$file = (new Parser)->parse("<?php\nnamespace A;\nreturn 1;\n");
	[$namespace] = $file->find(NamespaceNode::class);
	[$number] = $file->find(IntegerNode::class);
	Assert::same($namespace, CodeWriter::findImportScope($number));
	Assert::null(CodeWriter::findImportScope($file)); // a file with namespaces imports into them
});


function createWriterContext(FileNode $file): RuleContext
{
	return new RuleContext(
		$file,
		'a.php',
		new Style,
		'8.4',
		new Registry,
		Suppression::fromFile($file, fn() => []),
		new Fingerprints([], 'a.php'),
	);
}


/** The file with `New\B` imported into the scope of its last statement. */
function addImport(string $code): string
{
	$file = (new Parser)->parse($code);
	$items = $file->statements->getItems();
	$scope = CodeWriter::findImportScope($items[count($items) - 1]);
	Assert::true($scope !== null && CodeWriter::canAddImport($scope));
	CodeWriter::addImport($scope, SymbolKind::ClassLike, 'New\B', createWriterContext($file));
	return (string) $file;
}


/**
 * The file with `#[B]` and `#[C]` added to the first declaration of the class.
 * @param  class-string<AttributeAwareNode&Node>  $class
 */
function addAttributes(string $code, string $class): string
{
	$file = (new Parser)->parse("<?php\n$code");
	$declaration = $file->findFirst($class);
	Assert::true($declaration instanceof AttributeAwareNode);
	CodeWriter::addAttributes($declaration, ['B', 'C'], createWriterContext($file));
	return substr((string) $file, 6);
}


test('addImport(): the first import stands under the open tag', function () {
	Assert::same("<?php\n\nuse New\\B;\n\n\$x = 1;\n", addImport("<?php\n\$x = 1;\n"));
	Assert::same(
		"<?php\ndeclare(strict_types=1);\n\nuse New\\B;\n\n\$x = 1;\n",
		addImport("<?php\ndeclare(strict_types=1);\n\n\$x = 1;\n"),
	);
});


test('addImport(): a hashbang and a byte order mark stay before the open tag', function () {
	Assert::same(
		"#!/usr/bin/env php\n<?php\n\nuse New\\B;\n\n\$x = 1;\n",
		addImport("#!/usr/bin/env php\n<?php\n\$x = 1;\n"),
	);
	Assert::same(
		"#!/usr/bin/env php\n<?php declare(strict_types=1);\n\nuse New\\B;\n\n\$x = 1;\n",
		addImport("#!/usr/bin/env php\n<?php declare(strict_types=1);\n\n\$x = 1;\n"),
	);
	Assert::same("\u{FEFF}<?php\n\nuse New\\B;\n\n\$x = 1;\n", addImport("\u{FEFF}<?php\n\$x = 1;\n"));
});


test('addImport(): the header comment of a file stays above the import, the doc comment of a statement with it', function () {
	Assert::same(
		"<?php\n\n/**\n * Header.\n */\n\nuse New\\B;\n\n\$x = 1;\n",
		addImport("<?php\n\n/**\n * Header.\n */\n\n\$x = 1;\n"),
	);
	Assert::same(
		"<?php declare(strict_types=1);\n\n/** Header. */\n\nuse New\\B;\n\n\$x = 1;\n",
		addImport("<?php declare(strict_types=1);\n\n/** Header. */\n\n\$x = 1;\n"),
	);
	Assert::same(
		"<?php\n\nuse New\\B;\n\n/** @var int */\n\$x = 1;\n",
		addImport("<?php\n\n/** @var int */\n\$x = 1;\n"),
	);
});


test('addImport(): markup before the open tag leaves no line for the first import', function () {
	$file = (new Parser)->parse("<html>\n<?php\n\$x = 1;\n");
	Assert::false(CodeWriter::canAddImport($file));
});


test('addAttributes(): a declaration starting its line gets them on lines of their own', function () {
	Assert::same(
		"class X\n{\n\t/** Doc. */\n\t#[B]\n\t#[C]\n\tpublic function m() {}\n}\n",
		addAttributes("class X\n{\n\t/** Doc. */\n\tpublic function m() {}\n}\n", MethodNode::class),
	);
	Assert::same(
		"class X\n{\n\t#[A]\n\t#[B]\n\t#[C]\n\tpublic function m() {}\n}\n",
		addAttributes("class X\n{\n\t#[A]\n\tpublic function m() {}\n}\n", MethodNode::class),
	);
});


test('addAttributes(): a declaration standing behind other code or its attributes gets them on its line', function () {
	Assert::same(
		"class X\n{\n\t#[A] #[B] #[C] public function m() {}\n}\n",
		addAttributes("class X\n{\n\t#[A] public function m() {}\n}\n", MethodNode::class),
	);
	Assert::same(
		"class X { #[B] #[C] public function m() {} }\n",
		addAttributes("class X { public function m() {} }\n", MethodNode::class),
	);
	Assert::same(
		"function f(#[B] #[C] \$a) {}\n",
		addAttributes("function f(\$a) {}\n", ParameterNode::class),
	);
});
