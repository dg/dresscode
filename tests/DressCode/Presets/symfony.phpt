<?php declare(strict_types=1);

/**
 * The Symfony preset is PER, which the @Symfony rule set of PHP CS Fixer builds on, with the rules that set
 * adds; the rules that would break a construct over lines are off and a file written the Symfony way is left
 * as it is.
 */

use DressCode\Config;
use DressCode\Config\{ConfigResolver, PluginRegistry, RuleBuilder};
use DressCode\Engine\{PhpFileProcessor, ReportPolicy};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$registry = new PluginRegistry;
$resolver = new ConfigResolver($registry);
$resolved = $resolver->resolve(new Config(use: ['symfony']), Config::DefaultPhpVersion);
$rules = RuleBuilder::buildRules($resolved);
// which rules run, not in what order
$slugs = array_map(fn($rule) => ruleSlug($rule), $rules);
sort($slugs);
$expected = [
	'delimiterSpacing',
	'arraySyntax',
	'trailingComma',
	'classHeadSpacing',
	'nameCasing',
	'memberOrder',
	'privateForProtected',
	'noGroupedDeclarations',
	'uselessNullInitialization',
	'visibilityRequired',
	'modifierOrder',
	'commentSpacing',
	'noEmptyComments',
	'noHashComments',
	'noBracelessBodies',
	'elseifNotation',
	'fallThroughComment',
	'multilineCondition',
	'noAlternativeSyntax',
	'noContinueInSwitch',
	'noEmptyStatements',
	'switchCaseNotation',
	'switchCaseSpacing',
	'uselessBraces',
	'uselessParenthesesAfterConstruct',
	'uselessElse',
	'uselessReturn',
	'binaryOperatorSpacing',
	'castCanonicalType',
	'operandSpacing',
	'emptyArgumentParentheses',
	'increment',
	'multilineBinaryOperator',
	'noDoubleNegations',
	'notEqualsNotation',
	'memberAccessSpacing',
	'ternaryOperatorSpacing',
	'yoda',
	'declareSpacing',
	'finalLineEndings',
	'openingTagNotation',
	'lineEnding',
	'noBom',
	'noClosingTag',
	'noTrailingWhitespace',
	'strictTypesRequired',
	'arrowFunctionForClosure',
	'functionNameSpacing',
	'methodCasing',
	'namedArgumentSpacing',
	'noDollarBraceInterpolations',
	'builtinCasing',
	'noBacktickOperators',
	'stringQuotes',
	'importNotation',
	'declaredNameCasing',
	'importOrder',
	'globalNameQualification',
	'optimizedCallNotation',
	'uselessLeadingBackslash',
	'noUnusedImports',
	'uselessAlias',
	'phpdocBlankLines',
	'phpdocTypeNotation',
	'nullableTypeForDefaultNull',
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

foreach (glob(__DIR__ . '/fixtures/symfony/*.code') ?: [] as $file) {
	$code = (string) file_get_contents($file);
	$target = (string) preg_replace('~\.code$~', '.expected', $file);
	$expected = is_file($target) ? (string) file_get_contents($target) : $code;
	$result = $processor->process(basename($file), $code);
	Assert::null($result->syntaxError, basename($file));
	Assert::null($result->failure, basename($file));
	Assert::same($expected, $result->output, basename($file));
	Assert::same($expected === $code, !$result->violations, basename($file));
}
