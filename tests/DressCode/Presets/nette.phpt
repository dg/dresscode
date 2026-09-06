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
use DressCode\PresetContext;
use DressCode\Presets\Nette;
use DressCode\Presets\Per;
use DressCode\RuleInfo;
use PhpSyntax\Style;
use Tester\Assert;


require __DIR__ . '/../../bootstrap.php';


$registry = new RuleRegistry;
$resolver = new PresetResolver($registry);
$names = fn(string $preset) => array_map(
	fn($rule) => RuleInfo::of($rule)->name,
	$resolver->resolve(Config::create()->preset($preset), new PresetContext(Config::DefaultPhpVersion)),
);
Assert::same([], array_diff($names(Per::class), $names(Nette::class)));
Assert::same($names(Nette::class), $names('dresscode/nette'));

$config = Config::create()->preset(Nette::class);
Assert::same(["\t", 'majority'], $resolver->resolveStyle($config));
$rules = $resolver->resolve($config, new PresetContext(Config::DefaultPhpVersion));
$processor = new FileProcessor($rules, new Analyses\Registry, $registry->resolveNames(...), Config::DefaultPhpVersion, new Style("\t", 'auto'));

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
