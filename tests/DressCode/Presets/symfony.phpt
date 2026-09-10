<?php declare(strict_types=1);

/**
 * The Symfony preset is PER, which the @Symfony rule set of PHP CS Fixer builds on, with the rules that set
 * adds; the rules that would break a construct over lines are off and a file written the Symfony way is left
 * as it is.
 */

use DressCode\Analyses;
use DressCode\Config;
use DressCode\Config\PresetResolver;
use DressCode\Config\RuleRegistry;
use DressCode\Engine\FileProcessor;
use DressCode\PresetContext;
use DressCode\Presets\Symfony;
use DressCode\RuleInfo;
use PhpSyntax\Style;
use Tester\Assert;


require __DIR__ . '/../../bootstrap.php';


$registry = new RuleRegistry;
$resolver = new PresetResolver($registry);
$config = Config::create()->preset(Symfony::class);
$rules = $resolver->resolve($config, new PresetContext(Config::DefaultPhpVersion));
Assert::same(
	[
		'dresscode/no-bom',
		'dresscode/full-opening-tag',
		'dresscode/line-ending',
		'dresscode/eof-newline',
		'dresscode/no-closing-tag',
		'dresscode/no-trailing-whitespace',
		'dresscode/single-statement-per-line',
		'dresscode/indentation',
		'dresscode/keyword-casing',
		'dresscode/constant-casing',
		'dresscode/cast-spacing',
		'dresscode/cast-canonical-type',
		'dresscode/name-casing',
		'dresscode/ordered-imports',
		'dresscode/no-leading-backslash-in-import',
		'dresscode/declare-spacing',
		'dresscode/new-argument-parentheses',
		'dresscode/class-definition-spacing',
		'dresscode/braces-position',
		'dresscode/blank-lines',
		'dresscode/ordered-members',
		'dresscode/visibility-required',
		'dresscode/single-member-per-declaration',
		'dresscode/function-name-spacing',
		'dresscode/parentheses-spacing',
		'dresscode/comma-spacing',
		'dresscode/type-hint-spacing',
		'dresscode/reference-spacing',
		'dresscode/spread-operator-spacing',
		'dresscode/construct-spacing',
		'dresscode/control-structure-braces',
		'dresscode/elseif-keyword',
		'dresscode/switch-case-colon',
		'dresscode/switch-case-spacing',
		'dresscode/fall-through-comment',
		'dresscode/unary-operator-spacing',
		'dresscode/binary-operator-spacing',
		'dresscode/ternary-operator-spacing',
		'dresscode/short-array-syntax',
		'dresscode/trailing-comma',
		'dresscode/named-argument-spacing',
		'dresscode/concat-spacing',
		'dresscode/semicolon-spacing',
		'dresscode/single-member-per-line',
		'dresscode/attribute-spacing',
		'dresscode/useless-attribute-parentheses',
		'dresscode/attribute-position',
		'dresscode/attribute-after-phpdoc',
		'dresscode/class-reference-name-casing',
		'dresscode/magic-constant-casing',
		'dresscode/native-function-casing',
		'dresscode/import-notation',
		'dresscode/unused-imports',
		'dresscode/comment-spacing',
		'dresscode/no-empty-comment',
		'dresscode/no-hash-comment',
		'dresscode/no-empty-phpdoc',
		'dresscode/phpdoc-canonical-types',
		'dresscode/phpdoc-trim',
		'dresscode/array-spacing',
		'dresscode/object-operator-spacing',
		'dresscode/offset-bracket-spacing',
		'dresscode/increment-operator',
		'dresscode/not-equals-operator',
		'dresscode/no-short-bool-cast',
		'dresscode/single-quoted-strings',
		'dresscode/complex-string-variable',
		'dresscode/no-backtick-operator',
		'dresscode/no-alternative-syntax',
		'dresscode/no-continue-in-switch',
		'dresscode/no-empty-statement',
		'dresscode/useless-braces',
		'dresscode/useless-construct-parentheses',
		'dresscode/useless-else',
		'dresscode/useless-return',
		'dresscode/useless-null-property-initialization',
		'dresscode/nullable-type-for-default-null',
	],
	array_map(fn($rule) => RuleInfo::of($rule)->name, $rules),
);
Assert::same(['    ', 'majority'], $resolver->resolveStyle($config));

$processor = new FileProcessor($rules, new Analyses\Registry, $registry->resolveNames(...), Config::DefaultPhpVersion, new Style('    ', "\n"));

foreach (glob(__DIR__ . '/fixtures/symfony/*.code') ?: [] as $file) {
	$code = (string) file_get_contents($file);
	$target = (string) preg_replace('~\.code$~', '.expected', $file);
	$expected = is_file($target) ? (string) file_get_contents($target) : $code;
	$result = $processor->process(basename($file), $code);
	Assert::null($result->error, basename($file));
	Assert::null($result->failure, basename($file));
	Assert::same($expected, $result->output, basename($file));
	Assert::same($expected === $code, !$result->violations, basename($file));
}
