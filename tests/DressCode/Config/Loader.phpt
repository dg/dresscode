<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Config\Loader;
use DressCode\Config\PresetResolver;
use DressCode\ConfigurationException;
use DressCode\Extension;
use DressCode\NodeRule;
use DressCode\Presets\Per;
use DressCode\RuleInfo;
use DressCode\Stage;
use Tester\Assert;
use Tester\FileMock;
use Tester\Helpers;

require __DIR__ . '/../../bootstrap.php';


final class LoaderFilter
{
	public function __construct(
		private readonly string $marker = '@generated',
		private readonly bool $negate = false,
	) {
	}


	public static function create(string $marker): self
	{
		return new self($marker);
	}


	public static function marker(): string
	{
		return '// skip';
	}


	public static function isEmpty(string $content): bool
	{
		return $content === '';
	}


	public function negated(): self
	{
		return new self($this->marker, !$this->negate);
	}


	public function __invoke(string $content): bool
	{
		return str_contains($content, $this->marker) !== $this->negate;
	}
}


final class LoaderExtension implements Extension
{
	public function __construct(
		private readonly string $path,
	) {
	}


	public function getConfig(): Config
	{
		return new Config(excludePaths: [$this->path]);
	}
}


#[RuleInfo('test/with-dependency', Stage::Structure)]
final class LoaderRule extends NodeRule
{
	public function __construct(
		public readonly string $dependency,
	) {
	}


	public function getVisitedTypes(): array
	{
		return [];
	}
}


$fixtures = str_replace('\\', '/', __DIR__) . '/fixtures';


test('the file is searched upwards from the directory', function () use ($fixtures) {
	Assert::same("$fixtures/project/dresscode.php", Loader::find("$fixtures/project/src/sub"));
	Assert::same("$fixtures/project/dresscode.php", Loader::find("$fixtures\\project\\"));
	Assert::null(Loader::find(sys_get_temp_dir()));
});


test('load: the found file and its directory as the root', function () use ($fixtures) {
	[$config, $root] = (new Loader)->load(null, "$fixtures/project/src/sub");
	Assert::same("$fixtures/project", $root);
	Assert::same(['test/a' => true], $config->rules);
	Assert::same([], $config->presets);
	Assert::same(['src'], $config->paths);
});


test('load: an explicit file is taken wherever the run started', function () use ($fixtures) {
	[$config, $root, $file] = (new Loader)->load("$fixtures/project/dresscode.php", sys_get_temp_dir());
	Assert::same("$fixtures/project", $root);
	Assert::same("$fixtures/project/dresscode.php", $file);
	Assert::same(['test/a' => true], $config->rules);
});


test('load: without a file the default applies and the directory is the root', function () {
	$dir = sys_get_temp_dir();
	[$config, $root, $file] = (new Loader)->load(null, $dir);
	Assert::same([Per::class], $config->presets);
	Assert::same(rtrim(str_replace('\\', '/', $dir), '/'), $root);
	Assert::null($file);

	$default = new Config(presets: ['from/default']);
	Assert::same($default, (new Loader)->load(null, $dir, $default)[0]);
});


test('errors', function () use ($fixtures) {
	Assert::exception(fn() => Loader::loadFile("$fixtures/none.php"), ConfigurationException::class, 'Configuration file %a%none.php does not exist.');
	Assert::exception(fn() => Loader::loadFile("$fixtures/bad.php"), ConfigurationException::class, 'Configuration file %a%bad.php must return DressCode\Config.');
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create('', 'txt')),
		ConfigurationException::class,
		'Configuration file %a% must be a .neon or a .php file.',
	);

	// a value the configuration refuses is an error of the file, in either format
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("<?php\nreturn new DressCode\\Config(indent: 'spaces');\n", 'php')),
		ConfigurationException::class,
		"Configuration file %a%: The indentation must be a number of spaces or 'tab'.",
	);
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("indent: spaces\n", 'neon')),
		ConfigurationException::class,
		"Configuration file %a%: The indentation must be a number of spaces or 'tab'.",
	);
});


test('the two formats side by side are an ambiguity, not a preference', function () {
	$dir = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())) . '/dresscode-formats';
	@mkdir($dir, recursive: true); // @ - may exist
	Helpers::purge($dir);
	file_put_contents("$dir/dresscode.neon", '');
	file_put_contents("$dir/dresscode.php", '');
	Assert::exception(
		fn() => Loader::find($dir),
		ConfigurationException::class,
		"Both $dir/dresscode.neon and $dir/dresscode.php exist; keep one of them.",
	);

	unlink("$dir/dresscode.php");
	rename("$dir/dresscode.neon", "$dir/dresscode.neon.dist");
	Assert::same("$dir/dresscode.neon.dist", Loader::find($dir));
});


test('a misspelled key is an error of the file, and so is the narrowing of a run, which belongs to the command line', function () {
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("path: [src]\n", 'neon')),
		ConfigurationException::class,
		"Configuration file %a%: Unexpected item 'path', did you mean 'paths'?",
	);
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("only: [line-length]\n", 'neon')),
		ConfigurationException::class,
		"Configuration file %a%: Unexpected item 'only'%a%",
	);
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("paths: [src\n", 'neon')),
		ConfigurationException::class,
		'Configuration file %a% is not valid NEON: %a%',
	);
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("analyses: [Acme\\Nope]\n", 'neon')),
		ConfigurationException::class,
		'Configuration file %a%: Analysis class Acme\Nope does not exist.',
	);
});


test('the version of PHP is taken with quotes or without them', function () {
	$php = fn(string $file) => Loader::loadFile(FileMock::create($file, 'neon'))->php;
	Assert::same('8.2', $php("php: '8.2'\n"));
	Assert::same('8.2', $php("php: 8.2\n"));
	Assert::same('8.2', $php("php: 8.2  # what composer.json says\n"));
	Assert::null($php("paths: [src]\n"));

	// a number is a version whose minor is a single digit, which every PHP ever released has had
	Assert::same('8.0', $php("php: 8.0\n"));
	Assert::same('8.0', $php("php: 8\n"));

	// a value that is no version at all is an error of the file, with the file named
	Assert::exception(
		fn() => $php("php: yes\n"),
		ConfigurationException::class,
		"Configuration file %a%: The item 'php' expects to be string|int|float, true given.",
	);
	Assert::exception(
		fn() => $php("php: '8'\n"),
		ConfigurationException::class,
		"Configuration file %a%: Invalid PHP version '8'.",
	);
	Assert::same('8.2', $php("php: 8.25\n")); // no such version, and the minor is one digit
});


test('extensions name rules, presets and extensions, and what the namespaces declare is a map', function () {
	$config = Loader::loadFile(FileMock::create(<<<'XX'
		extensions: [LoaderRule, DressCode\Presets\Per]
		namespaces:
			functions: [App\helper]
		XX, 'neon'));
	Assert::same([LoaderRule::class, Per::class], $config->extensions);
	Assert::same(['functions' => ['App\helper'], 'constants' => []], $config->namespaces);
});


test('an override takes every key of a profile, the entity of a rule included', function () {
	$config = Loader::loadFile(FileMock::create(<<<'XX'
		overrides:
			- paths: [tests]
			  presets: [nette]
			  php: 8.3
			  namespaces: {functions: [App\Tests\fixture]}
			  nameResolution: uncertain
			  fixRisky: [strict-call]
			  warnings: [line-length]
			  rules: {LoaderRule: LoaderRule(dependency: db)}
		XX, 'neon'));
	$override = $config->overrides[0];
	Assert::same(['tests'], $override->paths);
	Assert::same(['nette'], $override->presets);
	Assert::same('8.3', $override->php);
	Assert::same(['App\Tests\fixture'], $override->namespaces['functions']);
	Assert::same('uncertain', $override->nameResolution);
	Assert::same([['strict-call'], ['line-length']], [$override->fixRisky, $override->warnings]);
	Assert::type(Closure::class, $override->rules['LoaderRule']);

	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("overrides:\n\t- rules: {LoaderRule: false}\n", 'neon')),
		ConfigurationException::class,
		'Configuration file %a%paths%a%',
	);
});


test('an entity is a new object, what a static method returns, the method itself, or a chain of calls', function () {
	$skipWhen = fn(string $neon): Closure => Loader::loadFile(FileMock::create($neon, 'neon'))->skipWhen ?? throw new LogicException;
	Assert::true($skipWhen("skipWhen: LoaderFilter(marker: '@generated')\n")('// @generated', 'a.php'));
	Assert::false($skipWhen("skipWhen: LoaderFilter::create(skip)\n")('// @generated', 'a.php'));
	Assert::true($skipWhen("skipWhen: LoaderFilter::isEmpty(...)\n")('', 'a.php'));
	Assert::false($skipWhen("skipWhen: LoaderFilter()::negated()\n")('// @generated', 'a.php'));
	Assert::true($skipWhen("skipWhen: LoaderFilter(LoaderFilter::marker())\n")('// skip', 'a.php'));
});


test('the entity of a rule is its recipe, evaluated at every build', function () {
	$factory = Loader::loadFile(FileMock::create("rules:\n\tLoaderRule: LoaderRule(dependency: db)\n", 'neon'))->rules['LoaderRule'];
	Assert::type(Closure::class, $factory);
	$rule = PresetResolver::createRule(LoaderRule::class, $factory);
	Assert::same('db', $rule instanceof LoaderRule ? $rule->dependency : null);
	Assert::notSame($rule, PresetResolver::createRule(LoaderRule::class, $factory));
});


test('an extension and the factory of an analysis are entities too', function () {
	$config = Loader::loadFile(FileMock::create("extensions: [LoaderExtension(generated)]\nanalyses: {stdClass, ArrayObject: LoaderFilter::create(...)}\n", 'neon'));
	$extension = $config->extensions[0];
	Assert::type(LoaderExtension::class, $extension);
	Assert::contains('generated', $extension->getConfig()->excludePaths);
	Assert::same([stdClass::class, ArrayObject::class], array_keys($config->analyses));
	Assert::null($config->analyses[stdClass::class]);
	Assert::type(Closure::class, $config->analyses[ArrayObject::class]);
});


test('an entity that cannot be evaluated is an error of the file', function () {
	$load = fn(string $neon) => Loader::loadFile(FileMock::create($neon, 'neon'));
	Assert::exception(fn() => $load("skipWhen: Acme\\Nope()\n"), ConfigurationException::class, 'Configuration file %a%: Class Acme\Nope does not exist.');
	Assert::exception(fn() => $load("skipWhen: LoaderFilter::nope(...)\n"), ConfigurationException::class, 'Configuration file %a%: Static method LoaderFilter::nope() does not exist.');
	Assert::exception(fn() => $load("skipWhen: LoaderFilter()::nope()\n"), ConfigurationException::class, 'Configuration file %a%: Method nope() cannot be called on LoaderFilter.');
	Assert::exception(fn() => $load("skipWhen: stdClass()\n"), ConfigurationException::class, 'Configuration file %a%: The value of skipWhen must be callable, stdClass given.');
	Assert::exception(fn() => $load("skipWhen: LoaderFilter::create()\n"), ConfigurationException::class, 'Configuration file %a%: LoaderFilter::create(): Too few arguments %a%');
	Assert::exception(fn() => $load("extensions: [LoaderFilter::marker()]\n"), ConfigurationException::class, 'Configuration file %a%: An extension must implement DressCode\Extension, string given.');
	Assert::exception(fn() => $load("analyses: {ArrayObject: ArrayObject}\n"), ConfigurationException::class, 'Configuration file %a%: An analysis is written as its class, or as its class with the entity of its factory.');

	// the class of a rule is looked up when the file is read, what it gives when the rule is built
	Assert::exception(fn() => $load("rules: {LoaderRule: Acme\\Nope()}\n"), ConfigurationException::class, 'Configuration file %a%: Class Acme\Nope does not exist.');
	$factory = $load("rules: {LoaderRule: LoaderFilter()}\n")->rules['LoaderRule'];
	Assert::type(Closure::class, $factory);
	Assert::exception(
		fn() => PresetResolver::createRule(LoaderRule::class, $factory),
		ConfigurationException::class,
		'Configuration file %a%: The entity of rule LoaderRule gives LoaderFilter, not a rule.',
	);
});
