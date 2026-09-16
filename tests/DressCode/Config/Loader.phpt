<?php declare(strict_types=1);

use DressCode\{Config, ConfigurationException, NodeRule, Plugin, PluginManifest, Profile, RuleInfo, Stage};
use DressCode\Config\{ConfigResolver, Loader, NeonReader, PluginRegistry};
use Nette\Schema\Elements\Type;
use Tester\{Assert, FileMock};

require __DIR__ . '/../../bootstrap.php';


final readonly class LoaderFilter
{
	public function __construct(
		private string $marker = '@generated',
		private bool $negate = false,
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


final readonly class LoaderPlugin implements Plugin
{
	public function __construct(
		private string $path,
	) {
	}


	public function getManifest(): PluginManifest
	{
		return new PluginManifest(excludePaths: [$this->path]);
	}
}


#[RuleInfo(Stage::Structure)]
final class LoaderRule extends NodeRule
{
	use ProjectDecision;

	public function __construct(
		public readonly string $dependency,
	) {
	}


	public function getVisitedNodes(): array
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
	[$config, $root] = Loader::load(null, "$fixtures/project/src/sub");
	Assert::same("$fixtures/project", $root);
	Assert::same(['literals' => ['quotes' => 'single']], $config->decisions);
	Assert::same([], $config->use);
	Assert::same(['src'], $config->paths);
});


test('load: an explicit file is taken wherever the run started', function () use ($fixtures) {
	[$config, $root, $file] = Loader::load("$fixtures/project/dresscode.php", sys_get_temp_dir());
	Assert::same("$fixtures/project", $root);
	Assert::same("$fixtures/project/dresscode.php", $file);
	Assert::same(['literals' => ['quotes' => 'single']], $config->decisions);
});


test('load: without a file the default applies and the directory is the root', function () {
	$dir = sys_get_temp_dir();
	$default = new Config(use: ['from/default']);
	[$config, $root, $file] = Loader::load(null, $dir, $default);
	Assert::same($default, $config);
	Assert::same(rtrim(str_replace('\\', '/', $dir), '/'), $root);
	Assert::null($file);
});


test('load: without a file and without a default there is no style to run', function () {
	Assert::exception(
		fn() => Loader::load(null, sys_get_temp_dir()),
		ConfigurationException::class,
		'No `dresscode.neon` or `dresscode.php` found in `%a%` or above it, so there is no dress code to check against. Name a standard with `--use`.',
	);
});


test('errors', function () use ($fixtures) {
	Assert::exception(fn() => Loader::loadFile("$fixtures/none.php"), ConfigurationException::class, 'Configuration file `%a%none.php` does not exist.');
	Assert::exception(fn() => Loader::loadFile("$fixtures/bad.php"), ConfigurationException::class, 'Configuration file `%a%bad.php` must return `DressCode\Config`.');
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create('', 'txt')),
		ConfigurationException::class,
		'Configuration file `%a%` must be a `.neon` or a `.php` file.',
	);

	// a value the configuration refuses is an error of the file, in either format
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("<?php\nreturn new DressCode\\Config(targets: ['php' => 'eight']);\n", 'php')),
		ConfigurationException::class,
		'Configuration file `%a%`: The PHP version must be written as `8.2`, `eight` given.',
	);
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("targets: {php: eight}\n", 'neon')),
		ConfigurationException::class,
		'Configuration file `%a%`: The PHP version must be written as `8.2`, `eight` given.',
	);
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("<?php\nreturn new DressCode\\Config(uses: ['nette']);\n", 'php')),
		ConfigurationException::class,
		'Configuration file `%a%`: Unknown named parameter $uses on line 2',
	);
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("<?php\nreturn new DressCode\\Config(use: ['nette'] paths: ['src']);\n", 'php')),
		ConfigurationException::class,
		'Configuration file `%a%`: syntax error, %a% on line 2',
	);
});


test('a map of functions given as a list says to write a map', function () {
	$config = Loader::loadFile(FileMock::create("upgrading:\n\tlibraries:\n\t\tforbiddenFunctions: [var_dump, dd]\n", 'neon'));
	Assert::exception(
		fn() => new ConfigResolver(new PluginRegistry)->resolve($config, '8.4'),
		ConfigurationException::class,
		'Key `upgrading.libraries.forbiddenFunctions` does not take %a%; write a map of names.',
	);
});


test('a NEON file and a PHP file say the same thing, and the local file wins over the template', function () use ($fixtures) {
	Assert::same("$fixtures/formats/dresscode.neon", Loader::find("$fixtures/formats"));
	Assert::equal(
		Loader::loadFile("$fixtures/formats/dresscode.php.dist"),
		Loader::loadFile("$fixtures/formats/dresscode.neon"),
	);
});


test('the two formats side by side are an ambiguity, not a preference', function () {
	$dir = createTempDir('formats');
	file_put_contents("$dir/dresscode.neon", '');
	file_put_contents("$dir/dresscode.php", '');
	Assert::exception(
		fn() => Loader::find($dir),
		ConfigurationException::class,
		"Both `$dir/dresscode.neon` and `$dir/dresscode.php` exist; keep one of them.",
	);

	$dir = createTempDir('formats');
	file_put_contents("$dir/dresscode.neon.dist", '');
	Assert::same("$dir/dresscode.neon.dist", Loader::find($dir));
});


test('a misspelled key is an error of the file, and so is the narrowing of a run, which belongs to the command line', function () {
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("path: [src]\n", 'neon')),
		ConfigurationException::class,
		"Configuration file `%a%`: Unexpected item 'path', did you mean 'paths'?",
	);
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("only: [lineLength]\n", 'neon')),
		ConfigurationException::class,
		"Configuration file `%a%`: Unexpected item 'only'%a%",
	);
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("paths: [src\n", 'neon')),
		ConfigurationException::class,
		'Configuration file `%a%` is not valid NEON: %a%',
	);
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("analyses: [Acme\\Nope]\n", 'neon')),
		ConfigurationException::class,
		'Configuration file `%a%`: Analysis class `Acme\Nope` does not exist.',
	);
});


test('the version of PHP is taken with quotes or without them', function () {
	$php = fn(string $file) => Loader::loadFile(FileMock::create($file, 'neon'))->targets['php'] ?? null;
	Assert::same('8.2', $php("targets: {php: '8.2'}\n"));
	Assert::same('8.2', $php("targets: {php: 8.2}\n"));
	Assert::same('8.2', $php("targets:\n\tphp: 8.2  # what composer.json says\n"));
	Assert::null($php("paths: [src]\n"));

	// a number is a version whose minor is a single digit, which every PHP ever released has had
	Assert::same('8.0', $php("targets: {php: 8.0}\n"));
	Assert::same('8.0', $php("targets: {php: 8}\n"));

	// a value that is no version at all is an error of the file, with the file named
	Assert::exception(
		fn() => $php("targets: {php: yes}\n"),
		ConfigurationException::class,
		"Configuration file `%a%`: The item 'targets%a%php' expects to be string|int|float, true given.",
	);
	// the target is one version, not the constraint of composer.json
	Assert::exception(
		fn() => $php("targets: {php: '>=8.1'}\n"),
		ConfigurationException::class,
		'Configuration file `%a%`: The PHP version must be written as `8.2`, `>=8.1` given.',
	);
	Assert::same('8.2', $php("targets: {php: 8.25}\n")); // no such version, and the minor is one digit
});


test('the version of a package the code is written for is taken in quotes, a whole number without them', function () {
	$packages = fn(string $file) => array_diff_key(Loader::loadFile(FileMock::create($file, 'neon'))->targets, ['php' => true]);
	Assert::same(['acme/mailer' => '3.10', 'acme/lib' => '4'], $packages("targets:\n\tacme/mailer: '3.10'\n\tacme/lib: 4\n"));
	Assert::same(['php' => '8.2', 'acme/mailer' => '3.3'], Loader::loadFile(FileMock::create("targets: {php: 8.2, acme/mailer: '3.3'}\n", 'neon'))->targets);
	Assert::same([], $packages("paths: [src]\n"));

	// NEON reads a bare 3.10 as the number 3.1, so a number with a fraction is refused rather than guessed
	Assert::exception(
		fn() => $packages("targets:\n\tacme/mailer: 3.10\n"),
		ConfigurationException::class,
		'Configuration file `%a%`: The version of package `acme/mailer` must be in quotes, because NEON reads a bare `3.10` as the number `3.1`.',
	);
	Assert::exception(
		fn() => $packages("targets:\n\tacme/mailer: ^3.3\n"),
		ConfigurationException::class,
		'Configuration file `%a%`: Invalid version `^3.3` of package `acme/mailer`.',
	);

	// it is a decision of the project, which an override does not make
	Assert::exception(
		fn() => $packages("overrides:\n\t- paths: [src]\n\t  targets: {acme/mailer: '3.3'}\n"),
		ConfigurationException::class,
		'Configuration file `%a%`: The override for `src` targets the version of PHP alone, `acme/mailer` given;%a%',
	);
});


test('the types of the code come from phpstan or from nowhere', function () {
	$types = fn(string $file) => Loader::loadFile(FileMock::create($file, 'neon'))->typeAnalysis;
	Assert::same('phpstan', $types("typeAnalysis: phpstan\n"));
	Assert::null($types("paths: [src]\n"));
	Assert::exception(
		fn() => $types("typeAnalysis: psalm\n"),
		ConfigurationException::class,
		"Configuration file `%a%`: The item 'typeAnalysis' expects to be %a%",
	);

	// the types are known for the whole project, which an override does not decide
	Assert::exception(
		fn() => $types("overrides:\n\t- paths: [src]\n\t  typeAnalysis: phpstan\n"),
		ConfigurationException::class,
		"Configuration file `%a%`: Unexpected item 'overrides\u{a0}›\u{a0}0\u{a0}›\u{a0}typeAnalysis'%a%",
	);
});


test('a rule of the project is named by its class, and what the namespaces declare is a map', function () {
	$config = Loader::loadFile(FileMock::create(<<<'XX'
		rules: [LoaderRule]
		namespaces:
			functions: [App\helper]
		XX, 'neon'));
	Assert::same([LoaderRule::class => null], $config->rules);
	Assert::same(['functions' => ['App\helper'], 'constants' => []], $config->namespaces);
});


test('a list of a single item is written without the list, in a configuration and in an override', function () {
	$config = Loader::loadFile(FileMock::create(<<<'XX'
		use: nette
		paths: src
		excludePaths: src/generated
		fileExtensions: php
		fixRisky: modernizations
		warnOnly: cleanup
		namespaces:
			functions: App\helper
		overrides:
			- paths: tests
			  use: psr12
		XX, 'neon'));
	Assert::same(['nette'], $config->use);
	Assert::same(['src'], $config->paths);
	Assert::contains('src/generated', $config->excludePaths);
	Assert::same(['php'], $config->fileExtensions);
	Assert::same(['modernizations'], $config->fixRisky);
	Assert::same(['cleanup'], $config->warnOnly);
	Assert::same(['App\helper'], $config->namespaces['functions']);
	Assert::same(['tests'], $config->overrides[0]->paths);
	Assert::same(['psr12'], $config->overrides[0]->profile->use);
	Assert::exception(fn() => Loader::loadFile(FileMock::create('use: 1', 'neon')), ConfigurationException::class, "%a%The item 'use%a%0' expects to be string|%a%Entity, 1 given.");
});


test('a comment that silences rules is a pattern with a rule or a list of them, in a configuration and in an override', function () {
	$config = Loader::loadFile(FileMock::create(<<<'XX'
		suppressionComments:
			"~intentionally ==~": noLooseComparisons
			"~^// @ ~": [noErrorSuppression, noLooseComparisons]
		overrides:
			- paths: [tests]
			  suppressionComments: {"~ok~": noLooseComparisons}
		XX, 'neon'));
	Assert::same(['~intentionally ==~' => ['noLooseComparisons'], '~^// @ ~' => ['noErrorSuppression', 'noLooseComparisons']], $config->suppressionComments);
	Assert::same(['~ok~' => ['noLooseComparisons']], $config->overrides[0]->profile->suppressionComments);
	Assert::exception(fn() => Loader::loadFile(FileMock::create("suppressionComments: {'~x~': 1}", 'neon')), ConfigurationException::class);
	Assert::exception(fn() => Loader::loadFile(FileMock::create("suppressionComments: {'x': noLooseComparisons}", 'neon')), ConfigurationException::class, '%a%is not a regular expression%a%');
});


test('the page of the rules the configuration names by class is an address with the slug in it', function () {
	$ruleUrl = fn(string $file) => Loader::loadFile(FileMock::create($file, 'neon'))->ruleUrl;
	Assert::same('https://wiki.acme.dev/rules/{slug}', $ruleUrl("ruleUrl: 'https://wiki.acme.dev/rules/{slug}'\n"));
	Assert::null($ruleUrl("paths: [src]\n"));
	Assert::exception(
		fn() => $ruleUrl("ruleUrl: 'wiki page'\n"),
		ConfigurationException::class,
		'Configuration file `%a%`: Invalid `ruleUrl` `wiki page`, an address such as `https://acme.dev/rules/{slug}` is expected.',
	);
});


test('an override takes every key of a profile', function () {
	$config = Loader::loadFile(FileMock::create(<<<'XX'
		overrides:
			- paths: [tests]
			  use: [nette]
			  targets: {php: 8.3}
			  namespaces: {functions: [App\Tests\fixture]}
			  nameResolution: uncertain
			  fixRisky: [strictComparisonArgumentRequired]
			  warnOnly: [lineLength]
		XX, 'neon'));
	$override = $config->overrides[0];
	Assert::same(['tests'], $override->paths);
	Assert::same(['nette'], $override->profile->use);
	Assert::same('8.3', $override->profile->targets['php'] ?? null);
	Assert::same(['App\Tests\fixture'], $override->profile->namespaces['functions']);
	Assert::same('uncertain', $override->profile->nameResolution);
	Assert::same([['strictComparisonArgumentRequired'], ['lineLength']], [$override->profile->fixRisky, $override->profile->warnOnly]);

	// the rules of the project are known to the whole of it, which an override does not decide
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("overrides:\n\t- paths: [tests]\n\t  rules: [LoaderRule]\n", 'neon')),
		ConfigurationException::class,
		"Configuration file `%a%`: Unexpected item 'overrides\u{a0}›\u{a0}0\u{a0}›\u{a0}rules'%a%",
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


test('a rule of the project is named by its class, a factory being for dresscode.php', function () {
	Assert::same([LoaderRule::class => null], Loader::loadFile(FileMock::create("rules: [LoaderRule]\n", 'neon'))->rules);
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("rules:\n\tLoaderRule: LoaderRule(dependency: db)\n", 'neon')),
		ConfigurationException::class,
		'Configuration file `%a%`: A rule of the project is written as its class; a rule built by a factory is given by `dresscode.php`.',
	);
});


test('a plugin and the factory of an analysis are entities too', function () {
	$config = Loader::loadFile(FileMock::create("use: [LoaderPlugin(generated)]\nanalyses: {stdClass, ArrayObject: LoaderFilter::create(...)}\n", 'neon'));
	$plugin = $config->plugins[0];
	Assert::type(LoaderPlugin::class, $plugin);
	Assert::contains('generated', $plugin->getManifest()->excludePaths);
	Assert::same([stdClass::class, ArrayObject::class], array_keys($config->analyses));
	Assert::null($config->analyses[stdClass::class]);
	Assert::type(Closure::class, $config->analyses[ArrayObject::class]);
});


test('an entity that cannot be evaluated is an error of the file', function () {
	$load = fn(string $neon) => Loader::loadFile(FileMock::create($neon, 'neon'));
	Assert::exception(fn() => $load("skipWhen: Acme\\Nope()\n"), ConfigurationException::class, 'Configuration file `%a%`: Class `Acme\Nope` does not exist.');
	Assert::exception(fn() => $load("skipWhen: LoaderFilter::nope(...)\n"), ConfigurationException::class, 'Configuration file `%a%`: Static method `LoaderFilter::nope()` does not exist.');
	Assert::exception(fn() => $load("skipWhen: LoaderFilter()::nope()\n"), ConfigurationException::class, 'Configuration file `%a%`: Method `nope()` cannot be called on `LoaderFilter`.');
	Assert::exception(fn() => $load("skipWhen: stdClass()\n"), ConfigurationException::class, 'Configuration file `%a%`: The value of `skipWhen` must be callable, `stdClass` given.');
	Assert::exception(fn() => $load("skipWhen: LoaderFilter::create()\n"), ConfigurationException::class, 'Configuration file `%a%`: `LoaderFilter::create()`: Too few arguments %a%');
	// an entity of `use` that does not start from a plugin is refused before it is called
	Assert::exception(fn() => $load("use: [LoaderFilter::marker()]\n"), ConfigurationException::class, 'Configuration file `%a%`: An entity in `use` is a plugin, which `LoaderFilter` is not.');
	Assert::exception(fn() => $load("use: [SplFileObject(evil.txt, w)]\n"), ConfigurationException::class, 'Configuration file `%a%`: An entity in `use` is a plugin, which `SplFileObject` is not.');
	Assert::exception(fn() => $load("use: [LoaderPlugin(SplFileObject(evil.txt, w))]\n"), ConfigurationException::class, 'Configuration file `%a%`: The arguments of plugin `LoaderPlugin` in `use` are data, not an entity.');
	Assert::false(is_file('evil.txt'));
	Assert::exception(fn() => $load("analyses: {ArrayObject: ArrayObject}\n"), ConfigurationException::class, 'Configuration file `%a%`: An analysis is written as its class, or as its class with the entity of its factory.');
	Assert::exception(fn() => $load("decisions:\n\tfile: {bom: forbidden}\nspacing: {call: compact}\n"), ConfigurationException::class, 'Configuration file `%a%`: The decisions are written as sections at the top or under `decisions`, not both; `spacing` stands beside `decisions`.');
	Assert::exception(fn() => $load("overrides:\n\t-\n\t\tpaths: [src]\n\t\tdecisions: {file: {bom: keep}}\n\t\tspacing: {call: keep}\n"), ConfigurationException::class, 'Configuration file `%a%`: %a% `spacing` stands beside `decisions`.');
});


test('the schema of the NEON file takes exactly the parameters of the constructors of Config and of Override', function () {
	$schema = new ReflectionMethod(NeonReader::class, 'getSchema')->invoke(null);
	$top = array_keys($schema->getShape());
	$overrides = new ReflectionProperty(Type::class, 'itemsValue')->getValue($schema->getShape()['overrides']);
	$override = array_keys($overrides->getShape());

	$names = function (string $class, string ...$more): array {
		$names = array_map(fn(ReflectionParameter $p) => $p->getName(), new ReflectionMethod($class, '__construct')->getParameters());
		$names = [...$names, ...$more];
		sort($names);
		return $names;
	};
	sort($top);
	sort($override);
	Assert::same($names(Config::class), $top);
	Assert::same($names(Profile::class, 'paths'), $override);
});
