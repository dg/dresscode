<?php declare(strict_types=1);

/**
 * The PSR-12 preset is the PER preset without what the PER added; its fixtures are the PSR-12 examples.
 */

use DressCode\AnalysisRegistry;
use DressCode\Config;
use DressCode\Config\PresetResolver;
use DressCode\Config\RuleRegistry;
use DressCode\Engine\FileProcessor;
use DressCode\PresetContext;
use DressCode\Presets\Per;
use DressCode\Presets\Psr12;
use DressCode\RuleInfo;
use PhpSyntax\PhpVersion;
use PhpSyntax\Style;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$registry = new RuleRegistry;
$resolver = new PresetResolver($registry);
$names = fn(string $preset) => array_map(
	fn($rule) => RuleInfo::of($rule)->name,
	$resolver->resolve(Config::create()->preset($preset), new PresetContext(PhpVersion::lowest())),
);
$perOnly = [
	'dresscode/trailing-comma',
	'dresscode/named-argument-spacing',
	'dresscode/concat-spacing',
	'dresscode/semicolon-spacing',
	'dresscode/nowdoc-without-interpolation',
	'dresscode/heredoc-indentation',
	'dresscode/attribute-spacing',
	'dresscode/attribute-after-phpdoc',
];
Assert::same(array_values(array_diff($names(Per::class), $perOnly)), $names(Psr12::class));
Assert::same($names(Psr12::class), $names('dresscode/psr12'));

$config = Config::create()->preset(Psr12::class);
Assert::same(['    ', "\n"], $resolver->resolveStyle($config));
$rules = $resolver->resolve($config, new PresetContext(PhpVersion::lowest()));
$processor = new FileProcessor($rules, new AnalysisRegistry, $registry->resolveNames(...), PhpVersion::lowest(), new Style('    ', "\n"));

foreach (glob(__DIR__ . '/fixtures/psr12/*.code') ?: [] as $file) {
	$code = (string) file_get_contents($file);
	$target = (string) preg_replace('~\.code$~', '.expected', $file);
	$expected = is_file($target) ? (string) file_get_contents($target) : $code;
	$result = $processor->process(basename($file), $code);
	Assert::null($result->error, basename($file));
	Assert::same($expected, $result->output, basename($file));
	Assert::same($expected === $code, !$result->violations, basename($file));
}
