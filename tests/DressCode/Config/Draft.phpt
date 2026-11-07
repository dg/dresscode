<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Config\{Draft, Loader, RunnerFactory};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$draft = new Draft()
	->setUse(['psr12'])
	->setScope(['src', 'tests'], ['tests/fixtures' => 'test data'], ['php', 'phpt'])
	->setDecision('multiline.trailingComma.argument', 'required', 'your code')
	->setDecision('multiline.trailingComma.array', 'keep', 'not checked')
	->setDecision('indentation.unit', 'tab');


test('as NEON every key stands with what it rests on, the decisions in the order of the catalogue', function () use ($draft) {
	// the line endings Draft writes, whatever the checkout gave the heredoc
	Assert::same(str_replace("\r\n", "\n", <<<'XX'
		use: psr12

		paths: [src, tests]
		excludePaths:
			- tests/fixtures   # test data
		fileExtensions: [php, phpt]

		indentation:
			unit: tab

		multiline:
			trailingComma:
				array: keep          # not checked
				argument: required   # your code

		XX), $draft->toNeon());
});


test('as PHP it says the same without the comments', function () use ($draft) {
	$file = createTempDir('draft') . '/dresscode.php';
	file_put_contents($file, $draft->toPhp());
	$config = Loader::loadFile($file);
	Assert::same(['psr12'], $config->use);
	Assert::same(['src', 'tests'], $config->paths);
	Assert::same(['unit' => 'tab'], $config->decisions['indentation'] ?? null);
	Assert::same("use: []\n", (new Draft)->toNeon());
});


test('what a run makes of the configuration written is checked against the draft', function () {
	$root = createTempDir('draft-check');
	$draft = new Draft()->setUse(['psr12'])->setDecision('indentation.unit', 'tab');
	Assert::noError(fn() => $draft->renderChecked($root));
	Assert::noError(fn() => $draft->renderChecked($root, 'php'));

	// a path NEON would read otherwise is quoted, and the scope it loads as is checked too
	$paths = ['src/a,b.php', 'a #b', '[x]', "it's", 'a b', 'yes', '123'];
	$draft = new Draft()
		->setUse(['psr12'])
		->setScope($paths, ['src/a,c.php' => 'generated'], ['php', 'null']);
	Assert::noError(fn() => $draft->renderChecked($root));
	$file = "$root/dresscode.neon";
	file_put_contents($file, $draft->toNeon());
	$config = Loader::loadFile($file);
	Assert::same($paths, $config->paths);
	Assert::same(['php', 'null'], $config->fileExtensions);
	Assert::contains('src/a,c.php', $config->excludePaths);

	$resolved = new RunnerFactory()->resolve(new Config(use: ['psr12']), $root)->resolvedConfig;
	$draft = new Draft()->setUse(['psr12'])->setDecision('indentation.unit', 'tab');
	Assert::exception(
		fn() => $draft->checkResolution($resolved),
		LogicException::class,
		'The configuration written does not say what it was made to: `indentation.unit` resolves to another value.',
	);
});
