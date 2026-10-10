<?php declare(strict_types=1);

/**
 * The Symfony preset is PER, which the @Symfony rule set of PHP CS Fixer builds on, with the rules that set
 * adds; the rules that would break a construct over lines are off and a file written the Symfony way is left
 * as it is.
 */

use DressCode\Config;
use DressCode\Config\{ConfigResolver, PluginRegistry, RuleBuilder};
use DressCode\Engine\{FileProcessor, ReportPolicy};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$registry = new PluginRegistry;
$resolver = new ConfigResolver($registry);
$resolved = $resolver->resolve(new Config(use: ['symfony']), Config::DefaultPhpVersion);
$rules = RuleBuilder::buildRules($resolved);
Assert::same(
	[
		'arraySpacing',
		'noLongArraySyntax',
		'trailingComma',
		'classHeadSpacing',
		'nameCasing',
		'memberOrder',
		'noGroupedDeclarations',
		'noMembersSharingLine',
		'uselessNullInitialization',
		'visibilityRequired',
		'modifierOrder',
		'commentSpacing',
		'noEmptyComments',
		'noHashComments',
		'noBracelessBodies',
		'elseifNotation',
		'fallThroughComment',
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
		'castSpacing',
		'concatenationSpacing',
		'emptyArgumentParentheses',
		'incrementForAddOne',
		'noDoubleNegations',
		'notEqualsNotation',
		'objectOperatorSpacing',
		'offsetBracketSpacing',
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
		'namedArgumentSpacing',
		'noDollarBraceInterpolations',
		'builtinCasing',
		'noBacktickOperators',
		'stringQuotes',
		'importNotation',
		'builtinNameCasing',
		'importOrder',
		'globalNameQualification',
		'optimizedCallNotation',
		'uselessLeadingBackslash',
		'noUnusedImports',
		'phpdocAboveAttributes',
		'noEmptyPhpdocs',
		'phpdocBlankLines',
		'phpdocTypeNotation',
		'nullableTypeForDefaultNull',
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
