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


test('role by the place in the tree', function () {
	$file = (new Parser)->parse('<?php namespace A; use B\C; use function D; f(E); new F; G::h(); function i(J $j): K {} $l instanceof M; #[N] class O extends P implements Q {} try {} catch (R $e) {}');
	$roles = [];
	foreach ($file->getDescendants(NameNode::class) as $name) {
		$roles[$name->getName()] = $name->getRole()->name;
	}

	Assert::same([
		'A' => 'Namespace',
		'B\C' => 'Namespace',
		'D' => 'Namespace',
		'f' => 'Function',
		'E' => 'Constant',
		'F' => 'ClassLike',
		'G' => 'ClassLike',
		'J' => 'ClassLike',
		'K' => 'ClassLike',
		'M' => 'ClassLike',
		'N' => 'ClassLike',
		'P' => 'ClassLike',
		'Q' => 'ClassLike',
		'R' => 'ClassLike',
	], $roles);
});
