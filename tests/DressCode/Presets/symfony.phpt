<?php declare(strict_types=1);

/**
 * The Symfony preset is PER, which the @Symfony rule set of PHP CS Fixer builds on, with the rules that set
 * adds; the rules that would break a construct over lines are off and a file written the Symfony way is left
 * as it is.
 */

use DressCode\{Analyses, Config, RuleInfo, Style};
use DressCode\Config\{ConfigResolver, RuleBuilder, RuleRegistry};
use DressCode\Engine\FileProcessor;
use DressCode\Presets\Symfony;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$registry = new RuleRegistry;
$resolver = new ConfigResolver($registry);
$resolved = $resolver->resolve(new Config(presets: [Symfony::class]), Config::DefaultPhpVersion);
$rules = RuleBuilder::buildRules($resolved);
Assert::same(
	[
		'dresscode/noBom',
		'dresscode/fullOpeningTag',
		'dresscode/lineEnding',
		'dresscode/eofLineEnding',
		'dresscode/noClosingTag',
		'dresscode/noTrailingWhitespace',
		'dresscode/singleStatementPerLine',
		'dresscode/indentation',
		'dresscode/keywordCasing',
		'dresscode/trueFalseNullCasing',
		'dresscode/castSpacing',
		'dresscode/castCanonicalType',
		'dresscode/nameCasing',
		'dresscode/orderedImports',
		'dresscode/uselessImportBackslash',
		'dresscode/declareSpacing',
		'dresscode/newArgumentParentheses',
		'dresscode/classDefinitionSpacing',
		'dresscode/bracesPosition',
		'dresscode/blankLines',
		'dresscode/orderedMembers',
		'dresscode/visibilityRequired',
		'dresscode/singleMemberPerDeclaration',
		'dresscode/functionNameSpacing',
		'dresscode/parenthesesSpacing',
		'dresscode/commaSpacing',
		'dresscode/typeHintSpacing',
		'dresscode/referenceSpacing',
		'dresscode/spreadOperatorSpacing',
		'dresscode/constructSpacing',
		'dresscode/controlStructureBraces',
		'dresscode/elseifKeyword',
		'dresscode/switchCaseColon',
		'dresscode/switchCaseSpacing',
		'dresscode/fallThroughComment',
		'dresscode/unaryOperatorSpacing',
		'dresscode/binaryOperatorSpacing',
		'dresscode/ternaryOperatorSpacing',
		'dresscode/shortArraySyntax',
		'dresscode/trailingComma',
		'dresscode/namedArgumentSpacing',
		'dresscode/concatSpacing',
		'dresscode/semicolonSpacing',
		'dresscode/singleMemberPerLine',
		'dresscode/attributeSpacing',
		'dresscode/uselessAttributeParentheses',
		'dresscode/attributePosition',
		'dresscode/attributeAfterPhpdoc',
		'dresscode/nativeClassCasing',
		'dresscode/magicConstantCasing',
		'dresscode/nativeFunctionCasing',
		'dresscode/importNotation',
		'dresscode/nameNotation',
		'dresscode/unusedImports',
		'dresscode/commentSpacing',
		'dresscode/noEmptyComments',
		'dresscode/noHashComments',
		'dresscode/noEmptyPhpdocs',
		'dresscode/phpdocCanonicalTypes',
		'dresscode/phpdocTrim',
		'dresscode/arraySpacing',
		'dresscode/objectOperatorSpacing',
		'dresscode/offsetBracketSpacing',
		'dresscode/incrementForAddOne',
		'dresscode/notEqualsNotation',
		'dresscode/noShortBoolCasts',
		'dresscode/stringQuotes',
		'dresscode/complexStringVariable',
		'dresscode/noBacktickOperators',
		'dresscode/noAlternativeSyntax',
		'dresscode/noContinueInSwitch',
		'dresscode/noEmptyStatements',
		'dresscode/uselessBraces',
		'dresscode/uselessConstructParentheses',
		'dresscode/uselessElse',
		'dresscode/uselessReturn',
		'dresscode/uselessNullInitialization',
		'dresscode/nullableTypeForDefaultNull',
	],
	array_map(fn($rule) => RuleInfo::of($rule)->name, $rules),
);
Assert::same(['    ', 'majority'], [$resolved->indent, $resolved->lineEnding]);

$processor = new FileProcessor($rules, new Analyses\Registry, $registry->resolveNames(...), Config::DefaultPhpVersion, new Style('    ', "\n", lineLength: $resolved->lineLength));

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
