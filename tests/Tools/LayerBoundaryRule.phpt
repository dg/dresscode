<?php declare(strict_types=1);

use Nette\PHPStan\Tester\TypeAssert;

require __DIR__ . '/../bootstrap.php';


$config = [__DIR__ . '/fixtures/layer-boundary.neon'];

TypeAssert::assertErrors(__DIR__ . '/fixtures/layer-boundary-violations.php', [
	'dresscode.layerBoundary on line 5',
	'dresscode.layerBoundary on line 6',
	'dresscode.layerBoundary on line 7',
	'dresscode.layerBoundary on line 8',
	'dresscode.layerBoundary on line 13',
	'dresscode.layerBoundary on line 16',
	'dresscode.layerBoundary on line 17',
	'dresscode.layerBoundary on line 18',
], $config);

TypeAssert::assertNoErrors(__DIR__ . '/fixtures/layer-boundary-clean.php', $config);
TypeAssert::assertNoErrors(__DIR__ . '/fixtures/layer-boundary-outside.php', $config);
