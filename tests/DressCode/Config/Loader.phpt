<?php declare(strict_types=1);

use DressCode\{Config, ConfigurationException, NodeRule, Override, Plugin, PluginManifest, RuleInfo, Stage};
use DressCode\Config\{Loader, NeonReader, RuleBuilder};
use DressCode\Rules\Upgrading\ForbiddenFunctionsRule;
use Nette\Schema\Elements\Type;
use Tester\{Assert, FileMock};

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


final class LoaderPlugin implements Plugin
{
	public function __construct(
		private readonly string $path,
	) {
	}


	public function getManifest(): PluginManifest
	{
		return new PluginManifest(excludePaths: [$this->path]);
	}
}


#[RuleInfo('test/withDependency', Stage::Structure)]
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
	$default = new Config(presets: ['from/default']);
	[$config, $root, $file] = (new Loader)->load(null, $dir, $default);
	Assert::same($default, $config);
	Assert::same(rtrim(str_replace('\\', '/', $dir), '/'), $root);
	Assert::null($file);
});


test('load: without a file and without a default there is no style to run', function () {
	Assert::exception(
		fn() => (new Loader)->load(null, sys_get_temp_dir()),
		ConfigurationException::class,
		'No `dresscode.neon` or `dresscode.php` found in `%a%` or above it, so there is no dress code to check against. Name a preset with `--preset`.',
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
		fn() => Loader::loadFile(FileMock::create("<?php\nreturn new DressCode\\Config(indent: 'spaces');\n", 'php')),
		ConfigurationException::class,
		'Configuration file `%a%`: The indentation must be a number of spaces or `tab`.',
	);
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("indent: spaces\n", 'neon')),
		ConfigurationException::class,
		'Configuration file `%a%`: The indentation must be a number of spaces or `tab`.',
	);
});


test('a map of functions given as a list says to write the name with null', function () {
	$message = '`forbiddenFunctions` maps a function to a sentence; write `var_dump: null` for none.';
	$config = Loader::loadFile(FileMock::create("rules:\n\tforbiddenFunctions: [var_dump, dd]\n", 'neon'));
	Assert::exception(
		fn() => RuleBuilder::processOptions(ForbiddenFunctionsRule::class, [['file', $config->rules['forbiddenFunctions']]]),
		ConfigurationException::class,
		$message,
	);
	Assert::same([['var_dump' => null], []], RuleBuilder::processOptions(ForbiddenFunctionsRule::class, [['file', ['var_dump' => null]]]));
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
	$rule = RuleBuilder::createRule(LoaderRule::class, $factory);
	Assert::same('db', $rule instanceof LoaderRule ? $rule->dependency : null);
	Assert::notSame($rule, RuleBuilder::createRule(LoaderRule::class, $factory));
});


test('a plugin and the factory of an analysis are entities too', function () {
	$config = Loader::loadFile(FileMock::create("plugins: [LoaderPlugin(generated)]\nanalyses: {stdClass, ArrayObject: LoaderFilter::create(...)}\n", 'neon'));
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
	Assert::exception(fn() => $load("plugins: [LoaderFilter::marker()]\n"), ConfigurationException::class, 'Configuration file `%a%`: A plugin must implement `DressCode\Plugin`, `string` given.');
	Assert::exception(fn() => $load("analyses: {ArrayObject: ArrayObject}\n"), ConfigurationException::class, 'Configuration file `%a%`: An analysis is written as its class, or as its class with the entity of its factory.');

	// the class of a rule is looked up when the file is read, what it gives when the rule is built
	Assert::exception(fn() => $load("rules: {LoaderRule: Acme\\Nope()}\n"), ConfigurationException::class, 'Configuration file `%a%`: Class `Acme\Nope` does not exist.');
	$factory = $load("rules: {LoaderRule: LoaderFilter()}\n")->rules['LoaderRule'];
	Assert::type(Closure::class, $factory);
	Assert::exception(
		fn() => RuleBuilder::createRule(LoaderRule::class, $factory),
		ConfigurationException::class,
		'Configuration file `%a%`: The entity of rule `LoaderRule` gives `LoaderFilter`, not a rule.',
	);
});


test('the schema of the NEON file takes exactly the parameters of the constructors of Config and of Override', function () {
	$schema = new ReflectionMethod(NeonReader::class, 'getSchema')->invoke(null);
	$top = array_keys($schema->getShape());
	$overrides = new ReflectionProperty(Type::class, 'itemsValue')->getValue($schema->getShape()['overrides']);
	$override = array_keys($overrides->getShape());

	$names = function (string $class): array {
		$names = array_map(fn(ReflectionParameter $p) => $p->getName(), new ReflectionMethod($class, '__construct')->getParameters());
		sort($names);
		return $names;
	};
	sort($top);
	sort($override);
	Assert::same($names(Config::class), $top);
	Assert::same($names(Override::class), $override);
});
