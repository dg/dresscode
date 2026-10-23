<?php declare(strict_types=1);

use DressCode\Measuring\{LeftOutReason, Scope};
use Tester\{Assert, Environment};

require __DIR__ . '/../../bootstrap.php';


/**
 * A project root of its own with the files given, relative path → content.
 * @param  array<string, string>  $files
 */
function createScopeProject(string $name, array $files): string
{
	$root = createTempDir($name);
	foreach ($files as $path => $content) {
		@mkdir(dirname("$root/$path"), recursive: true); // @ directory may already exist
		file_put_contents("$root/$path", $content);
	}

	return $root;
}


/** @param  list<string>  $args */
function runScopeGit(string $root, array $args): void
{
	exec('git -C ' . escapeshellarg($root) . ' ' . implode(' ', array_map(escapeshellarg(...), $args)) . ' 2>&1', $output, $exit);
	if ($exit !== 0) {
		Environment::skip('git does not run: ' . implode("\n", $output));
	}
}


$php = "<?php\n";


test(
	'with git the scope is what the repository tracks, the entries of the root its paths, what is left out said why',
	function () use ($php) {
		$root = createScopeProject('scope-git', [
			'src/A.php' => $php,
			'src/Parser/Lexer.php' => $php,
			'src/Data/one.php' => $php,
			'src/Data/two.php' => $php,
			'src/Lazy/Proxy.php' => $php,
			'tests/a.phpt' => $php,
			'tests/fixtures/style.php' => $php,
			'build/scripts/x.php' => $php,
			'build/tracked.php' => $php,
			'compile.php' => $php,
			'vendor/lib/a.php' => $php,
			'notes/readme.txt' => 'text',
			'.gitattributes' => "src/Data/** linguist-generated\n",
			'.gitignore' => "/build/scripts/\n/src/Lazy/\nlocal.php\n",
			'src/local.php' => $php,
		]);
		runScopeGit($root, ['init', '-q']);
		runScopeGit($root, ['add', '-A']);
		runScopeGit($root, ['add', '-f', 'vendor']);

		$scope = Scope::fromGit($root, ['php', 'phpt']);
		Assert::notNull($scope);
		Assert::same(
			['build/tracked.php', 'compile.php', 'src/A.php', 'src/Parser/Lexer.php', 'tests/a.phpt'],
			$scope->files,
		);
		Assert::same([
			'src/Data/one.php' => LeftOutReason::Generated,
			'src/Data/two.php' => LeftOutReason::Generated,
			'tests/fixtures/style.php' => LeftOutReason::TestData,
			'build/scripts' => LeftOutReason::Ignored,
			'src/Lazy' => LeftOutReason::Ignored,
			'src/local.php' => LeftOutReason::Ignored,
		], $scope->leftOut);
		Assert::same(['build', 'compile.php', 'src', 'tests'], $scope->listPaths());
		Assert::same([
			'build/scripts' => LeftOutReason::Ignored,
			'fixtures' => LeftOutReason::TestData,
			'src/Data' => LeftOutReason::Generated,
			'src/Lazy' => LeftOutReason::Ignored,
			'src/local.php' => LeftOutReason::Ignored,
		], $scope->listExcludePaths());
	},
);


test('a test in the format PHPT is no PHP, and an extension no file of the scope has needs no exclusion', function () use ($php) {
	$phpt = "--TEST--\nA test\n--FILE--\n<?php\necho 1;\n--EXPECT--\n1\n";
	$root = createScopeProject('scope-phpt', ['src/a.php' => $php, 'tests/a.phpt' => $phpt, 'tests/b.phpt' => $phpt]);
	runScopeGit($root, ['init', '-q']);
	runScopeGit($root, ['add', '-A']);
	$scope = Scope::fromGit($root, ['php', 'phpt']);
	Assert::same(['src/a.php'], $scope?->files);
	Assert::same(['php'], $scope->listExtensions());
	Assert::same(['src'], $scope->listPaths());
	Assert::same([], $scope->listExcludePaths());

	// beside a .phpt that is PHP, the configuration takes the extension and has to exclude the test
	file_put_contents("$root/tests/c.phpt", $php);
	runScopeGit($root, ['add', '-A']);
	$scope = Scope::fromGit($root, ['php', 'phpt']);
	Assert::same(['php', 'phpt'], $scope?->listExtensions());
	Assert::same(['tests/a.phpt' => LeftOutReason::PhptTest, 'tests/b.phpt' => LeftOutReason::PhptTest], $scope->listExcludePaths());
});


test('a file the paths name one by one is excluded once it is left out, whatever the reason', function () use ($php) {
	$root = createScopeProject('scope-named', ['only.php' => $php, 'src/a.php' => $php]);
	foreach (LeftOutReason::cases() as $reason) {
		$scope = Scope::fromPaths($root, ['only.php', 'src'], ['php']);
		$scope->leaveOut(['only.php'], $reason);
		Assert::same(['only.php', 'src'], $scope->listPaths());
		Assert::same(['only.php' => $reason], $scope->listExcludePaths(), $reason->name);
	}
});


test('a root that lies in no repository has no scope from git', function () use ($php) {
	$root = createScopeProject('scope-no-git', ['src/a.php' => $php]);
	Assert::null(Scope::fromGit($root, ['php']));
});


test('without git the scope is what the autoload names and the conventional directories beside it', function () use ($php) {
	$composer = fn(array $autoload) => json_encode($autoload, JSON_THROW_ON_ERROR);
	$paths = fn(string $root) => Scope::fromAutoload($root, ['php'])->listPaths();

	// psr-4 with a prefix on several paths and one that is not there, a test suite only the convention knows
	$root = createScopeProject('scope-psr4', [
		'composer.json' => $composer([
			'autoload' => ['psr-4' => ['App\\' => 'ModuleFront', 'App\\Api\\' => ['ModuleApi/', 'gone']]],
			'autoload-dev' => ['psr-4' => ['App\\Tests\\' => 'test/']],
		]),
		'ModuleFront/a.php' => $php,
		'ModuleApi/a.php' => $php,
		'test/a.php' => $php,
		'www.admin/index.php' => $php,
		'cron/a.php' => $php,
		'assets/a.php' => $php,
	]);
	Assert::same(['ModuleApi', 'ModuleFront', 'cron', 'test', 'www.admin'], $paths($root));

	// a classmap names directories and single files, as the files section does, and a file outside the directories is a path
	$root = createScopeProject('scope-classmap', [
		'composer.json' => $composer(
			['autoload' => ['classmap' => ['src/', 'src/helpers.php', 'legacy.php'], 'files' => ['bootstrap.php']]],
		),
		'src/a.php' => $php,
		'src/helpers.php' => $php,
		'legacy.php' => $php,
		'bootstrap.php' => $php,
		'tests/a.php' => $php,
	]);
	Assert::same(['bootstrap.php', 'legacy.php', 'src', 'tests'], $paths($root));

	// a path inside another one is already in the scope, whichever of the two named it
	$root = createScopeProject('scope-nested', [
		'composer.json' => $composer(['autoload' => ['psr-4' => ['App\\' => 'ModuleFront/', 'App\\Sub\\' => 'ModuleFront/Sub']]]),
		'ModuleFront/a.php' => $php,
		'ModuleFront/Sub/a.php' => $php,
	]);
	Assert::same(['ModuleFront'], $paths($root));

	$root = createScopeProject('scope-app', [
		'composer.json' => $composer(['autoload' => ['psr-0' => ['' => 'app/Model']]]),
		'app/Model/a.php' => $php,
		'app/bootstrap.php' => $php,
	]);
	Assert::same(['app'], $paths($root));

	// a dot segment names the same directory as the path without it, and only resolved do the two compare as one
	$root = createScopeProject('scope-dots', [
		'composer.json' => $composer(['autoload' => ['psr-4' => ['App\\' => './src', 'App\\Api\\' => 'app/../ModuleApi']]]),
		'src/a.php' => $php,
		'ModuleApi/a.php' => $php,
	]);
	Assert::same(['ModuleApi', 'src'], $paths($root));

	// an autoload pointing at the root itself is the whole scope
	$root = createScopeProject('scope-root', [
		'composer.json' => $composer(['autoload' => ['psr-4' => ['App\\' => '']]]),
		'src/a.php' => $php,
	]);
	Assert::same(['.'], $paths($root));

	// the composer.json of a directory above names paths of another project, which are none of this scope
	$root = createScopeProject('scope-above/package', [
		'../composer.json' => $composer(['autoload' => ['psr-4' => ['App\\' => 'src']]]),
		'../src/a.php' => $php, // the src of the project above, which exists and is still none of this scope
		'src/a.php' => $php,
	]);
	Assert::same(['src'], $paths($root));

	// without a composer.json the convention is the whole answer, and the data of tests are left out
	$root = createScopeProject('scope-conventional', [
		'src/a.php' => $php,
		'tests/a.php' => $php,
		'tests/expected/a.php' => $php,
		'storage/a.php' => $php,
	]);
	$scope = Scope::fromAutoload($root, ['php']);
	Assert::same(['src', 'tests'], $scope->listPaths());
	Assert::same(['src/a.php', 'tests/a.php'], $scope->files);
	Assert::same(['expected' => LeftOutReason::TestData], $scope->listExcludePaths());
});
