<?php declare(strict_types=1);

/**
 * The decisions a configuration writes: sections beside the keys of the configuration, laid over what it uses,
 * a preset by its name or a file by its path, and narrowed by the run without a value changed.
 */

use DressCode\{Config, ConfigurationException, Profile};
use DressCode\Config\{ConfigResolver, Loader, PluginRegistry};
use DressCode\Console\Application;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** @param  array<string, string>  $files  name => content */
function createDecisionsProject(array $files): string
{
	$dir = createTempDir('decisions');
	foreach ($files as $name => $content) {
		file_put_contents("$dir/$name", $content);
	}

	return $dir;
}


/** @param  ?list<string>  $only */
function resolveFile(string $file, ?Profile $commandLine = null, ?array $only = null): DressCode\Config\ResolvedConfig
{
	return new ConfigResolver(new PluginRegistry)->resolve(Loader::loadFile($file), '8.3', commandLine: $commandLine, only: $only);
}


test('the sections of a file are its decisions, laid over the preset it uses', function () {
	$dir = createDecisionsProject(['dresscode.neon' => "use: nette\nblankLines:\n\tbetweenMethods: 1\n"]);
	$resolved = resolveFile("$dir/dresscode.neon");
	Assert::same([1, 1], $resolved->decisions['blankLines.betweenMethods']->value->getCount());
	Assert::same('the configuration', $resolved->decisions['blankLines.betweenMethods']->value->origin?->describe());
	Assert::same([1, 2], $resolved->decisions['blankLines.betweenDeclarations']->value->getCount());
	Assert::same('dresscode/nette', $resolved->decisions['blankLines.betweenDeclarations']->value->origin?->describe());
	Assert::contains('dresscode/nette', $resolved->use);
	Assert::true($resolved->findRule(DressCode\Rules\Whitespace\BlankLinesRule::class)?->isActive());
});


test('a file used by its path is taken relative to the file writing it, once however often it is named', function () {
	$dir = createDecisionsProject([
		'shared.neon' => "blankLines:\n\tbetweenMethods: 3\n\tafterImports: 1\n",
		'left.neon' => "use: shared.neon\nblankLines:\n\tbetweenMethods: 2\n",
		'right.neon' => "use: shared.neon\nblankLines:\n\tafterImports: 2\n",
		'dresscode.neon' => "use: [left.neon, right.neon]\n",
	]);
	$resolved = resolveFile("$dir/dresscode.neon");
	$shared = strtr((string) realpath("$dir/shared.neon"), '\\', '/');
	Assert::same([$shared, strtr((string) realpath("$dir/left.neon"), '\\', '/'), strtr((string) realpath("$dir/right.neon"), '\\', '/')], $resolved->use);
	Assert::same([2, 2], $resolved->decisions['blankLines.betweenMethods']->value->getCount());
	Assert::same([2, 2], $resolved->decisions['blankLines.afterImports']->value->getCount());
});


test('a file that comes back to itself is an error, and so is one that does not exist', function () {
	$dir = createDecisionsProject([
		'a.neon' => "use: b.neon\n",
		'b.neon' => "use: a.neon\n",
		'dresscode.neon' => "use: a.neon\n",
	]);
	Assert::exception(fn() => resolveFile("$dir/dresscode.neon"), ConfigurationException::class, 'Preset file `%a%/a.neon` uses itself through `%a%/b.neon`; a preset cannot come back to itself.');

	$dir = createDecisionsProject(['dresscode.neon' => "use: missing.neon\n"]);
	Assert::exception(fn() => Loader::loadFile("$dir/dresscode.neon"), ConfigurationException::class, 'Configuration file `%a%`: Preset file `missing.neon` in `use` does not exist.');
	Assert::exception(
		fn() => new ConfigResolver(new PluginRegistry, root: $dir)->resolve(new Config(overrides: [new DressCode\Override(['tests'], new DressCode\Profile(use: ['missing.neon']))]), '8.3'),
		ConfigurationException::class,
		'The override for `tests`: Preset file `missing.neon` in `use` does not exist.',
	);
});


test('a file a configuration in PHP uses is relative to the root, as one in NEON is', function () {
	$dir = createDecisionsProject([
		'shared.neon' => "blankLines:\n\tbetweenMethods: 3\n",
		'dresscode.php' => "<?php\nreturn new DressCode\\Config(use: ['shared.neon']);\n",
	]);
	$resolved = new ConfigResolver(new PluginRegistry, root: $dir)->resolve(Loader::loadFile("$dir/dresscode.php"), '8.3');
	Assert::same([3, 3], $resolved->decisions['blankLines.betweenMethods']->value->getCount());
	Assert::same([strtr((string) realpath("$dir/shared.neon"), '\\', '/')], $resolved->use);
});


test('a preset written as a file carries decisions, what it uses and the comments that silence a line, nothing the project decides', function () {
	$dir = createDecisionsProject(['base.neon' => "paths: [src]\n", 'dresscode.neon' => "use: base.neon\n"]);
	Assert::exception(fn() => resolveFile("$dir/dresscode.neon"), ConfigurationException::class, 'Preset file `%a%/base.neon` sets `paths`, which the project decides, not a preset.');
	$dir = createDecisionsProject(['base.neon' => "use: [[nette]]\n", 'dresscode.neon' => "use: base.neon\n"]);
	Assert::exception(fn() => resolveFile("$dir/dresscode.neon"), ConfigurationException::class, "Preset file `%a%/base.neon`: The item 'use%a%0' expects to be string|Nette\\Neon\\Entity, array given.");
	$dir = createDecisionsProject(['base.neon' => "suppressionComments:\n\t'~ok~': expressions.comparison.equality\n", 'dresscode.neon' => "use: base.neon\n"]);
	Assert::same(['~ok~' => ['expressions.comparison.equality']], resolveFile("$dir/dresscode.neon")->suppressionComments);
});


test('a key the catalogue does not know is named with the nearest known one, where it stands', function () {
	$dir = createDecisionsProject(['dresscode.neon' => "spacng:\n\tcall: \"foo()\"\n"]);
	Assert::exception(fn() => resolveFile("$dir/dresscode.neon"), ConfigurationException::class, 'Key `spacng` is unknown; write `spacing`.');

	$dir = createDecisionsProject(['base.neon' => "blankLines:\n\tbetweenMethod: 1\n", 'dresscode.neon' => "use: base.neon\n"]);
	Assert::exception(fn() => resolveFile("$dir/dresscode.neon"), ConfigurationException::class, 'Preset `%a%/base.neon`: Key `blankLines.betweenMethod` is unknown; write `blankLines.betweenMethods`.');
});


test('the run narrowed to a path reports that decision alone and changes no value', function () {
	$dir = createDecisionsProject(['dresscode.neon' => "use: nette\n"]);
	$narrowed = resolveFile("$dir/dresscode.neon", only: ['blankLines.betweenMethods']);
	Assert::true($narrowed->values->isSelected('blankLines.betweenMethods'));
	Assert::false($narrowed->values->isSelected('blankLines.afterImports'));
	Assert::same([1, 1], $narrowed->values->get('blankLines.afterImports')->getCount());
	Assert::true($narrowed->findRule(DressCode\Rules\Whitespace\BlankLinesRule::class)?->isActive());
	Assert::same(DressCode\Config\InactiveReason::Narrowed, $narrowed->findRule(DressCode\Rules\Whitespace\ConstructSpacingRule::class)?->inactiveReason);
});


test('the command line sets a decision over everything, and fixRisky and warnOnly take a path', function () {
	$dir = createDecisionsProject(['dresscode.neon' => "use: nette\nfixRisky: [types.declaration.parameter]\nwarnOnly: [blankLines]\ntypes:\n\tdeclaration:\n\t\tparameter: required\n"]);
	$resolved = resolveFile("$dir/dresscode.neon", new Profile(decisions: ['blankLines' => ['betweenMethods' => 3]]));
	Assert::same([3, 3], $resolved->decisions['blankLines.betweenMethods']->value->getCount());
	Assert::same('the command line', $resolved->decisions['blankLines.betweenMethods']->value->origin?->describe());
	Assert::true($resolved->findRule(DressCode\Rules\Types\NativeTypeRequiredRule::class)?->fixRisky);
	Assert::true($resolved->findRule(DressCode\Rules\Whitespace\BlankLinesRule::class)?->warnOnly);

	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$code = new Application($out, $err, cwd: $dir)->run(['dresscode', 'config', '--format', 'json', '--set', 'blankLines.betweenMethods=1-2']);
	rewind($out);
	Assert::same(0, $code);
	$data = json_decode((string) stream_get_contents($out), true);
	Assert::same(['value' => [1, 2], 'layer' => 'the command line', 'inactive' => null, 'selected' => true], $data['decisions']['blankLines.betweenMethods']);
	Assert::exception(fn() => new Config(decisions: ['paths' => []]), InvalidArgumentException::class, 'The decisions are sections, and `paths` is a key of the configuration.');
});
