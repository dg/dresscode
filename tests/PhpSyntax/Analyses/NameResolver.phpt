<?php declare(strict_types=1);

use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ClassConstantFetchNode;
use PhpSyntax\Nodes\Expression\ConstantFetchNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\Expression\NewNode;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Parser\Parser;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$code = <<<'XX'
	<?php
	namespace App\Model;

	use Foo\Bar;
	use Foo\Baz as Qux, Other\Thing;
	use function Foo\helper, Foo\other as alias;
	use const Foo\LIMIT;
	use Lib\{Alpha, Beta as Gamma, function fn1, const C1};

	new Bar; new Qux; new Thing\Sub; new Bar\Nested; new Local; new \Global\Cls; new namespace\Rel; new Alpha; new Gamma; new self; new static;
	helper(); alias(); fn1(); strlen(); local(); \strlen(); Foo\f(); Bar\f(); namespace\g();
	LIMIT; C1; PHP_EOL; LOCAL; \E_ALL; Bar\X; Qux::CONST;
	function local() {}

	namespace {
		use Foo\Bar;
		new Bar; new Baz; strlen(); other();
	}
	XX;

$file = (new Parser)->parse($code);
$resolver = new NameResolver($file);


/** @return list<string> */
function classes(FileNode $file, NameResolver $resolver): array
{
	return array_map(
		fn(NewNode $new) => $new->class instanceof NameNode ? $resolver->resolveClass($new->class) : '?',
		$file->getDescendants(NewNode::class),
	);
}


test('namespaces', function () use ($file, $resolver) {
	[$model, $global] = $file->stmts->getItems();
	Assert::same('App\Model', $resolver->getNamespace($model));
	Assert::same('App\Model', $resolver->getNamespace($file->getDescendants(NewNode::class)[0]));
	Assert::same('', $resolver->getNamespace($global));
	Assert::same('', $resolver->getNamespace($file->getDescendants(NewNode::class)[11]));
});


test('classes: imports, aliases, group use, qualified prefixes, special names', function () use ($file, $resolver) {
	Assert::same([
		'Foo\Bar', 'Foo\Baz', 'Other\Thing\Sub', 'Foo\Bar\Nested', 'App\Model\Local', 'Global\Cls', 'App\Model\Rel', 'Lib\Alpha', 'Lib\Beta', 'self', 'static',
		'Foo\Bar', 'Baz',
	], classes($file, $resolver));
});


test('functions: imports, fallback to global, declared in the namespace', function () use ($file, $resolver) {
	$calls = $file->getDescendants(FunctionCallNode::class);
	$resolved = array_map(fn(FunctionCallNode $call) => $call->name instanceof NameNode ? $resolver->resolveFunction($call->name) : '?', $calls);
	Assert::same([
		'Foo\helper', 'Foo\other', 'Lib\fn1', 'strlen', 'App\Model\local', 'strlen', 'App\Model\Foo\f', 'Foo\Bar\f', 'App\Model\g',
		'strlen', 'other',
	], $resolved);

	Assert::same([false, false, false, true, false, true, false, false, false, true, true], array_map(fn(Node $call) => $resolver->isGlobalFunctionCall($call), $calls));
	Assert::true($resolver->isGlobalFunctionCall($calls[3], 'STRLEN'));
	Assert::false($resolver->isGlobalFunctionCall($calls[3], 'strtolower'));
	Assert::false($resolver->isGlobalFunctionCall($file->stmts, 'strlen'));
});


test('constants: case-sensitive imports and fallback', function () use ($file, $resolver) {
	$constants = array_map(
		fn(ConstantFetchNode $fetch) => $resolver->resolveConstant($fetch->name),
		$file->getDescendants(ConstantFetchNode::class),
	);
	Assert::same(['Foo\LIMIT', 'Lib\C1', 'PHP_EOL', 'LOCAL', 'E_ALL', 'Foo\Bar\X'], $constants);
	$fetch = $file->getDescendants(ClassConstantFetchNode::class)[0];
	Assert::type(NameNode::class, $fetch->class);
	Assert::same('Foo\Baz', $resolver->resolveClass($fetch->class));
});
