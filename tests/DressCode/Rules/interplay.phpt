<?php declare(strict_types=1);

/**
 * Pairs of rules that pull at the same tokens in opposite directions: one adds what the other removes.
 * Each pair must converge, and its result must be the one the pair is meant to give. A pair where one rule
 * only makes work for the other belongs here too: the violation of the second must follow the first.
 */

use DressCode\{Analyses, Config, FileResult, Rules, Style, Violation};
use DressCode\Config\{RuleBuilder, RuleRegistry};
use DressCode\Engine\FileProcessor;
use PhpSyntax\Analyses\NamespacedSymbols;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** @param array<class-string<DressCode\Rule>, true|array<string, mixed>> $rules */
function interplay(
	array $rules,
	string $code,
	?string $expected = null,
	Analyses\Registry $analyses = new Analyses\Registry,
): FileResult
{
	$registry = new RuleRegistry;
	$instances = [];
	foreach ($rules as $class => $options) {
		$instances[] = RuleBuilder::createRule($class, $options);
	}

	$processor = new FileProcessor($instances, $analyses, $registry->resolveNames(...), Config::DefaultPhpVersion, new Style("\t", "\n"));
	$result = $processor->process('interplay.php', $code);
	Assert::null($result->syntaxError);
	Assert::same($expected ?? $code, $result->output);
	$again = $processor->process('interplay.php', (string) $result->output);
	Assert::same($result->output, $again->output, 'the result is not stable');
	return $result;
}


test('the areas of blankLines never pull against one another', function () {
	// the first statement of a block gets no blank line before it: that gap belongs to the brace
	interplay([
		Rules\Whitespace\BlankLinesRule::class => ['afterOpeningTag' => 'keep'],
	], "<?php\nfunction f()\n{\n\n\treturn 1;\n}\n", "<?php\nfunction f()\n{\n\treturn 1;\n}\n");

	// a declaration nested in a body is a declaration, not a statement of a kind
	interplay([
		Rules\Whitespace\BlankLinesRule::class => ['afterOpeningTag' => 'keep', 'after' => ['if' => 1]],
	], "<?php\nfunction f()\n{\n\tif (\$x) {\n\t}\n\tfunction g()\n\t{\n\t}\n}\n", "<?php\nfunction f()\n{\n\tif (\$x) {\n\t}\n\n\n\tfunction g()\n\t{\n\t}\n}\n");

	// the statement after the imports is the header's business, whatever its kind asks for
	interplay([
		Rules\Whitespace\BlankLinesRule::class => ['before' => ['if' => 0]],
	], "<?php\n\nuse A;\n\nif (\$x) {\n}\n");

	// and so is a function after them, where the header and the declarations meet
	interplay([
		Rules\Whitespace\BlankLinesRule::class => true,
	], "<?php\n\nuse A;\n\n\nfunction f()\n{\n}\n", "<?php\n\nuse A;\n\nfunction f()\n{\n}\n");
});


test('explicitOperatorPrecedence adds what uselessConstructParentheses does not remove', function () {
	interplay([
		Rules\Expressions\ExplicitOperatorPrecedenceRule::class => true,
		Rules\ControlFlow\UselessConstructParenthesesRule::class => true,
	], "<?php\nreturn \$a && \$b || \$c;\necho (\$a);\n", "<?php\nreturn (\$a && \$b) || \$c;\necho \$a;\n");
});


test('explicitOperatorPrecedence and symbolicLogicalOperators agree on and/or', function () {
	interplay([
		Rules\Expressions\ExplicitOperatorPrecedenceRule::class => true,
		Rules\Expressions\SymbolicLogicalOperatorsRule::class => true,
	], "<?php\nif (\$b and \$c or \$d) {\n}\n", "<?php\nif ((\$b && \$c) || \$d) {\n}\n");
});


test('indentation and multilineCall settle on one shape', function () {
	interplay([
		Rules\Whitespace\IndentationRule::class => true,
		Rules\Functions\MultilineCallRule::class => true,
	], "<?php\nfunction f()\n{\n  \$a = \$foo\n  ->bar(\n    1,\n      2,\n    )\n        ->baz();\n}\n", "<?php\nfunction f()\n{\n\t\$a = \$foo\n\t\t->bar(\n\t\t\t1,\n\t\t\t2,\n\t\t)\n\t\t->baz();\n}\n");
});


test('a comma asked for only because another rule spread the array follows that rule', function () {
	$result = interplay([
		Rules\Arrays\MultilineArrayRule::class => ['maxWidth' => 30],
		Rules\Arrays\TrailingCommaRule::class => true,
	], "<?php\n\$a = ['alpha' => 1, 'beta' => 2, 'gamma' => 3];\n", "<?php\n\$a = [\n\t'alpha' => 1,\n\t'beta' => 2,\n\t'gamma' => 3,\n];\n");
	Assert::count(2, $result->violations);
	$byRule = array_column($result->violations, null, 'ruleName');
	Assert::null($byRule['dresscode/multilineArray']->derivedFrom);
	Assert::same($byRule['dresscode/multilineArray']->fingerprint, $byRule['dresscode/trailingComma']->derivedFrom);

	// the same holds for the arguments of a call, the report standing on the closing bracket whatever the list is
	$result = interplay([
		Rules\Functions\MultilineCallRule::class => true,
		Rules\Arrays\TrailingCommaRule::class => ['argument' => 'required'],
	], "<?php\nfoo(\n\t1, 2);\n", "<?php\nfoo(\n\t1,\n\t2,\n);\n");
	Assert::count(2, $result->violations);
	$byRule = array_column($result->violations, null, 'ruleName');
	Assert::null($byRule['dresscode/multilineCall']->derivedFrom);
	Assert::same($byRule['dresscode/multilineCall']->fingerprint, $byRule['dresscode/trailingComma']->derivedFrom);

	// an array the file itself spread owes the comma to nobody
	$result = interplay([
		Rules\Arrays\MultilineArrayRule::class => true,
		Rules\Arrays\TrailingCommaRule::class => true,
	], "<?php\n\$a = [\n\t'alpha' => 1,\n\t'beta' => 2\n];\n", "<?php\n\$a = [\n\t'alpha' => 1,\n\t'beta' => 2,\n];\n");
	Assert::same([null], array_map(fn(Violation $violation) => $violation->derivedFrom, $result->violations));

	// and the comma claims nothing about the line of the bracket, which the lines counted from it are placed by
	$result = interplay([
		Rules\Arrays\TrailingCommaRule::class => true,
		Rules\Whitespace\IndentationRule::class => true,
	], "<?php\n\$a = [\n\t1,\n\t2\n] + [\n\t\t3,\n];\n", "<?php\n\$a = [\n\t1,\n\t2,\n] + [\n\t3,\n];\n");
	Assert::same([null, null], array_map(fn(Violation $violation) => $violation->derivedFrom, $result->violations));
});


test('uselessModifier and visibilityRequired settle on one shape', function () {
	interplay([
		Rules\Classes\UselessModifierRule::class => true,
		Rules\Classes\VisibilityRequiredRule::class => true,
	], "<?php\nfinal class A\n{\n\tfinal function a() {}\n\tstatic final public function b() {}\n}\n", "<?php\nfinal class A\n{\n\tpublic function a() {}\n\tpublic static function b() {}\n}\n");
});


test('importNotation combines what orderedImports then sorts', function () {
	interplay([
		Rules\Namespaces\ImportNotationRule::class => ['function' => 'combined'],
		Rules\Namespaces\OrderedImportsRule::class => true,
	], "<?php\nnamespace A;\nuse function b;\nuse function a;\nuse D, C;\n", "<?php\nnamespace A;\nuse C;\nuse D;\nuse function a, b;\n");
});


test('an import nameFallback adds takes the shape importNotation gives it, so that nothing else is reported', function () {
	$rules = fn(string $shape) => [
		Rules\Namespaces\NameFallbackRule::class => ['optimizedFunction' => 'qualified'],
		Rules\Namespaces\ImportNotationRule::class => ['function' => $shape],
	];
	$certain = new Analyses\Registry(new NamespacedSymbols(complete: true));
	$reported = fn(FileResult $result) => array_values(array_unique(array_map(fn(Violation $violation) => $violation->ruleName, $result->violations)));

	// one import of the kind fits either shape, so the shape comes from the rule
	$code = "<?php\nnamespace A;\n\nuse function count;\n\ncount(\$a);\nstrlen(\$b);\n";
	$result = interplay($rules('combined'), $code, "<?php\nnamespace A;\n\nuse function count, strlen;\n\ncount(\$a);\nstrlen(\$b);\n", $certain);
	Assert::same(['dresscode/nameFallback'], $reported($result));
	$result = interplay($rules('single'), $code, "<?php\nnamespace A;\n\nuse function count;\nuse function strlen;\n\ncount(\$a);\nstrlen(\$b);\n", $certain);
	Assert::same(['dresscode/nameFallback'], $reported($result));

	// and two imports the rule adds itself go into one statement
	$result = interplay($rules('combined'), "<?php\nnamespace A;\n\nstrlen(\$a);\ncount(\$b);\n", "<?php\nnamespace A;\n\nuse function count, strlen;\n\nstrlen(\$a);\ncount(\$b);\n", $certain);
	Assert::same(['dresscode/nameFallback'], $reported($result));
});


test('nameFallback qualifies a name in the shape nameNotation gives it, and nameNotation leaves a name nameFallback wants bare', function () {
	$certain = new Analyses\Registry(new NamespacedSymbols(complete: true));
	interplay([
		Rules\Namespaces\NameFallbackRule::class => ['optimizedFunction' => 'qualified'],
		Rules\Namespaces\NameNotationRule::class => ['globalFunction' => 'backslash'],
	], "<?php\nnamespace A;\n\nstrlen(\$a);\n", "<?php\nnamespace A;\n\n\\strlen(\$a);\n", $certain);

	interplay([
		Rules\Namespaces\NameFallbackRule::class => ['function' => 'fallback'],
		Rules\Namespaces\NameNotationRule::class => ['globalFunction' => 'import'],
	], "<?php\nnamespace A;\n\n\\implode(',', \$a);\n", "<?php\nnamespace A;\n\nimplode(',', \$a);\n", $certain);

	// a name the namespace declares cannot stand bare, so its shape stays nameNotation's
	interplay([
		Rules\Namespaces\NameFallbackRule::class => ['function' => 'fallback'],
		Rules\Namespaces\NameNotationRule::class => ['globalFunction' => 'backslash'],
	], "<?php\nnamespace A;\n\nuse function strlen;\n\nstrlen(\$a);\n", "<?php\nnamespace A;\n\n\n\\strlen(\$a);\n", new Analyses\Registry(new NamespacedSymbols(['A\strlen'], [], true)));
});


test('an import a bare name would be taken over by is reported, and added once nameFallback qualifies that name', function () {
	$certain = new Analyses\Registry(new NamespacedSymbols(complete: true));
	$code = "<?php\nnamespace A;\n\n\\strlen(\$a);\nstrlen(\$b);\n";
	$result = interplay([Rules\Namespaces\NameNotationRule::class => ['globalFunction' => 'import']], $code, null, $certain);
	Assert::same(['Global function `strlen()` must be imported'], array_map(fn(Violation $violation) => $violation->message, $result->violations));

	// the import nameFallback adds for the bare name is in place for the other one by the next pass
	interplay([
		Rules\Namespaces\NameFallbackRule::class => ['optimizedFunction' => 'qualified'],
		Rules\Namespaces\NameNotationRule::class => ['globalFunction' => 'import'],
	], $code, "<?php\nnamespace A;\n\nuse function strlen;\n\nstrlen(\$a);\nstrlen(\$b);\n", $certain);

	// only a call PHP optimizes with its arguments is nameFallback's to qualify, any other leaves the import reported
	$rules = [
		Rules\Namespaces\NameFallbackRule::class => ['optimizedFunction' => 'qualified'],
		Rules\Namespaces\NameNotationRule::class => ['globalFunction' => 'import'],
	];
	interplay($rules, "<?php\nnamespace A;\n\n\\dirname(\$a);\ndirname(__DIR__);\n", "<?php\nnamespace A;\n\nuse function dirname;\n\ndirname(\$a);\ndirname(__DIR__);\n", $certain);
	$result = interplay($rules, "<?php\nnamespace A;\n\n\\dirname(\$a);\ndirname(\$b);\n", null, $certain);
	Assert::same(
		['dresscode/nameNotation: Global function `dirname()` must be imported'],
		array_map(fn(Violation $violation) => "$violation->ruleName: $violation->message", $result->violations),
	);
});


test('an attribute written instead of an annotation stands where attributePosition wants it', function () {
	$rules = [
		Rules\Upgrading\AttributeForAnnotationRule::class => ['persistent' => 'Acme\Persistent'],
		Rules\Whitespace\AttributePositionRule::class => true,
	];
	interplay($rules, "<?php\nclass P\n{\n\t/** @persistent */\n\tpublic \$lang;\n}\n", "<?php\n\nuse Acme\\Persistent;\n\nclass P\n{\n\t#[Persistent]\n\tpublic \$lang;\n}\n");
	interplay($rules, "<?php\nclass P\n{\n\t/** @persistent */\n\t#[Other]\n\tpublic \$lang;\n}\n", "<?php\n\nuse Acme\\Persistent;\n\nclass P\n{\n\t#[Other]\n\t#[Persistent]\n\tpublic \$lang;\n}\n");
});


test('an import nameNotation asks for and the markup leaves no line for is reported once, not written with the backslash', function () {
	$result = interplay([
		Rules\Namespaces\NameFallbackRule::class => ['optimizedFunction' => 'qualified'],
		Rules\Namespaces\NameNotationRule::class => ['globalFunction' => 'import'],
	], "<?php\nnamespace A ?>\n<html>\n<?php echo strlen('a'); ?>\n", null, new Analyses\Registry(new NamespacedSymbols(complete: true)));
	Assert::same(
		['dresscode/nameFallback: Global function `strlen()` must be imported'],
		array_map(fn(Violation $violation) => "$violation->ruleName: $violation->message", $result->violations),
	);
});
