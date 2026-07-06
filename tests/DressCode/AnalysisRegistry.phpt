<?php declare(strict_types=1);

use DressCode\AnalysisRegistry;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Analyses\Scope;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Parser\Parser;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('analyses are created lazily, cached per file and dropped after a mutation', function () {
	$registry = new AnalysisRegistry;
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
	Assert::same('A', $resolver->getNamespace($file->stmts->getItems()[0]));

	$scope = $registry->get($file, Scope::class);
	Assert::same($scope, $registry->get($file, Scope::class));
	Assert::same(1, $created[0]);

	$file->stmts->getItems()[0]->remove();
	Assert::notSame($resolver, $registry->get($file, NameResolver::class));
	Assert::notSame($scope, $registry->get($file, Scope::class));
	Assert::same(2, $created[0]);

	Assert::exception(fn() => $registry->get($file, PhpSyntax\Node::class), InvalidArgumentException::class, 'Analysis PhpSyntax\Node is not registered.');
});
