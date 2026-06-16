<?php declare(strict_types=1);

use DressCode\Analyses;
use PhpSyntax\Analyses\{NameResolver, NamespacedSymbols, Scope};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\{Parser, SymbolKind, UnqualifiedResolution};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('analyses are created lazily, cached per file and dropped after a mutation', function () {
	$registry = new Analyses\Registry;
	$registry->register(NameResolver::class);
	$created = new ArrayObject([0]);
	$registry->register(Scope::class, function (FileNode $file) use ($created) {
		$created[0]++;
		return new Scope;
	});

	$file = (new Parser)->parse('<?php namespace A; f();');
	$other = (new Parser)->parse('<?php g();');
	$resolver = $registry->get($file, NameResolver::class);
	Assert::same($resolver, $registry->get($file, NameResolver::class));
	Assert::notSame($resolver, $registry->get($other, NameResolver::class));
	Assert::same('A', $resolver->getNamespace($file->statements->getItems()[0]));

	$scope = $registry->get($file, Scope::class);
	Assert::same($scope, $registry->get($file, Scope::class));
	Assert::same(1, $created[0]);

	$file->statements->getItems()[0]->remove();
	Assert::notSame($resolver, $registry->get($file, NameResolver::class));
	Assert::notSame($scope, $registry->get($file, Scope::class));
	Assert::same(2, $created[0]);

	Assert::exception(
		fn() => $registry->get($file, PhpSyntax\Node::class),
		LogicException::class,
		'Analysis `PhpSyntax\Node` is not registered and cannot be built from the file.',
	);
	Assert::exception(fn() => $registry->get($file, ArrayObject::class), LogicException::class);
	Assert::exception(fn() => $registry->get($file, DateTimeZone::class), LogicException::class);
	Assert::null($registry->find($file, DateTimeZone::class));
});


final class FileAnalysisStub
{
	public function __construct(
		public FileNode $file,
	) {
	}
}


final class NodeAnalysisStub
{
	public function __construct(
		public PhpSyntax\Node $node,
	) {
	}
}


test('an analysis is built from the file when its constructor takes the file or a type the file is', function () {
	$registry = new Analyses\Registry;
	$file = (new Parser)->parse('<?php f();');
	Assert::true(Analyses\Registry::isConstructible(FileAnalysisStub::class));
	Assert::true(Analyses\Registry::isConstructible(NodeAnalysisStub::class));
	Assert::same($file, $registry->get($file, FileAnalysisStub::class)->file);
	Assert::same($file, $registry->find($file, NodeAnalysisStub::class)?->node);
});


test('the resolver of names is built with what the namespaces declare outside the file', function () {
	$symbols = new NamespacedSymbols(['A\f'], complete: true);
	$registry = new Analyses\Registry($symbols);
	$file = (new Parser)->parse('<?php namespace A; f(); g();');
	[$f, $g] = $file->find(FunctionCallNode::class);
	$resolver = $registry->get($file, NameResolver::class);
	Assert::false($resolver->isGlobalFunctionCall($f));
	Assert::true($resolver->isGlobalFunctionCall($g));
	Assert::same(UnqualifiedResolution::Global, $resolver->getUnqualifiedResolution('g', SymbolKind::Function, $g));
	Assert::same($symbols, $registry->get($file, NamespacedSymbols::class));

	$unknown = (new Analyses\Registry)->get($file, NameResolver::class);
	Assert::same(UnqualifiedResolution::Uncertain, $unknown->getUnqualifiedResolution('g', SymbolKind::Function, $g));
});


final class StringAnalysisStub
{
	public function __construct(
		public readonly string $value,
	) {
	}
}


final class OptionalStringAnalysisStub
{
	public function __construct(
		public readonly string $value = '',
	) {
	}
}


test('a factory that throws is not taken for an analysis the run does not have', function () {
	$registry = new Analyses\Registry;
	$registry->register(OptionalStringAnalysisStub::class, fn() => throw new RuntimeException('Broken factory.'));
	$file = (new Parser)->parse('<?php f();');
	Assert::exception(fn() => $registry->find($file, OptionalStringAnalysisStub::class), RuntimeException::class, 'Broken factory.');
});
