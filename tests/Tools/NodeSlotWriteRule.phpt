<?php declare(strict_types=1);

use Nette\PHPStan\Tester\TypeAssert;

require __DIR__ . '/../bootstrap.php';


TypeAssert::assertErrors(__DIR__ . '/fixtures/node-slot-write.php', [
	'dresscode.nodeSlotWrite on line 14',
	'dresscode.nodeSlotWrite on line 15',
	'dresscode.nodeSlotWrite on line 16',
	'dresscode.nodeSlotWrite on line 17',
], [__DIR__ . '/fixtures/node-slot-write.neon']);

// the same file is fine where direct writes are allowed
TypeAssert::assertNoErrors(__DIR__ . '/fixtures/node-slot-write.php', [__DIR__ . '/fixtures/layer-boundary.neon']);
