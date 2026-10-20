<?php declare(strict_types=1);

use DressCode\Config\{Draft, Loader};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$draft = new Draft()
	->setUse(['psr12'])
	->setDecision('multiline.trailingComma.argument', 'required')
	->setDecision('multiline.trailingComma.array', 'keep')
	->setDecision('indentation.unit', 'tab');


test('as NEON the decisions stand in the order of the catalogue', function () use ($draft) {
	// the line endings Draft writes, whatever the checkout gave the heredoc
	Assert::same(str_replace("\r\n", "\n", <<<'XX'
		use: psr12

		indentation:
			unit: tab

		multiline:
			trailingComma:
				array: keep
				argument: required

		XX), $draft->toNeon());

	$file = createTempDir('draft') . '/dresscode.neon';
	file_put_contents($file, $draft->toNeon());
	Assert::same('keep', Loader::loadFile($file)->decisions['multiline']['trailingComma']['array'] ?? null);
});


test('as PHP it says the same', function () use ($draft) {
	$file = createTempDir('draft') . '/dresscode.php';
	file_put_contents($file, $draft->toPhp());
	$config = Loader::loadFile($file);
	Assert::same(['psr12'], $config->use);
	Assert::same(['unit' => 'tab'], $config->decisions['indentation'] ?? null);
	Assert::same("use: []\n", (new Draft)->toNeon());
});
