<?php declare(strict_types=1);

/**
 * The PER preset leaves the examples of the specification as they are and brings a dirty file to their shape.
 */

use DressCode\{Analyses, Config, RuleInfo, Style};
use DressCode\Config\{ConfigResolver, RuleBuilder, RuleRegistry};
use DressCode\Engine\FileProcessor;
use DressCode\Presets\PerCs;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$registry = new RuleRegistry;
$resolver = new ConfigResolver($registry);
$resolved = $resolver->resolve(new Config(presets: [PerCs::class]), Config::DefaultPhpVersion);
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
		'dresscode/multilineSignature',
		'dresscode/typeHintSpacing',
		'dresscode/referenceSpacing',
		'dresscode/spreadOperatorSpacing',
		'dresscode/multilineCall',
		'dresscode/constructSpacing',
		'dresscode/controlStructureBraces',
		'dresscode/elseifKeyword',
		'dresscode/multilineCondition',
		'dresscode/switchCaseColon',
		'dresscode/switchCaseSpacing',
		'dresscode/fallThroughComment',
		'dresscode/unaryOperatorSpacing',
		'dresscode/binaryOperatorSpacing',
		'dresscode/ternaryOperatorSpacing',
		'dresscode/shortArraySyntax',
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
	],
	array_map(fn($rule) => RuleInfo::of($rule)->name, $rules),
);
Assert::same(['    ', 'majority'], [$resolved->indent, $resolved->lineEnding]);
$processor = new FileProcessor($rules, new Analyses\Registry, $registry->resolveNames(...), Config::DefaultPhpVersion, new Style('    ', "\n", lineLength: $resolved->lineLength));

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
