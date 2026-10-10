<?php declare(strict_types=1);

/**
 * The PER preset leaves the examples of the specification as they are and brings a dirty file to their shape.
 */

use DressCode\Config;
use DressCode\Config\{ConfigResolver, PluginRegistry, RuleBuilder};
use DressCode\Engine\{FileProcessor, ReportPolicy};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$registry = new PluginRegistry;
$resolver = new ConfigResolver($registry);
$resolved = $resolver->resolve(new Config(use: ['perCs']), Config::DefaultPhpVersion);
$rules = RuleBuilder::buildRules($resolved);
Assert::same(
	[
		'multilineArray',
		'noLongArraySyntax',
		'trailingComma',
		'classHeadSpacing',
		'nameCasing',
		'memberOrder',
		'noGroupedDeclarations',
		'noMembersSharingLine',
		'visibilityRequired',
		'modifierOrder',
		'noBracelessBodies',
		'elseifNotation',
		'fallThroughComment',
		'multilineCondition',
		'noEmptyStatements',
		'switchCaseNotation',
		'switchCaseSpacing',
		'binaryOperatorSpacing',
		'castCanonicalType',
		'castSpacing',
		'concatenationSpacing',
		'emptyArgumentParentheses',
		'multilineChain',
		'multilineTernary',
		'referenceSpacing',
		'spreadOperatorSpacing',
		'ternaryOperatorSpacing',
		'unaryOperatorSpacing',
		'declareSpacing',
		'finalLineEndings',
		'openingTagNotation',
		'lineEnding',
		'noBom',
		'noClosingTag',
		'noTrailingWhitespace',
		'functionNameSpacing',
		'multilineCall',
		'multilineSignature',
		'namedArgumentSpacing',
		'heredocIndentation',
		'builtinCasing',
		'nowdocForHeredoc',
		'builtinNameCasing',
		'importOrder',
		'uselessLeadingBackslash',
		'phpdocAboveAttributes',
		'typeDeclarationSpacing',
		'typeNotation',
		'attributePosition',
		'attributeSpacing',
		'statementBlankLines',
		'memberBlankLines',
		'bracesPosition',
		'commaSpacing',
		'constructSpacing',
		'indentation',
		'parenthesesSpacing',
		'semicolonSpacing',
		'noStatementsSharingLine',
	],
	array_map(fn($rule) => ruleSlug($rule), $rules),
);
Assert::same(['    ', 'majority'], [$resolved->indent, $resolved->lineEnding]);
$style = $resolved->createStyle();
$processor = new FileProcessor($rules, $resolved->createAnalyses($style), Config::DefaultPhpVersion, $style, policy: new ReportPolicy($registry->expandSuppressedName(...)));

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
