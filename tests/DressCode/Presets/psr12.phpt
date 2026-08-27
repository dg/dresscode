<?php declare(strict_types=1);

/**
 * The PSR-12 preset is the PER preset without what the PER added; its fixtures are the PSR-12 examples.
 */

use DressCode\{Analyses, Config, RuleInfo, Style};
use DressCode\Config\{ConfigResolver, RuleBuilder, RuleRegistry};
use DressCode\Engine\FileProcessor;
use DressCode\Presets\{PerCs, Psr12};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$registry = new RuleRegistry;
$resolver = new ConfigResolver($registry);
$names = fn(string $preset) => array_map(
	fn($rule) => RuleInfo::of($rule)->name,
	RuleBuilder::buildRules($resolver->resolve(new Config(presets: [$preset]), Config::DefaultPhpVersion)),
);
$perOnly = [
	'dresscode/trailingComma',
	'dresscode/namedArgumentSpacing',
	'dresscode/multilineChain',
	'dresscode/concatSpacing',
	'dresscode/multilineTernary',
	'dresscode/semicolonSpacing',
	'dresscode/singleMemberPerLine',
	'dresscode/nowdocWithoutInterpolation',
	'dresscode/heredocIndentation',
	'dresscode/multilineArray',
	'dresscode/attributeSpacing',
	'dresscode/uselessAttributeParentheses',
	'dresscode/attributePosition',
	'dresscode/attributeAfterPhpdoc',
];
Assert::same(array_values(array_diff($names(PerCs::class), $perOnly)), $names(Psr12::class));
Assert::same($names(Psr12::class), $names('dresscode/psr12'));

$resolved = $resolver->resolve(new Config(presets: [Psr12::class]), Config::DefaultPhpVersion);
Assert::same(['    ', 'majority'], [$resolved->indent, $resolved->lineEnding]);
$rules = RuleBuilder::buildRules($resolved);
$processor = new FileProcessor($rules, new Analyses\Registry, $registry->resolveNames(...), Config::DefaultPhpVersion, new Style('    ', "\n", lineLength: $resolved->lineLength));

foreach (glob(__DIR__ . '/fixtures/psr12/*.code') ?: [] as $file) {
	$code = (string) file_get_contents($file);
	$target = (string) preg_replace('~\.code$~', '.expected', $file);
	$expected = is_file($target) ? (string) file_get_contents($target) : $code;
	$result = $processor->process(basename($file), $code);
	Assert::null($result->syntaxError, basename($file));
	Assert::null($result->failure, basename($file));
	Assert::same($expected, $result->output, basename($file));
	Assert::same($expected === $code, !$result->violations, basename($file));
}
