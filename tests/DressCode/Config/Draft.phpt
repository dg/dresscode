<?php declare(strict_types=1);

use DressCode\Config\{Draft, Loader};
use DressCode\{Override, Profile};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$draft = new Draft("made to measure\nby hand")
	->setUse(['psr12'], 'the standard')
	->setScope(['src', 'tests'], ['tests/fixtures' => 'test data'], ['php', 'phpt'])
	->setNamespaces(['App\a'], [], 'what the namespaces declare')
	->setTypeAnalysis('PHPStan is installed')
	->setDecision('multiline.trailingComma.argument', 'required', 'your code')
	->setDecision('multiline.trailingComma.array', 'keep', 'not checked')
	->setDecision('indentation.unit', 'tab')
	->addOverride(new Override(['src/drivers'], new Profile(decisions: ['naming' => ['method' => 'keep']])), 'left out');


test('as NEON every key stands with what it rests on, the decisions in the order of the catalogue', function () use ($draft) {
	// the line endings Draft writes, whatever the checkout gave the heredoc
	Assert::same(str_replace("\r\n", "\n", <<<'XX'
		# made to measure
		# by hand
		use: psr12   # the standard

		paths: [src, tests]
		excludePaths:
			- tests/fixtures   # test data
		fileExtensions: [php, phpt]

		# what the namespaces declare
		nameResolution: certain
		namespaces:
			functions:
				- App\a

		typeAnalysis: phpstan   # PHPStan is installed

		indentation:
			unit: tab

		multiline:
			trailingComma:
				array: keep          # not checked
				argument: required   # your code

		overrides:
			# left out
			-
				paths:
					- src/drivers
				naming:
					method: keep

		XX), $draft->toNeon());

	$file = createTempDir('draft') . '/dresscode.neon';
	file_put_contents($file, $draft->toNeon());
	Assert::same('keep', Loader::loadFile($file)->overrides[0]->profile->decisions['naming']['method'] ?? null);
});


test('as PHP and as data it says the same without the comments', function () use ($draft) {
	$file = createTempDir('draft') . '/dresscode.php';
	file_put_contents($file, $draft->toPhp());
	$config = Loader::loadFile($file);
	Assert::same(['psr12'], $config->use);
	Assert::same(['src', 'tests'], $config->paths);
	Assert::same(['src/drivers'], $config->overrides[0]->paths);
	Assert::same(['unit' => 'tab'], $config->decisions['indentation'] ?? null);

	Assert::same(['App\a'], $draft->toArray()['namespaces']['functions'] ?? null);
	Assert::same([['paths' => ['src/drivers'], 'naming' => ['method' => 'keep']]], $draft->toArray()['overrides'] ?? null);
	Assert::same("use: []\n", (new Draft)->toNeon());
});
