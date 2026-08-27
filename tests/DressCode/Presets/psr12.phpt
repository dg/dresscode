<?php declare(strict_types=1);

/**
 * The PSR-12 preset is the PER preset without what the PER added; its fixtures are the PSR-12 examples.
 */

use DressCode\Config;
use DressCode\Config\{ConfigResolver, PluginRegistry, RuleBuilder};
use DressCode\Engine\{FileProcessor, ReportPolicy};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$registry = new PluginRegistry;
$resolver = new ConfigResolver($registry);
$names = fn(string $preset) => array_map(
	fn($rule) => ruleSlug($rule),
	RuleBuilder::buildRules($resolver->resolve(new Config(use: [$preset]), Config::DefaultPhpVersion)),
);
$perOnly = [
	'typeNotation',
	'trailingComma',
	'namedArgumentSpacing',
	'multilineChain',
	'multilineTernary',
	'semicolonSpacing',
	'noMembersSharingLine',
	'nowdocForHeredoc',
	'heredocIndentation',
	'noLongArraySyntax',
	'multilineArray',
	'attributeSpacing',
	'attributePosition',
	'phpdocAboveAttributes',
];
Assert::same(array_values(array_diff($names('perCs'), $perOnly)), $names('psr12'));
Assert::same($names('psr12'), $names('dresscode/psr12'));

$resolved = $resolver->resolve(new Config(use: ['psr12']), Config::DefaultPhpVersion);
Assert::same(['    ', 'majority'], [$resolved->indent, $resolved->lineEnding]);
$rules = RuleBuilder::buildRules($resolved);
$style = $resolved->createStyle();
$processor = new FileProcessor($rules, $resolved->createAnalyses($style), Config::DefaultPhpVersion, $style, policy: new ReportPolicy($registry->expandSuppressedName(...)));

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
