<?php declare(strict_types=1);

/**
 * Pairs of rules that pull at the same tokens in opposite directions: one adds what the other removes.
 * Each pair must converge, and its result must be the one the pair is meant to give. A pair where one rule
 * only makes work for the other belongs here too: the violation of the second must follow the first.
 */

use DressCode\{Analyses, Config, FileResult, ImportStyle, Rules, Style, Violation};
use DressCode\Config\{PluginRegistry, RuleBuilder};
use DressCode\Engine\{FileProcessor, ReportPolicy};
use PhpSyntax\Analyses\NamespacedSymbols;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** @param array<class-string<DressCode\Rule>, true|array<string, mixed>> $rules  the values of the decisions of each rule, or the map of a rule of maps */
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
		$values = [...$values, ...defined("$class::Map") ? [$class::Map => $value] : $value];
	}

	$resolved = RuleBuilder::resolveValues(array_keys($rules), $values);
	$instances = RuleBuilder::createRules(array_keys($rules), $resolved);
	$style = new Style("\t", "\n", imports: ImportStyle::fromValues($resolved));
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
 * The three rules of blankLines with the counts most standards give them, under the values given.
 * @param  array<string, mixed>  $values
 * @return array<class-string<DressCode\Rule>, array<string, mixed>>
 */
function blankLinesRules(array $values): array
{
	return [
		Rules\Whitespace\StatementBlankLinesRule::class => blankLinesValues($values),
		Rules\Whitespace\MemberBlankLinesRule::class => [],
		Rules\Whitespace\BlockBlankLinesRule::class => [],
	];
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
	interplay(blankLinesRules(['blankLines.afterOpeningTag' => 'keep']), "<?php\nfunction f()\n{\n\n\treturn 1;\n}\n", "<?php\nfunction f()\n{\n\treturn 1;\n}\n");

	// a declaration nested in a body is a declaration, not a statement of a kind
	interplay(blankLinesRules(['blankLines.afterOpeningTag' => 'keep', 'blankLines.afterStatement' => ['if' => 1]]), "<?php\nfunction f()\n{\n\tif (\$x) {\n\t}\n\tfunction g()\n\t{\n\t}\n}\n", "<?php\nfunction f()\n{\n\tif (\$x) {\n\t}\n\n\n\tfunction g()\n\t{\n\t}\n}\n");

	// the statement after the imports is the header's business, whatever its kind asks for
	interplay(blankLinesRules(['blankLines.beforeStatement' => ['if' => 0]]), "<?php\n\nuse A;\n\nif (\$x) {\n}\n");

	// and so is a function after them, where the header and the declarations meet
	interplay(blankLinesRules([]), "<?php\n\nuse A;\n\n\nfunction f()\n{\n}\n", "<?php\n\nuse A;\n\nfunction f()\n{\n}\n");
});


test('explicitPrecedenceRequired adds what uselessParenthesesAfterConstruct does not remove', function () {
	interplay([
		Rules\Expressions\ExplicitPrecedenceRequiredRule::class => true,
		Rules\ControlFlow\UselessParenthesesAfterConstructRule::class => true,
	], "<?php\nreturn \$a && \$b || \$c;\necho (\$a);\n", "<?php\nreturn (\$a && \$b) || \$c;\necho \$a;\n");
});


test('explicitPrecedenceRequired and logicalOperatorNotation agree on and/or', function () {
	interplay([
		Rules\Expressions\ExplicitPrecedenceRequiredRule::class => true,
		Rules\Expressions\LogicalOperatorNotationRule::class => true,
	], "<?php\nif (\$b and \$c or \$d) {\n}\n", "<?php\nif ((\$b && \$c) || \$d) {\n}\n");
});


test('indentation and multilineCall settle on one shape', function () {
	interplay([
		Rules\Whitespace\IndentationRule::class => [
			'indentation.unit' => 'tab', 'indentation.binaryOperator' => 0, 'indentation.ternary.level' => 1,
			'indentation.ternary.belowCondition' => 'aligned', 'indentation.switchCase' => 1, 'indentation.chain' => 'flat',
		],
		Rules\Functions\MultilineCallRule::class => ['multiline.shape.call' => 'perLine'],
	], "<?php\nfunction f()\n{\n  \$a = \$foo\n  ->bar(\n    1,\n      2,\n    )\n        ->baz();\n}\n", "<?php\nfunction f()\n{\n\t\$a = \$foo\n\t\t->bar(\n\t\t\t1,\n\t\t\t2,\n\t\t)\n\t\t->baz();\n}\n");
});


test('a comma asked for only because another rule spread the array follows that rule', function () {
	$result = interplay([
		Rules\Arrays\MultilineArrayRule::class => ['multiline.shape.array' => 'perLine', 'multiline.split.array' => 30],
		Rules\Arrays\TrailingCommaRule::class => [
			'multiline.trailingComma.array' => 'required', 'multiline.trailingComma.argument' => 'optional',
			'multiline.trailingComma.parameter' => 'keep', 'multiline.trailingComma.matchArm' => 'keep',
			'multiline.trailingComma.closureUse' => 'keep', 'multiline.trailingComma.import' => 'optional',
			'multiline.trailingComma.list' => 'optional',
		],
	], "<?php\n\$a = ['alpha' => 1, 'beta' => 2, 'gamma' => 3];\n", "<?php\n\$a = [\n\t'alpha' => 1,\n\t'beta' => 2,\n\t'gamma' => 3,\n];\n");
	Assert::count(2, $result->violations);
	$byRule = array_column($result->violations, null, 'decision');
	Assert::null($byRule['multiline.shape.array']->derivedFrom);
	Assert::same($byRule['multiline.shape.array']->fingerprint, $byRule['multiline.trailingComma.array']->derivedFrom);

	// the same holds for the arguments of a call, the report standing on the closing bracket whatever the list is
	$result = interplay([
		Rules\Functions\MultilineCallRule::class => ['multiline.shape.call' => 'perLine'],
		Rules\Arrays\TrailingCommaRule::class => ['multiline.trailingComma.argument' => 'required'],
	], "<?php\nfoo(\n\t1, 2);\n", "<?php\nfoo(\n\t1,\n\t2,\n);\n");
	Assert::count(2, $result->violations);
	$byRule = array_column($result->violations, null, 'decision');
	Assert::null($byRule['multiline.shape.call']->derivedFrom);
	Assert::same($byRule['multiline.shape.call']->fingerprint, $byRule['multiline.trailingComma.argument']->derivedFrom);

	// an array the file itself spread owes the comma to nobody
	$result = interplay([
		Rules\Arrays\MultilineArrayRule::class => ['multiline.shape.array' => 'perLine', 'multiline.split.array' => 'none'],
		Rules\Arrays\TrailingCommaRule::class => [
			'multiline.trailingComma.array' => 'required', 'multiline.trailingComma.argument' => 'optional',
			'multiline.trailingComma.parameter' => 'keep', 'multiline.trailingComma.matchArm' => 'keep',
			'multiline.trailingComma.closureUse' => 'keep', 'multiline.trailingComma.import' => 'optional',
			'multiline.trailingComma.list' => 'optional',
		],
	], "<?php\n\$a = [\n\t'alpha' => 1,\n\t'beta' => 2\n];\n", "<?php\n\$a = [\n\t'alpha' => 1,\n\t'beta' => 2,\n];\n");
	Assert::same([null], array_map(fn(Violation $violation) => $violation->derivedFrom, $result->violations));

	// and the comma claims nothing about the line of the bracket, which the lines counted from it are placed by
	$result = interplay([
		Rules\Arrays\TrailingCommaRule::class => [
			'multiline.trailingComma.array' => 'required', 'multiline.trailingComma.argument' => 'optional',
			'multiline.trailingComma.parameter' => 'keep', 'multiline.trailingComma.matchArm' => 'keep',
			'multiline.trailingComma.closureUse' => 'keep', 'multiline.trailingComma.import' => 'optional',
			'multiline.trailingComma.list' => 'optional',
		],
		Rules\Whitespace\IndentationRule::class => [
			'indentation.unit' => 'tab', 'indentation.binaryOperator' => 0, 'indentation.ternary.level' => 1,
			'indentation.ternary.belowCondition' => 'aligned', 'indentation.switchCase' => 1, 'indentation.chain' => 'flat',
		],
	], "<?php\n\$a = [\n\t1,\n\t2\n] + [\n\t\t3,\n];\n", "<?php\n\$a = [\n\t1,\n\t2,\n] + [\n\t3,\n];\n");
	Assert::same([null, null], array_map(fn(Violation $violation) => $violation->derivedFrom, $result->violations));
});


test('a brace noAlternativeSyntax writes stands where constructSpacing wants it', function () {
	$result = interplay([
		Rules\ControlFlow\NoAlternativeSyntaxRule::class => true,
		Rules\Whitespace\ConstructSpacingRule::class => true,
	], "<?php\nforeach (\$a as \$b):\nendforeach;\nif (\$a):\nelse:\nendif;\nswitch (\$a):\nendswitch;\n", "<?php\nforeach (\$a as \$b) {\n}\nif (\$a) {\n} else {\n}\nswitch (\$a) {\n}\n");
	Assert::same(['braces.alternativeSyntax'], array_values(array_unique(array_map(fn(Violation $violation) => $violation->decision, $result->violations))));
});


test('a catch uselessCatchVariable leaves without its variable merges by noRepeatedCatches', function () {
	interplay([
		Rules\ControlFlow\UselessCatchVariableRule::class => ['cleanup.catchWithoutVariable' => 'required'],
		Rules\ControlFlow\NoRepeatedCatchesRule::class => ['cleanup.repeatedCatch' => 'forbidden'],
	], "<?php\ntry {\n\trun();\n} catch (A \$e) {\n\tfail();\n} catch (B) {\n\tfail();\n}\n", "<?php\ntry {\n\trun();\n} catch (A|B) {\n\tfail();\n}\n");
});


test('uselessModifier, visibilityRequired and modifierOrder settle on one shape', function () {
	interplay([
		Rules\Classes\UselessModifierRule::class => true,
		Rules\Classes\VisibilityRequiredRule::class => true,
		Rules\Classes\ModifierOrderRule::class => true,
	], "<?php\nfinal class A\n{\n\tfinal function a() {}\n\tstatic final public function b() {}\n}\n", "<?php\nfinal class A\n{\n\tpublic function a() {}\n\tpublic static function b() {}\n}\n");
});


test('publicWithSetVisibility and visibilityRequired agree that a set visibility is a visibility', function () {
	$code = "<?php\nclass A\n{\n\tstatic public private(set) int \$a = 0;\n\tprotected(set) int \$b = 0;\n\tvar \$c;\n}\n";
	$expected = [
		'required' => "<?php\nclass A\n{\n\tpublic private(set) static int \$a = 0;\n\tpublic protected(set) int \$b = 0;\n\tpublic \$c;\n}\n",
		'forbidden' => "<?php\nclass A\n{\n\tprivate(set) static int \$a = 0;\n\tprotected(set) int \$b = 0;\n\tpublic \$c;\n}\n",
	];
	foreach ($expected as $value => $output) {
		interplay([
			Rules\Classes\PublicWithSetVisibilityRule::class => ['classes.visibility.publicWithSet' => $value],
			Rules\Classes\VisibilityRequiredRule::class => true,
			Rules\Classes\ModifierOrderRule::class => true,
		], $code, $output);
	}
});


test('a comment commentSpacing rewrites keeps its line for the rule that reports it next', function () {
	$result = interplay([
		Rules\Comments\CommentSpacingRule::class => ['spacing.comment.marker' => 'spaced'],
		Rules\Comments\NoHashCommentsRule::class => true,
	], "<?php\n\$a = 1;\n#foo\n\$b = 2;\n", "<?php\n\$a = 1;\n// foo\n\$b = 2;\n");
	Assert::same([3, 3], array_map(fn(Violation $violation) => $violation->line, $result->violations));
});


test('importNotation combines what importOrder then sorts', function () {
	interplay([
		Rules\Namespaces\ImportNotationRule::class => ['imports.statement.class' => 'separate', 'imports.statement.function' => 'combined', 'imports.statement.constant' => 'separate'],
		Rules\Namespaces\ImportOrderRule::class => ['imports.order.withinKind' => 'alphabetical', 'imports.order.caseSensitive' => false],
	], "<?php\nnamespace A;\nuse function b;\nuse function a;\nuse D, C;\n", "<?php\nnamespace A;\nuse C;\nuse D;\nuse function a, b;\n");
});


test('an import the qualification adds takes the shape importNotation gives it, so that nothing else is reported', function () {
	$rules = fn(string $shape) => [
		Rules\Namespaces\GlobalNameQualificationRule::class => ['qualification.optimized.function' => ['imported', 'backslashed']],
		Rules\Namespaces\ImportNotationRule::class => ['imports.statement.class' => 'separate', 'imports.statement.function' => $shape, 'imports.statement.constant' => 'separate'],
	];
	$certain = new Analyses\Registry(new NamespacedSymbols(complete: true));
	$reported = fn(FileResult $result) => array_values(array_unique(array_map(fn(Violation $violation) => $violation->decision, $result->violations)));

	// one import of the kind fits either shape, so the shape comes from the rule
	$code = "<?php\nnamespace A;\n\nuse function count;\n\ncount(\$a);\nstrlen(\$b);\n";
	$result = interplay($rules('combined'), $code, "<?php\nnamespace A;\n\nuse function count, strlen;\n\ncount(\$a);\nstrlen(\$b);\n", $certain);
	Assert::same(['qualification.optimized.function'], $reported($result));
	$result = interplay($rules('separate'), $code, "<?php\nnamespace A;\n\nuse function count;\nuse function strlen;\n\ncount(\$a);\nstrlen(\$b);\n", $certain);
	Assert::same(['qualification.optimized.function'], $reported($result));

	// and two imports the rule adds itself go into one statement
	$result = interplay($rules('combined'), "<?php\nnamespace A;\n\nstrlen(\$a);\ncount(\$b);\n", "<?php\nnamespace A;\n\nuse function count, strlen;\n\nstrlen(\$a);\ncount(\$b);\n", $certain);
	Assert::same(['qualification.optimized.function'], $reported($result));
});


test('a call the qualification writes qualified gets its arguments positionally from optimizedCallNotation', function () {
	$values = ['qualification.global.function' => 'bare', 'qualification.optimized.function' => 'backslashed'];
	interplay(
		[
			Rules\Namespaces\GlobalNameQualificationRule::class => $values,
			Rules\Namespaces\OptimizedCallNotationRule::class => $values,
		],
		"<?php\nnamespace App;\n\n\$a = count(\$list, mode: COUNT_RECURSIVE);\n\$b = strlen(string: \$s);\n",
		"<?php\nnamespace App;\n\n\$a = \\count(\$list, COUNT_RECURSIVE);\n\$b = \\strlen(\$s);\n",
		new Analyses\Registry(new NamespacedSymbols(complete: true)),
	);
});


test('an attribute written instead of an annotation stands where attributePosition wants it', function () {
	$rules = [
		Rules\Upgrading\AttributeForAnnotationRule::class => ['persistent' => 'Acme\Persistent'],
		Rules\Whitespace\AttributePositionRule::class => ['multiline.attributes.declaration' => 'ownLines', 'multiline.attributes.parameter' => 'ownLines'],
	];
	interplay($rules, "<?php\nclass P\n{\n\t/** @persistent */\n\tpublic \$lang;\n}\n", "<?php\n\nuse Acme\\Persistent;\n\nclass P\n{\n\t#[Persistent]\n\tpublic \$lang;\n}\n");
	interplay($rules, "<?php\nclass P\n{\n\t/** @persistent */\n\t#[Other]\n\tpublic \$lang;\n}\n", "<?php\n\nuse Acme\\Persistent;\n\nclass P\n{\n\t#[Other]\n\t#[Persistent]\n\tpublic \$lang;\n}\n");
});
