<?php declare(strict_types=1);

/**
 * init writes a configuration measured from the code: what it writes, the loader reads and resolves to the
 * values it measured, a configuration that exists is never overwritten, and a decision the code does not make
 * clearly enough is not written as a value.
 */

use DressCode\Config\{Loader, RunnerFactory};
use DressCode\Console\Application;
use DressCode\Measuring\{Budget, FileSample, InProcessProbeRunner, ProbeResult, ProbeRunner, Project, Proposal, Scope, ValueOrigin};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/**
 * A project root of its own with the files given, relative path → content.
 * @param  array<string, string>  $files
 */
function createProject(string $name, array $files): string
{
	$root = createTempDir($name);
	foreach ($files as $path => $content) {
		@mkdir(dirname("$root/$path"), recursive: true); // @ directory may already exist
		file_put_contents("$root/$path", $content);
	}

	return $root;
}


/**
 * @param  list<string>  $args
 * @param  ?string  $answers  what the user types in a terminal; null for no terminal
 * @return array{int, string, string}
 */
function runInit(string $root, array $args = [], ?string $answers = null): array
{
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$in = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	fwrite($in, $answers ?? '');
	rewind($in);
	$code = new Application($out, $err, $in, cwd: $root, interactive: $answers !== null)->run(['dresscode', 'init', ...$args]);
	rewind($out);
	rewind($err);
	return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
}


$tabbed = "<?php\n\nclass A\n{\n\tpublic function b(): string\n\t{\n\t\treturn 'x' . 'y' . 'z';\n\t}\n}\n";


test('init writes what the code says, the loader reads it back as measured, and check runs with it', function () use ($tabbed) {
	$root = createProject('written', [
		'src/A.php' => $tabbed,
		'src/B.php' => str_replace('A', 'B', $tabbed),
		'src/C.php' => "<?php\n\n// @generated, and what a generator wrote says nothing about how the project writes\n",
		'tests/c.phpt' => "<?php\n\nfunction c(): void\n{\n\techo \"plain\";\n}\n",
		'tests/fixtures/generated.php' => "<?php\n    \$x = \"a\";\n",
		'composer.json' => '{}', // the project of its own, not the one the temporary directory lies in
	]);
	[$code, $out] = runInit($root);
	Assert::same(0, $code);
	Assert::match(<<<'XX'
		DRESS|CODE %a%  looking at %a% to write its `dresscode.neon`

		Project    3 PHP files in src, tests
		Left out   fixtures    test data
		           src/C.php   generated
		PHP        8.0, `composer.json` names none
		Standard   perCs (PER Coding Style), the nearest to your code, which departs from it in 1 decision and from psr12 in 1; `--use` names another
		Measured   your code departs from it in 1 decision over 3 files
		As written indentation.unit: tab   your code, all 3 files
		Checked    all 3 files: a fix changes no file
		Your call  removing the decisions below `use` from `dresscode.neon` takes perCs as it is; its fix rewrites 3 files

		Written    `dresscode.neon`
		Next       `dresscode check`      passes already

		XX, $out);

	$neon = (string) file_get_contents("$root/dresscode.neon");
	// the line endings init writes, whatever the checkout gave the heredoc
	Assert::same(str_replace("\r\n", "\n", <<<'XX'
		# made to measure by `dresscode init`; run it again to see what has moved since, it writes nothing over this file
		use: perCs   # PER Coding Style, of the standards the one your code departs from in the fewest decisions

		paths: [src, tests]
		excludePaths:
			- fixtures    # test data
			- src/C.php   # generated
		fileExtensions: [php, phpt]

		# the namespaces of the scope declare no function and no constant, so an unqualified name in a namespace
		# is resolved for certain; one declared later is reported until it is listed
		nameResolution: certain

		indentation:
			unit: tab   # your code, all 3 files

		XX), $neon);
	Assert::notContains('fixRisky', $neon);

	// the round trip: what the run resolves the written file to is what was measured
	$factory = new RunnerFactory;
	$resolved = $factory->resolve(Loader::loadFile("$root/dresscode.neon"), $root)->resolvedConfig;
	Assert::same("\t", $resolved->indent);
	Assert::noError(
		fn() => Proposal::measure(Project::detect($root), Scope::fromAutoload($root, Scope::Extensions), new InProcessProbeRunner($root))
			->checkResolution($resolved),
	);

	// and the configuration is one a run takes, and passes, the generated file out
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$check = new Application($out, $out, cwd: $root)->run(['dresscode', 'check', '--no-cache', '--jobs', '1']);
	rewind($out);
	Assert::same(0, $check, (string) stream_get_contents($out));
});


test(
	'a configuration that exists is not overwritten, and the report ends with that, not with a proposal as long as a file',
	function () use ($tabbed) {
		$root = createProject('existing', ['src/A.php' => $tabbed, 'dresscode.php.dist' => "<?php\n"]);
		[$code, $out, $err] = runInit($root);
		Assert::same(0, $code);
		Assert::match(
			"DRESS|CODE %A%Checked %A%\n`dresscode.php.dist` exists, so nothing is written; it is no configuration init can compare with.\n",
			$out,
		);
		Assert::same('', $err);
		Assert::same("<?php\n", file_get_contents("$root/dresscode.php.dist"));
		Assert::false(is_file("$root/dresscode.neon"));
	},
);


test('init lists what the namespaces declare from every file, and proposes the preset of an installed framework', function () {
	$root = createProject('namespaced', [
		'src/helpers.php' => "<?php\n\nnamespace App;\n\nfunction helper(): void\n{\n}\n\nconst LIMIT = 1;\n",
		'src/polyfill.php' => "<?php\n\nnamespace App\\Compat;\n\nif (!function_exists('App\\Compat\\first')) {\n\tfunction first(): void\n\t{\n\t}\n}\n",
		'src/global.php' => "<?php\n\nfunction globalHelper(): void\n{\n}\n\ndefine('App\\\\BOOTED', true);\n",
		// a declaration on the line of the namespace, and a define() whose leading backslash reaches no name
		'src/oneline.php' => '<?php namespace App\One; function one() {} define(\'App\One\DEFINED\', 1); define(\'\App\One\LEADING\', 2);'
			. "\n",
		'composer.json' => '{"autoload": {"psr-4": {"App\\\\": "src"}}}',
		'composer.lock' => '{"packages": [{"name": "symfony/dependency-injection"}], "packages-dev": []}',
		'vendor/composer/installed.json' => '{"packages": [{"name": "symfony/dependency-injection", "version": "7.3.0", "install-path": "../symfony/dependency-injection"}]}',
	]);
	[$code, $out] = runInit($root, ['--use', 'perCs']);
	Assert::same(0, $code);
	Assert::match(<<<'XX'
		%A%use: perCs
		%A%
		# the namespaces of the scope declare these functions and constants and no others, so an unqualified name
		# in a namespace is resolved for certain; one declared later is reported until it is listed
		nameResolution: certain
		namespaces:
			functions:
				- App\Compat\first
				- App\helper
				- App\One\one
			constants:
				- App\BOOTED
				- App\LIMIT
				- App\One\DEFINED
		%A%
		XX, (string) file_get_contents("$root/dresscode.neon"));

	$factory = new RunnerFactory;
	$resolved = $factory->resolve(Loader::loadFile("$root/dresscode.neon"), $root)->resolvedConfig;
	Assert::same('certain', $resolved->nameResolution);
	Assert::same(['dresscode/psr12', 'dresscode/perCs'], $resolved->use);
	Assert::same('the configuration', $resolved->namespacedFunctions['App\Compat\first']);
	Assert::same(
		'DressCode for symfony/dependency-injection',
		$resolved->namespacedFunctions['Symfony\Component\DependencyInjection\Loader\Configurator\service'],
	);
	Assert::true($resolved->findRule(DressCode\Rules\Namespaces\NoUnlistedNamespacedDeclarationsRule::class)?->isActive());
});


test('init turns the types on where the project has PHPStan, and says why', function () {
	$root = createProject('typed', [
		'src/a.php' => "<?php\n\nnamespace App;\n\nfunction a(): void\n{\n}\n",
		'composer.json' => '{"autoload": {"psr-4": {"App\\\\": "src"}}}',
		'composer.lock' => '{"packages": [], "packages-dev": [{"name": "phpstan/phpstan"}]}',
	]);
	[$code] = runInit($root, ['--use', 'perCs']);
	Assert::same(0, $code);
	Assert::contains(
		"\ntypeAnalysis: phpstan   # phpstan/phpstan is installed, so the rules get the types of the code\n",
		(string) file_get_contents("$root/dresscode.neon"),
	);
	Assert::same('phpstan', Loader::loadFile("$root/dresscode.neon")->typeAnalysis);

	$root = createProject('untyped', ['src/a.php' => "<?php\n", 'composer.json' => '{"autoload": {"psr-4": {"App\\\\": "src"}}}']);
	runInit($root, ['--use', 'perCs']);
	Assert::notContains('typeAnalysis', (string) file_get_contents("$root/dresscode.neon"));
});


test('init proposes the standard of the preset Laravel Pint formats with, as the package or its configuration says', function () {
	foreach ([
		'package' => ['composer.lock' => '{"packages": [], "packages-dev": [{"name": "laravel/pint"}]}'],
		'configuration' => ['pint.json' => '{"preset": "laravel"}'],
	] as $name => $files) {
		$root = createProject(
			"pint-$name",
			['src/a.php' => "<?php\n", 'composer.json' => '{"autoload": {"psr-4": {"App\\\\": "src"}}}', ...$files],
		);
		[$code, $out] = runInit($root);
		Assert::same(0, $code);
		Assert::contains('Standard   laravel (the style of Laravel Pint), Laravel Pint formats the project in this style', $out);
		Assert::contains(
			'use: laravel   # Laravel Pint formats the project in this style',
			(string) file_get_contents("$root/dresscode.neon"),
		);
	}

	// another preset of Pint is its standard, and one no standard is leaves it to the code, Laravel winning a tie
	foreach (['per' => 'perCs', 'psr12' => 'psr12', 'symfony' => 'symfony', 'empty' => 'laravel'] as $preset => $standard) {
		$root = createProject(
			"pint-$preset",
			['src/a.php' => "<?php\n", 'pint.json' => "{\"preset\": \"$preset\"}", 'composer.json' => '{}'],
		);
		[, $out] = runInit($root);
		Assert::contains("Standard   $standard (", $out);
	}
	Assert::contains('the nearest to your code', $out);

	// a standard given wins
	$root = createProject('pint-given', ['src/a.php' => "<?php\n", 'pint.json' => '{}']);
	runInit($root, ['--use', 'nette']);
	Assert::contains("use: nette\n", (string) file_get_contents("$root/dresscode.neon"));
});


test('a file that does not parse is left out of the scope, so what the rest declares is resolved for certain', function () {
	$root = createProject('unparsed', [
		'src/helpers.php' => "<?php\n\nnamespace App;\n\nfunction helper(): void\n{\n}\n",
		'src/broken.php' => "<?php\n\nnamespace App;\n\nfunction (\n",
		'src/plain.php' => "<?php\n\nfunction (\n", // no namespace, so only the check of a standard finds it
	]);
	[$code, $out] = runInit($root);
	Assert::same(0, $code);
	Assert::contains('Project    3 PHP files in src', $out);
	Assert::match("%A%Left out   src/broken.php   does not parse\n           src/plain.php    does not parse\n%A%", $out);
	$neon = (string) file_get_contents("$root/dresscode.neon");
	Assert::contains("excludePaths:\n\t- src/broken.php   # does not parse\n\t- src/plain.php    # does not parse\n", $neon);
	Assert::contains("nameResolution: certain\nnamespaces:\n\tfunctions:\n\t\t- App\\helper\n", $neon);
});


test('more presets than one are written as a list', function () use ($tabbed) {
	$root = createProject('presets', ['src/A.php' => $tabbed]);
	[$code] = runInit($root, ['--use', 'psr12', '--use', 'perCs']);
	Assert::same(0, $code);
	Assert::contains("use:\n\t- psr12\n\t- perCs\n\n", (string) file_get_contents("$root/dresscode.neon"));
});


test('a decision the code does not make clearly is not written as a value', function () {
	$root = createProject('undecided', [
		'src/a.php' => "<?php\n\nfunction a(): void\n{\n\techo 'a', 'b';\n}\n",
		'src/b.php' => "<?php\n\nfunction b(): void\n{\n    echo \"a\", \"b\";\n}\n",
	]);
	[$code, $out] = runInit($root, ['--use', 'nette']);
	Assert::same(0, $code);
	Assert::contains("Standard   nette (the Nette Coding Standard), as given\n", $out);
	Assert::contains("Unchecked  3 decisions left as your code has them (`keep`), each with the reason in the file\n", $out);

	$neon = (string) file_get_contents("$root/dresscode.neon");
	Assert::contains("use: nette\n\n", $neon); // given, so the file says nothing of where it came from
	// half of the code one way and half another: unchecked, and the comment says what the code has
	Assert::match("%A%\tunit: keep %s% # not checked: your code has %a% in 50%, %a% in 50% of 2 files\n%A%", $neon);
	Assert::match("%A%\tquotes: keep %s% # not checked: your code has %a% in 50%, %a% in 50% of 4 places\n%A?%", $neon);
	Assert::notContains('fixRisky', $neon);

	$factory = new RunnerFactory;
	$resolved = $factory->resolve(Loader::loadFile("$root/dresscode.neon"), $root)->resolvedConfig;
	Assert::false($resolved->findRule(DressCode\Rules\Literals\StringQuotesRule::class)?->isActive());
	Assert::same("\t", $resolved->indent); // new code is indented by a tab where nothing decides it
});


test('the unit of the indentation is measured alone, whatever levels the other constructs step in by', function () {
	$switch = "<?php\n\nfunction f(\$a)\n{\n\tswitch (\$a) {\n\tcase 1:\n\t\treturn 1;\n\t}\n}\n";
	$root = createProject('unit', ['src/a.php' => $switch, 'src/b.php' => $switch]);
	[$code, $out] = runInit($root);
	Assert::same(0, $code);
	Assert::contains("As written indentation.unit: tab   your code, all 2 files\n", $out);
});


test('the shape of the conditions is counted by condition, and the shapes the code has pass where none prevails', function () {
	$perLine = "if (\n\t\$a\n\t&& \$b\n) {\n}\n";
	$compact = "if (\$a\n\t&& \$b\n) {\n}\n";
	$attached = "if (\$a\n\t&& \$b) {\n}\n";
	$root = createProject('shapes', [
		'src/a.php' => "<?php\n" . str_repeat($perLine, 7),
		'src/b.php' => "<?php\n" . str_repeat($compact, 5),
	]);
	[$code] = runInit($root, ['--use', 'perCs']);
	Assert::same(0, $code);
	$neon = (string) file_get_contents("$root/dresscode.neon");
	Assert::match(
		"%A%multiline:\n\tedges:\n\t\tcondition: [apart, closingApart] %s% # your code, apart in 58%, closingApart in 42% of 12 places\n%A?%",
		$neon,
	);

	$factory = new RunnerFactory;
	$resolved = $factory->resolve(Loader::loadFile("$root/dresscode.neon"), $root)->resolvedConfig;
	Assert::same(['apart', 'closingApart'], $resolved->decisions['multiline.edges.condition']->value->getWords());

	// a shape at least 70 % of the conditions have is written as the shape
	$root = createProject('shape', ['src/a.php' => "<?php\n" . str_repeat($perLine, 2) . str_repeat($compact, 10)]);
	runInit($root, ['--use', 'perCs']);
	Assert::match(
		"%A%\t\tcondition: closingApart %s% # your code, 83% of 12 places\n%A?%",
		(string) file_get_contents("$root/dresscode.neon"),
	);

	// and so is every shape the rule knows, the bracket attached to the last part too
	$root = createProject('attached', ['src/a.php' => "<?php\n" . str_repeat($perLine, 2) . str_repeat($attached, 10)]);
	runInit($root, ['--use', 'perCs']);
	Assert::match(
		"%A%\t\tcondition: attached %s% # your code, 83% of 12 places\n%A?%",
		(string) file_get_contents("$root/dresscode.neon"),
	);
});


test('a decision the code holds to in all but a few files is written, unless its fix would change more than the budget', function () {
	$files = ['composer.json' => '{}'];
	foreach (range(1, 20) as $i) {
		$files[sprintf('src/f%02d.php', $i)] = $i <= 3 ? "<?php\n\$a = $i;\n" : "<?php\n\n\$a = $i;\n";
	}

	$root = createProject('budget', $files);
	[$code, $out] = runInit($root);
	Assert::same(0, $code);
	Assert::match(
		'%A%Checked    all 20 files: a fix changes no file%A%1 more decision is left as your code has it, as its fix would change more than 2 files (`--budget` raises the limit)%A%',
		$out,
	);
	Assert::contains(
		"\tafterOpeningTag: keep   # not checked: a fix would change 3 of 20 files\n",
		(string) file_get_contents("$root/dresscode.neon"),
	);

	Assert::contains('`blankLines.afterOpeningTag: 1` in `dresscode.neon` checks it too; its fix changes 3 files', $out);

	// init over its own file has nothing to say has moved
	[$code, $out] = runInit($root);
	Assert::same(0, $code);
	Assert::contains("Moved      nothing: `dresscode.neon` says what init measures\n", $out);

	// the budget given takes the three stragglers, and the fix aligns them
	unlink("$root/dresscode.neon");
	[$code, $out] = runInit($root, ['--budget', '3']);
	Assert::same(0, $code);
	Assert::contains('Next       `dresscode fix`        rewrites 3 files', $out);
	Assert::notContains('afterOpeningTag', (string) file_get_contents("$root/dresscode.neon"));

	// and so does the answer, which no budget leaves unchecked
	unlink("$root/dresscode.neon");
	[$code, $out] = runInit($root, ['--set', 'blankLines.afterOpeningTag=0']);
	Assert::same(0, $code);
	Assert::contains('rewrites 17 files', $out);
	Assert::contains("\tafterOpeningTag: 0   # your answer\n", (string) file_get_contents("$root/dresscode.neon"));

	// a decision the standard does not know is no answer
	[$code, , $err] = runInit($root, ['--set', 'project.unknown=1']);
	Assert::same(3, $code);
	Assert::contains('Decision `project.unknown` is not one the standard knows.', $err);
});


test(
	'the configuration of another formatter is read as text and never run, and what it leaves out init leaves out too',
	function () use ($tabbed) {
		$root = createProject('foreign', [
			'composer.json' => '{"scripts": {"cs": "php-cs-fixer fix"}}',
			'.php-cs-fixer.dist.php' => "<?php\nexit(1);\nreturn (new PhpCsFixer\\Config)->setRules(['@PSR12' => true])\n"
				. "\t->setFinder(PhpCsFixer\\Finder::create()->in(__DIR__ . '/src')->exclude('Generated'));\n",
			'src/A.php' => $tabbed,
			'src/S.php' => "<?php\n// phpcs:ignoreFile\n",
			'src/Generated/B.php' => "<?php\n  \$a = 1; // phpcs:ignore Generic.Files.LineLength\n",
		]);
		[$code, $out] = runInit($root);
		Assert::same(0, $code);
		Assert::match(
			'%A%Left out   src/Generated   left out by your formatter%A%Formatter  PHP CS Fixer, `.php-cs-fixer.dist.php`, run by `composer cs`%A%',
			$out,
		);
		Assert::contains(
			"Also       `dresscode check` can take the place of PHP CS Fixer in `composer cs`\n"
			. "           1 phpcs suppression comment: `dresscode migrate suppressions` rewrites them\n",
			$out,
		);
		Assert::contains("\t- src/Generated   # left out by your formatter\n", (string) file_get_contents("$root/dresscode.neon"));
	},
);


test('a rule a ruleset of PHP_CodeSniffer leaves out somewhere is unchecked there', function () {
	$camel = "<?php\n\nclass A\n{\n\tpublic function getName(): void\n\t{\n\t}\n}\n";
	$snake = "<?php\n\nclass B\n{\n\tpublic function get_name(): void\n\t{\n\t}\n}\n";
	$root = createProject('ruleset', [
		'composer.json' => '{}',
		'phpcs.xml' => '<?xml version="1.0"?><ruleset><rule ref="PSR12"/><rule ref="PSR1.Methods.CamelCapsMethodName.NotCamelCaps">'
			. '<exclude-pattern>src/legacy/</exclude-pattern></rule></ruleset>',
		'src/A.php' => $camel,
		'src/legacy/B.php' => $snake,
		'src/legacy/C.php' => str_replace('B', 'C', $snake),
	]);
	[$code, $out] = runInit($root, ['--use', 'psr12']);
	Assert::same(0, $code);
	Assert::contains('Formatter  PHP_CodeSniffer, `phpcs.xml`', $out);
	Assert::match(
		"%A%overrides:\n\t# where PSR1.Methods.CamelCapsMethodName.NotCamelCaps is left out by your formatter\n\t-\n\t\tpaths:\n\t\t\t- src/legacy\n\t\tnaming:\n\t\t\tmethod: keep\n",
		(string) file_get_contents("$root/dresscode.neon"),
	);
	Assert::notContains('baseline', (string) file_get_contents("$root/dresscode.neon"));
});


test('without the usual directories the root itself is the scope', function () use ($tabbed) {
	$root = createProject('flat', ['A.php' => $tabbed]);
	[$code] = runInit($root);
	Assert::same(0, $code);
	Assert::contains("paths: [.]\n", (string) file_get_contents("$root/dresscode.neon"));
});


test('the sample is every k-th file, the same for the same tree, without a file too large', function () {
	$files = [];
	foreach (range(1, 400) as $i) {
		$files[sprintf('src/f%03d.php', $i)] = "<?php\n";
	}

	$files['src/f007.php'] = "<?php\n" . str_repeat('// x', 30_000);
	$root = createProject('sample', $files);
	$scope = array_keys($files);
	sort($scope, SORT_STRING);

	$sample = FileSample::pick($root, $scope);
	Assert::same(199, count($sample->files)); // every second of the 400, less the large one
	Assert::same(1, $sample->oversized);
	Assert::same('src/f001.php', $sample->files[0]);
	Assert::same('src/f003.php', $sample->files[1]);
	Assert::notContains('src/f007.php', $sample->files);
	Assert::same($sample->files, FileSample::pick($root, $scope)->files);

	// the budget of bytes is the cost of the measure, and it holds whatever the count allows
	$files = [];
	foreach (range(1, 30) as $i) {
		$files[sprintf('src/g%02d.php', $i)] = "<?php\n" . str_repeat('x', 90_000);
	}

	$root = createProject('sample-bytes', $files);
	$sample = FileSample::pick($root, array_keys($files));
	Assert::same(22, count($sample->files)); // 22 * 90 kB is the last that fits in 2 MB
	Assert::same(8, $sample->oversized);
	Assert::same(11, count(FileSample::pick($root, array_keys($files), maxBytes: 1_000_000)->files));
	Assert::same(10, count(FileSample::pick($root, array_keys($files), maxFiles: 10)->files)); // every third of the 30
});


/** @return array<string, string>  twenty files, three of them without the blank line the others have after the tag */
function stragglers(): array
{
	$files = ['composer.json' => '{}'];
	foreach (range(1, 20) as $i) {
		$files[sprintf('src/f%02d.php', $i)] = $i <= 3 ? "<?php\n\$a = $i;\n" : "<?php\n\n\$a = $i;\n";
	}

	return $files;
}


test('a decision left unchecked that makes another one cost more than the budget leaves that one unchecked too', function () {
	$root = createProject('cascade', stragglers());
	// with the blank line after the tag left unchecked, the unused imports are said to cost three files
	$runner = new class (new InProcessProbeRunner($root)) implements ProbeRunner {
		public function __construct(
			private ProbeRunner $inner,
		) {
		}


		public function run(array $probes): array
		{
			$results = $this->inner->run($probes);
			$config = $probes['scope'] ?? [];
			if (
				isset($results['scope'])
				&& ($config['blankLines']['afterOpeningTag'] ?? null) === 'keep'
				&& ($config['imports']['unused'] ?? null) !== 'keep'
			) {
				$result = $results['scope'];
				$files = ['src/f10.php' => true, 'src/f11.php' => true, 'src/f12.php' => true];
				$results['scope'] = new ProbeResult(
					$result->places,
					['imports.unused' => $files] + $result->fixable,
					$result->left,
					$result->changedFiles,
					$result->syntaxErrors,
					$result->failed,
				);
			}

			return $results;
		}
	};

	$proposal = Proposal::measure(Project::detect($root), Scope::fromAutoload($root, Scope::Extensions), $runner, ['perCs']);
	$proposal->verify($runner, new Budget(20));
	Assert::same(ValueOrigin::OverBudget, $proposal->values['blankLines.afterOpeningTag']->origin);
	Assert::same(ValueOrigin::OverBudget, $proposal->values['imports.unused']->origin);
	Assert::same(3, $proposal->values['imports.unused']->cost);
});


test('in a terminal init asks where the measuring left the choice, and Enter takes what --yes takes', function () {
	$asked = createProject('asked', stragglers());
	[$code, $out] = runInit($asked, ['--use', 'perCs'], "\n");
	Assert::same(0, $code);
	Assert::contains("Check `blankLines.afterOpeningTag: 1`? A fix rewrites 3 files, more than the budget allows.\n", $out);
	Assert::contains('e.g. src/f01.php:1  ', $out);
	Assert::contains("  [k] don't check it (recommended)\n  [f] 1 everywhere, a fix rewrites 3 files\n", $out);

	$yes = createProject('yes', stragglers());
	[$code, $out] = runInit($yes, ['--use', 'perCs', '--yes'], "f\n");
	Assert::same(0, $code);
	Assert::notContains('[k]', $out);
	Assert::same(file_get_contents("$yes/dresscode.neon"), file_get_contents("$asked/dresscode.neon"));

	// the answer checks the decision, and the configuration is checked again with it
	$answered = createProject('answered', stragglers());
	[$code, $out] = runInit($answered, ['--use', 'perCs'], "f\n");
	Assert::same(0, $code);
	Assert::contains("\nChecked    all 20 files: a fix changes 3 files\n", $out);
	Assert::contains("\tafterOpeningTag: 1   # your answer\n", (string) file_get_contents("$answered/dresscode.neon"));
});


test('a close call of standards is asked first, and the answer measures against the other', function () {
	$root = createProject('close', stragglers());
	[$code, $out] = runInit($root, [], "b\n\n");
	Assert::same(0, $code);
	Assert::match('%A%Your code is as near to %a% as to %a%; which standard should it follow?%A%', $out);
	Assert::notSame('perCs', Loader::loadFile("$root/dresscode.neon")->use[0] ?? null);

	// without a terminal the nearer is taken, and the line says how to measure against the other
	$root = createProject('close-pipe', stragglers());
	[, $out] = runInit($root);
	Assert::match('%A%Your call  `dresscode init --use %a%` measures against %a% instead, where there is no `dresscode.neon` yet%A%', $out);
});


test('names no rule renames that share a suffix are let pass by the recommended answer', function () {
	$files = ['composer.json' => '{}'];
	foreach (['Cart_', 'Order_', 'Item_', 'Stock_'] as $class) {
		$files["src/$class.php"] = "<?php\n\nclass $class\n{\n}\n";
	}

	$root = createProject('names', $files);
	[$code, $out] = runInit($root, ['--use', 'perCs', '--set', 'naming.class=PascalCase']);
	Assert::same(0, $code);
	Assert::contains('`naming.except` lets the class names that end with `_` pass; without it they go to the baseline', $out);
	Assert::match(
		"%A%\texcept: [~_$~] %s%# the 4 class names that end with `_`, which no rule renames\n%A%",
		(string) file_get_contents("$root/dresscode.neon"),
	);
	Assert::contains('passes already', $out);
});


test('a value the code holds to that the formatter contradicts is a question', function () use ($tabbed) {
	$files = [
		'composer.json' => '{}',
		'phpcs.xml' => '<?xml version="1.0"?><ruleset><rule ref="Generic.WhiteSpace.DisallowTabIndent"/></ruleset>',
	];
	foreach (range(1, 4) as $i) {
		$files["src/C$i.php"] = $tabbed;
	}

	$root = createProject('contradicted', $files);
	[$code, $out] = runInit($root, ['--use', 'perCs']);
	Assert::same(0, $code);
	Assert::contains('`indentation.unit: 4 spaces` in `dresscode.neon` says what `phpcs.xml` says; its fix changes 4 files', $out);
	Assert::contains("\tunit: tab", (string) file_get_contents("$root/dresscode.neon"));
});


test('over a configuration that exists, a decision the file leaves to the standard moves with its cost', function () use ($tabbed) {
	$root = createProject('moved', [
		'composer.json' => '{}',
		'src/A.php' => $tabbed,
		'src/B.php' => $tabbed,
		'dresscode.neon' => "use: perCs\npaths: [src]\n",
	]);
	[$code, $out] = runInit($root);
	Assert::same(0, $code);
	Assert::contains('indentation.unit: `dresscode.neon` has 4 spaces, a fix of it rewrites 2 files, init now writes tab', $out);
});


test('for an agent init takes the recommended answers and draws no status', function () {
	$root = createProject('agent', stragglers());
	[$code, $out] = runInit($root, ['--use', 'perCs', '--format', 'agent'], "f\n");
	Assert::same(0, $code);
	Assert::notContains('[k]', $out);
	Assert::notContains('…', $out);
	Assert::contains('`blankLines.afterOpeningTag: 1` in `dresscode.neon` checks it too', $out);
	Assert::contains("\tafterOpeningTag: keep", (string) file_get_contents("$root/dresscode.neon"));
});
