<?php declare(strict_types=1);

use DressCode\Rules\CodeWriter;
use PhpSyntax\Nodes\Scalar\IntegerNode;
use PhpSyntax\Nodes\Statement\NamespaceNode;
use PhpSyntax\Parser;
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
