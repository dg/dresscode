<?php declare(strict_types=1);

/**
 * The PER preset leaves the examples of the specification as they are and brings a dirty file to their shape.
 */

use DressCode\AnalysisRegistry;
use DressCode\Config;
use DressCode\Config\PresetResolver;
use DressCode\Config\RuleRegistry;
use DressCode\Engine\FileProcessor;
use DressCode\PresetContext;
use DressCode\Presets\Per;
use DressCode\RuleInfo;
use PhpSyntax\PhpVersion;
use PhpSyntax\Style;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$registry = new RuleRegistry;
$resolver = new PresetResolver($registry);
$config = Config::create()->preset(Per::class);
$rules = $resolver->resolve($config, new PresetContext(PhpVersion::lowest()));
Assert::same(
	[
		'dresscode/no-byte-order-mark',
		'dresscode/full-opening-tag',
		'dresscode/line-ending',
		'dresscode/eof-newline',
		'dresscode/no-closing-tag',
		'dresscode/no-trailing-whitespace',
		'dresscode/single-statement-per-line',
		'dresscode/statement-indentation',
		'dresscode/keyword-casing',
		'dresscode/constant-casing',
		'dresscode/cast-spacing',
		'dresscode/cast-canonical-type',
		'dresscode/name-casing',
		'dresscode/header-blank-lines',
		'dresscode/ordered-imports',
		'dresscode/no-leading-backslash-in-import',
		'dresscode/declare-spacing',
		'dresscode/new-argument-parentheses',
		'dresscode/class-definition-spacing',
		'dresscode/braces-position',
		'dresscode/declaration-blank-lines',
		'dresscode/ordered-members',
		'dresscode/visibility-required',
		'dresscode/single-member-per-declaration',
		'dresscode/function-name-spacing',
		'dresscode/parentheses-spacing',
		'dresscode/comma-spacing',
		'dresscode/multi-line-signature',
		'dresscode/type-hint-spacing',
		'dresscode/reference-spacing',
		'dresscode/spread-operator-spacing',
		'dresscode/multi-line-call',
		'dresscode/construct-spacing',
		'dresscode/control-structure-braces',
		'dresscode/elseif-keyword',
		'dresscode/continuation-position',
		'dresscode/multi-line-condition',
		'dresscode/switch-case-colon',
		'dresscode/switch-case-spacing',
		'dresscode/fall-through-comment',
		'dresscode/unary-operator-spacing',
		'dresscode/binary-operator-spacing',
		'dresscode/ternary-operator-spacing',
		'dresscode/short-array-syntax',
		'dresscode/array-indentation',
		'dresscode/trailing-comma',
		'dresscode/named-argument-spacing',
		'dresscode/concat-spacing',
		'dresscode/semicolon-spacing',
		'dresscode/nowdoc-without-interpolation',
		'dresscode/heredoc-indentation',
		'dresscode/attribute-spacing',
		'dresscode/attribute-after-phpdoc',
	],
	array_map(fn($rule) => RuleInfo::of($rule)->name, $rules),
);
Assert::same(['    ', "\n"], $resolver->resolveStyle($config));
$processor = new FileProcessor($rules, new AnalysisRegistry, $registry->resolveNames(...), PhpVersion::lowest(), new Style('    ', "\n"));

foreach (glob(__DIR__ . '/fixtures/per/*.code') ?: [] as $file) {
	$code = (string) file_get_contents($file);
	$target = (string) preg_replace('~\.code$~', '.expected', $file);
	$expected = is_file($target) ? (string) file_get_contents($target) : $code;
	$result = $processor->process(basename($file), $code);
	Assert::null($result->error, basename($file));
	Assert::same($expected, $result->output, basename($file));
	Assert::same($expected === $code, !$result->violations, basename($file));
}
