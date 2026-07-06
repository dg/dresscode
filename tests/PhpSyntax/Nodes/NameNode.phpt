<?php declare(strict_types=1);

use PhpSyntax\NameKind;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Parser\Parser;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


function name(string $code): NameNode
{
	return (new Parser)->parseName($code);
}


test('kinds and parts', function () {
	$name = name('Foo');
	Assert::same(NameKind::Unqualified, $name->getKind());
	Assert::same(['Foo'], $name->getParts());
	Assert::same('Foo', $name->getName());

	$name = name('Foo\Bar');
	Assert::same(NameKind::Qualified, $name->getKind());
	Assert::same(['Foo', 'Bar'], $name->getParts());

	$name = name('\Foo\Bar');
	Assert::same(NameKind::FullyQualified, $name->getKind());
	Assert::same(['Foo', 'Bar'], $name->getParts());
	Assert::same('\Foo\Bar', $name->getName());

	$name = name('namespace\Bar');
	Assert::same(NameKind::Relative, $name->getKind());
	Assert::same(['Bar'], $name->getParts());
});


test('keywords accepted as names', function () {
	Assert::true(name('static')->isKeyword());
	Assert::false(name('Foo')->isKeyword());
	Assert::false(name('\Foo')->isKeyword());
	$file = (new Parser)->parse('<?php function f(array $a, callable $c) {} exit(); readonly();');
	$names = $file->getDescendants(NameNode::class);
	Assert::same(['array', 'callable', 'readonly'], array_map(fn(NameNode $n) => $n->getName(), array_values(array_filter($names, fn(NameNode $n) => $n->isKeyword()))));
});
