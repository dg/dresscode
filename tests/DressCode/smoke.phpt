<?php declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('tokenizer extension is available', function () {
	Assert::true(extension_loaded('tokenizer'));
});
