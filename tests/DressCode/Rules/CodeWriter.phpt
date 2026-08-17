<?php declare(strict_types=1);

use DressCode\Analyses\Registry;
use DressCode\Engine\{Fingerprints, Suppression};
use DressCode\{RuleContext, Style};
use DressCode\Rules\CodeWriter;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\Scalar\IntegerNode;
use PhpSyntax\Nodes\Statement\NamespaceNode;
use PhpSyntax\{Parser, SymbolKind};
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
		new Fingerprints([]),
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
