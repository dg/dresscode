<?php declare(strict_types=1);

/**
 * Pairs of rules that pull at the same tokens in opposite directions: one adds what the other removes.
 * Each pair must converge, and its result must be the one the pair is meant to give. A pair where one rule
 * only makes work for the other belongs here too: the violation of the second must follow the first.
 */

use DressCode\{Analyses, Config, FileResult, Rules, Style, Violation};
use DressCode\Config\{PluginRegistry, RuleBuilder};
use DressCode\Engine\{FileProcessor, ReportPolicy};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** @param array<class-string<DressCode\Rule>, true|array<string, mixed>> $rules  the values of the decisions of each rule */
function interplay(
	array $rules,
	string $code,
	?string $expected = null,
	Analyses\Registry $analyses = new Analyses\Registry,
): FileResult
{
	$registry = new PluginRegistry;
	$values = [];
	foreach ($rules as $class => $value) {
		$value = $value === true ? [] : $value;
		$values = [...$values, ...$value];
	}

	$resolved = RuleBuilder::resolveValues(array_keys($rules), $values);
	$instances = RuleBuilder::createRules(array_keys($rules), $resolved);
	$style = new Style("\t", "\n");
	$analyses->register(Analyses\IndentationPlan::class, Analyses\IndentationPlan::createFactory($resolved, $style));

	$processor = new FileProcessor($instances, $analyses, Config::DefaultPhpVersion, $style, policy: new ReportPolicy($registry->expandSuppressedName(...)));
	$result = $processor->process('interplay.php', $code);
	Assert::null($result->syntaxError);
	Assert::same($expected ?? $code, $result->output);
	$again = $processor->process('interplay.php', (string) $result->output);
	Assert::same($result->output, $again->output, 'the result is not stable');
	return $result;
}


/**
 * The decisions of blankLines with the counts most standards give them, under the values given.
 * @param  array<string, mixed>  $values
 * @return array<string, mixed>
 */
function blankLinesValues(array $values): array
{
	return $values + [
		'blankLines.afterOpeningTag' => 1, 'blankLines.beforeNamespace' => 1, 'blankLines.afterNamespace' => 1,
		'blankLines.afterImports' => 1, 'blankLines.betweenImportKinds' => 1, 'blankLines.betweenDeclarations' => 2,
		'blankLines.betweenMethods' => 2, 'blankLines.betweenInterfaceMethods' => 1, 'blankLines.beforeFirstMethod' => 0,
		'blankLines.afterLastMethod' => 0, 'blankLines.beforeFirstMember' => 0, 'blankLines.afterLastMember' => 0,
		'blankLines.betweenTraitUses' => 0, 'blankLines.afterTraitUses' => 1, 'blankLines.betweenMembers' => [0, 1],
		'blankLines.beforeDocumentedMember' => 1, 'blankLines.afterPhpdoc' => 0, 'blankLines.afterBlockOpeningBrace' => 0,
		'blankLines.beforeStatement' => ['return' => [1, null]],
	];
}


test('the areas of blankLines never pull against one another', function () {
	// the first statement of a block gets no blank line before it: that gap belongs to the brace
	interplay([
		Rules\Whitespace\BlankLinesRule::class => blankLinesValues(['blankLines.afterOpeningTag' => 'keep']),
	], "<?php\nfunction f()\n{\n\n\treturn 1;\n}\n", "<?php\nfunction f()\n{\n\treturn 1;\n}\n");

	// a declaration nested in a body is a declaration, not a statement of a kind
	interplay([
		Rules\Whitespace\BlankLinesRule::class => blankLinesValues(['blankLines.afterOpeningTag' => 'keep', 'blankLines.afterStatement' => ['if' => 1]]),
	], "<?php\nfunction f()\n{\n\tif (\$x) {\n\t}\n\tfunction g()\n\t{\n\t}\n}\n", "<?php\nfunction f()\n{\n\tif (\$x) {\n\t}\n\n\n\tfunction g()\n\t{\n\t}\n}\n");

	// the statement after the imports is the header's business, whatever its kind asks for
	interplay([
		Rules\Whitespace\BlankLinesRule::class => blankLinesValues(['blankLines.beforeStatement' => ['if' => 0]]),
	], "<?php\n\nuse A;\n\nif (\$x) {\n}\n");

	// and so is a function after them, where the header and the declarations meet
	interplay([
		Rules\Whitespace\BlankLinesRule::class => blankLinesValues([]),
	], "<?php\n\nuse A;\n\n\nfunction f()\n{\n}\n", "<?php\n\nuse A;\n\nfunction f()\n{\n}\n");
});


test('indentation and multilineCall settle on one shape', function () {
	interplay([
		Rules\Whitespace\IndentationRule::class => [
			'indentation.unit' => 'tab', 'indentation.binaryOperator' => 0, 'indentation.ternary' => 1,
			'indentation.ternaryBelowCondition' => 'aligned', 'indentation.switchCase' => 1, 'indentation.chain' => 'flat',
		],
		Rules\Functions\MultilineCallRule::class => ['multiline.call' => 'perLine'],
	], "<?php\nfunction f()\n{\n  \$a = \$foo\n  ->bar(\n    1,\n      2,\n    )\n        ->baz();\n}\n", "<?php\nfunction f()\n{\n\t\$a = \$foo\n\t\t->bar(\n\t\t\t1,\n\t\t\t2,\n\t\t)\n\t\t->baz();\n}\n");
});


test('a comment commentSpacing rewrites keeps its line for the rule that reports it next', function () {
	$result = interplay([
		Rules\Comments\CommentSpacingRule::class => ['spacing.comment' => 'spaced'],
		Rules\Comments\NoHashCommentsRule::class => true,
	], "<?php\n\$a = 1;\n#foo\n\$b = 2;\n", "<?php\n\$a = 1;\n// foo\n\$b = 2;\n");
	Assert::same([3, 3], array_map(fn(Violation $violation) => $violation->line, $result->violations));
});
