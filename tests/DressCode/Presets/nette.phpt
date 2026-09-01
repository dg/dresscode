<?php declare(strict_types=1);

/**
 * The Nette preset is PER with tabs and the departures of the Nette Coding Standard: it holds every rule of
 * PER, its style is a tab and a line feed, and a file written the Nette way is left as it is.
 */

use DressCode\Analyses;
use DressCode\Config;
use DressCode\Config\PresetResolver;
use DressCode\Config\RuleRegistry;
use DressCode\Engine\FileProcessor;
use DressCode\Presets\Nette;
use DressCode\Presets\Per;
use DressCode\RuleInfo;
use DressCode\Style;
use PhpSyntax\Analyses\NamespacedSymbols;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$registry = new RuleRegistry;
$resolver = new PresetResolver($registry);
$names = fn(string $preset) => array_map(
	fn($rule) => RuleInfo::of($rule)->name,
	$resolver->build($resolver->resolve(new Config(presets: [$preset]), Config::DefaultPhpVersion)),
);
Assert::same([], array_diff($names(Per::class), $names(Nette::class)));
Assert::same($names(Nette::class), $names('dresscode/nette'));

$resolved = $resolver->resolve(new Config(presets: [Nette::class]), Config::DefaultPhpVersion);
Assert::same(["\t", 'majority'], [$resolved->indent, $resolved->eol]);
$rules = $resolver->build($resolved);
// the fixtures show what the standard rewrites, which in a namespace of uncertain resolution it would only report
$symbols = new NamespacedSymbols(complete: true);
$processor = new FileProcessor($rules, new Analyses\Registry($symbols), $registry->resolveNames(...), Config::DefaultPhpVersion, new Style("\t", 'auto', lineLength: $resolved->lineLength));

foreach (glob(__DIR__ . '/fixtures/nette/*.code') ?: [] as $file) {
	$code = (string) file_get_contents($file);
	$target = (string) preg_replace('~\.code$~', '.expected', $file);
	$expected = is_file($target) ? (string) file_get_contents($target) : $code;
	$result = $processor->process(basename($file), $code);
	Assert::null($result->error, basename($file));
	Assert::null($result->failure, basename($file));
	Assert::same($expected, $result->output, basename($file));
	Assert::same($expected === $code, !$result->violations, basename($file));
}

// a class name does not repeat its kind, which the standard reports and leaves to the author
$result = $processor->process('kind.php', "<?php declare(strict_types=1);\n\ninterface FooInterface\n{\n}\n");
Assert::same(['dresscode/kind-in-class-name'], array_map(fn($violation) => $violation->ruleName, $result->violations));
