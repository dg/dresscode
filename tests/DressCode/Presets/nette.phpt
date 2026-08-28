<?php declare(strict_types=1);

/**
 * The Nette preset is PER with tabs and the departures of the Nette Coding Standard: it holds every rule of
 * PER, its style is a tab and the prevailing line ending, and a file written the Nette way is left as it is.
 */

use DressCode\Config;
use DressCode\Config\{ConfigResolver, PluginRegistry, RuleBuilder};
use DressCode\Engine\{FileProcessor, ReportPolicy};
use PhpSyntax\Analyses\NamespacedSymbols;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$registry = new PluginRegistry;
$resolver = new ConfigResolver($registry);
$names = fn(string $preset) => array_map(
	fn($rule) => ruleSlug($rule),
	RuleBuilder::buildRules($resolver->resolve(new Config(use: [$preset]), Config::DefaultPhpVersion)),
);
Assert::same([], array_diff($names('perCs'), $names('nette')));
Assert::same($names('nette'), $names('dresscode/nette'));

$resolved = $resolver->resolve(new Config(use: ['nette']), Config::DefaultPhpVersion);
Assert::same(["\t", 'majority'], [$resolved->indent, $resolved->lineEnding]);
$rules = RuleBuilder::buildRules($resolved);
// the fixtures show what the standard rewrites, which in a namespace of uncertain resolution it would only report
$symbols = new NamespacedSymbols(complete: true);
$style = $resolved->createStyle();
$processor = new FileProcessor($rules, $resolved->createAnalyses($style, $symbols), Config::DefaultPhpVersion, $style, policy: new ReportPolicy($registry->expandSuppressedName(...), $resolved->suppressionComments));

foreach (glob(__DIR__ . '/fixtures/nette/*.code') ?: [] as $file) {
	$code = (string) file_get_contents($file);
	$target = (string) preg_replace('~\.code$~', '.expected', $file);
	$expected = is_file($target) ? (string) file_get_contents($target) : $code;
	$result = $processor->process(basename($file), $code);
	Assert::null($result->syntaxError, basename($file));
	Assert::null($result->failure, basename($file));
	Assert::same($expected, $result->output, basename($file));
	Assert::same($expected === $code, !$result->violations, basename($file));
}

// a class name does not repeat its kind, which the standard reports and leaves to the author
$result = $processor->process('kind.php', "<?php declare(strict_types=1);\n\ninterface FooInterface\n{\n}\n");
Assert::same(['naming.classKindInName'], array_map(fn($violation) => $violation->decision, $result->violations));
