<?php declare(strict_types=1);

/**
 * The PER preset leaves the examples of the specification as they are and brings a dirty file to their shape.
 */

use DressCode\Config;
use DressCode\Config\{ConfigResolver, PluginRegistry, RuleBuilder};
use DressCode\Engine\{PhpFileProcessor, ReportPolicy};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$registry = new PluginRegistry;
$resolver = new ConfigResolver($registry);
$resolved = $resolver->resolve(new Config(use: ['perCs']), Config::DefaultPhpVersion);
$rules = RuleBuilder::buildRules($resolved);
// which rules run, not in what order
$slugs = array_map(fn($rule) => ruleSlug($rule), $rules);
sort($slugs);
$expected = [
	'multilineArray',
	'arraySyntax',
	'trailingComma',
	'classHeadSpacing',
	'multilineImplements',
	'nameCasing',
	'memberOrder',
	'privateForProtected',
	'noGroupedDeclarations',
	'visibilityRequired',
	'modifierOrder',
	'noBracelessBodies',
	'elseifNotation',
	'fallThroughComment',
	'multilineCondition',
	'multilineFor',
	'noEmptyStatements',
	'switchCaseNotation',
	'switchCaseSpacing',
	'binaryOperatorSpacing',
	'castCanonicalType',
	'operandSpacing',
	'emptyArgumentParentheses',
	'multilineBinaryOperator',
	'multilineMatchArm',
	'multilineChain',
	'multilineTernary',
	'ternaryOperatorSpacing',
	'declareSpacing',
	'finalLineEndings',
	'openingTagNotation',
	'lineEnding',
	'lineLength',
	'noBom',
	'noClosingTag',
	'noInvalidUtf8',
	'noTrailingWhitespace',
	'strictTypesRequired',
	'arrowFunctionForClosure',
	'functionNameSpacing',
	'multilineCall',
	'multilineSignature',
	'namedArgumentSpacing',
	'heredocIndentation',
	'builtinCasing',
	'nowdocForHeredoc',
	'declaredNameCasing',
	'importOrder',
	'uselessLeadingBackslash',
	'phpdocAboveAttributes',
	'typeDeclarationSpacing',
	'typeNotation',
	'attributePosition',
	'attributeSpacing',
	'statementBlankLines',
	'memberBlankLines',
	'blockBlankLines',
	'bracesPosition',
	'commaSpacing',
	'constructSpacing',
	'indentation',
	'delimiterSpacing',
	'semicolonSpacing',
	'noStatementsSharingLine',
];
sort($expected);
Assert::same($expected, $slugs);
Assert::same(['    ', null], [$resolved->indent, $resolved->lineEnding]);
$style = $resolved->createStyle();
$processor = new PhpFileProcessor(
	$rules,
	$resolved->createAnalyses($style),
	Config::DefaultPhpVersion,
	$style,
	policy: new ReportPolicy($registry->expandSuppressedName(...)),
);

foreach (glob(__DIR__ . '/fixtures/per/*.code') ?: [] as $file) {
	$code = (string) file_get_contents($file);
	$target = (string) preg_replace('~\.code$~', '.expected', $file);
	$expected = is_file($target) ? (string) file_get_contents($target) : $code;
	$result = $processor->process(basename($file), $code);
	Assert::null($result->syntaxError, basename($file));
	Assert::null($result->failure, basename($file));
	Assert::same($expected, $result->output, basename($file));
	Assert::same($expected === $code, !$result->violations, basename($file));
}
