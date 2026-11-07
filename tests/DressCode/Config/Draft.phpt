<?php declare(strict_types=1);

use DressCode\{Config, Override, Profile};
use DressCode\Config\{Draft, Loader, RunnerFactory};
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


test('the names of one namespace are written as group uses filling a line, a name alone in full', function () {
	$long = 'a_function_whose_name_is_longer_than_a_line_of_the_configuration_may_be_' . str_repeat('x', 40);
	$functions = [
		'Legacy\helper',
		...array_map(fn(int $i) => sprintf('Adminer\name%02d', $i), range(1, 12)),
		'App\Utils\format', 'App\Utils\parse',
		'App\Foo\x', 'app\foo\y', 'App\Foo\z',
		'Long\short', "Long\\$long",
	];
	$draft = new Draft()->setUse(['psr12'])->setNamespaces($functions, ['App\BOOTED', 'App\LIMIT']);
	Assert::same(str_replace("\r\n", "\n", <<<XX
		use: psr12

		nameResolution: certain
		namespaces:
			functions:
				- 'Adminer\\{name01, name02, name03, name04, name05, name06, name07, name08, name09, name10, name11}'
				- Adminer\\name12
				- 'App\\Foo\\{x, z}'
				- app\\foo\\y
				- 'App\\Utils\\{format, parse}'
				- Legacy\\helper
				- Long\\short
				- Long\\$long
			constants:
				- 'App\\{BOOTED, LIMIT}'

		XX), $draft->toNeon());
	Assert::noError(fn() => $draft->renderChecked(createTempDir('draft-groups')));
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


test('what a run makes of the configuration written is checked against the draft', function () {
	$root = createTempDir('draft-check');
	$draft = new Draft()->setUse(['psr12'])->setDecision('indentation.unit', 'tab');
	Assert::noError(fn() => $draft->renderChecked($root));
	Assert::noError(fn() => $draft->renderChecked($root, 'php'));

	// a path NEON would read otherwise is quoted, and the scope it loads as is checked too
	$paths = ['src/a,b.php', 'a #b', '[x]', "it's", 'a b', 'yes', '123'];
	$draft = new Draft()
		->setUse(['psr12'])
		->setScope($paths, ['src/a,c.php' => 'generated'], ['php', 'null'])
		->addOverride(new Override(['lib/a,b'], new Profile(decisions: ['naming' => ['method' => 'keep']])));
	Assert::noError(fn() => $draft->renderChecked($root));
	$file = "$root/dresscode.neon";
	file_put_contents($file, $draft->toNeon());
	$config = Loader::loadFile($file);
	Assert::same($paths, $config->paths);
	Assert::same(['php', 'null'], $config->fileExtensions);
	Assert::contains('src/a,c.php', $config->excludePaths);
	Assert::same(['lib/a,b'], $config->overrides[0]->paths);

	$resolved = new RunnerFactory()->resolve(new Config(use: ['psr12']), $root)->resolvedConfig;
	$draft = new Draft()->setUse(['psr12'])->setDecision('indentation.unit', 'tab');
	Assert::exception(
		fn() => $draft->checkResolution($resolved),
		LogicException::class,
		'The configuration written does not say what it was made to: `indentation.unit` resolves to another value.',
	);
});
