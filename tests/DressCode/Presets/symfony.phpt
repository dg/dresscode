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
// which rules run, not in what order
$names = function (string $preset) use ($resolver): array {
	$names = array_map(
		fn($rule) => ruleSlug($rule),
		RuleBuilder::buildRules($resolver->resolve(new Config(use: [$preset]), Config::DefaultPhpVersion)),
	);
	sort($names);
	return $names;
};
$symfonyAdds = [
	'uselessNullInitialization',
	'commentSpacing',
	'noEmptyComments',
	'noHashComments',
	'noAlternativeSyntax',
	'noContinueInSwitch',
	'uselessBraces',
	'uselessElse',
	'uselessReturn',
	'increment',
	'memberAccessSpacing',
	'noBacktickOperators',
	'noDoubleNegations',
	'notEqualsNotation',
	'uselessParenthesesAfterConstruct',
	'yoda',
	'methodCasing',
	'noDollarBraceInterpolations',
	'stringQuotes',
	'globalNameQualification',
	'importNotation',
	'noUnusedImports',
	'optimizedCallNotation',
	'uselessAlias',
	'phpdocBlankLines',
	'phpdocTypeNotation',
	'nullableTypeForDefaultNull',
];
// what breaks a construct over lines, which Symfony leaves to the author, and what its rule set does not have
$symfonyOff = [
	'multilineArray',
	'multilineImplements',
	'multilineFor',
	'multilineChain',
	'multilineMatchArm',
	'multilineTernary',
	'lineLength',
	'multilineCall',
	'multilineSignature',
	'heredocIndentation',
	'nowdocForHeredoc',
	'phpdocAboveAttributes',
];
$expected = [...array_diff($names('perCs'), $symfonyOff), ...$symfonyAdds];
sort($expected);
Assert::same($expected, $names('symfony'));

$resolved = $resolver->resolve(new Config(use: ['symfony']), Config::DefaultPhpVersion);
$rules = RuleBuilder::buildRules($resolved);
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
