<?php declare(strict_types=1);

/**
 * Pairs of rules that pull at the same tokens in opposite directions: one adds what the other removes.
 * Each pair must converge, and its result must be the one the pair is meant to give.
 */

use DressCode\AnalysisRegistry;
use DressCode\Config\PresetResolver;
use DressCode\Config\RuleRegistry;
use DressCode\Engine\FileProcessor;
use DressCode\Rules;
use PhpSyntax\PhpVersion;
use PhpSyntax\Style;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** @param array<class-string<DressCode\Rule>, true|array<string, mixed>> $rules */
function interplay(array $rules, string $code, ?string $expected = null): void
{
	$registry = new RuleRegistry;
	$instances = [];
	foreach ($rules as $class => $options) {
		$instances[] = PresetResolver::createRule($class, $options);
	}

	$processor = new FileProcessor($instances, new AnalysisRegistry, $registry->resolveNames(...), PhpVersion::lowest(), new Style("\t", "\n"));
	$result = $processor->process('interplay.php', $code);
	Assert::null($result->error);
	Assert::same($expected ?? $code, $result->output);
	$again = $processor->process('interplay.php', $result->output);
	Assert::same($result->output, $again->output, 'the result is not stable');
}


test('statement-blank-lines never adds before the first statement of a block, which body-blank-lines trims', function () {
	interplay([
		Rules\Whitespace\StatementBlankLinesRule::class => true,
		Rules\Whitespace\BodyBlankLinesRule::class => true,
	], "<?php\nfunction f()\n{\n\n\treturn 1;\n}\n", "<?php\nfunction f()\n{\n\treturn 1;\n}\n");
});


test('statement-blank-lines leaves a nested declaration to declaration-blank-lines', function () {
	interplay([
		Rules\Whitespace\StatementBlankLinesRule::class => ['after' => ['if' => 1]],
		Rules\Whitespace\DeclarationBlankLinesRule::class => true,
	], "<?php\nfunction f()\n{\n\tif (\$x) {\n\t}\n\tfunction g()\n\t{\n\t}\n}\n", "<?php\nfunction f()\n{\n\tif (\$x) {\n\t}\n\n\n\tfunction g()\n\t{\n\t}\n}\n");
});


test('statement-blank-lines leaves the statement after the imports to header-blank-lines', function () {
	interplay([
		Rules\Whitespace\StatementBlankLinesRule::class => ['before' => ['if' => 0]],
		Rules\Files\HeaderBlankLinesRule::class => true,
	], "<?php\n\nuse A;\n\nif (\$x) {\n}\n");
});


test('explicit-operator-precedence adds what useless-construct-parentheses does not remove', function () {
	interplay([
		Rules\Expressions\ExplicitOperatorPrecedenceRule::class => true,
		Rules\ControlFlow\UselessConstructParenthesesRule::class => true,
	], "<?php\nreturn \$a && \$b || \$c;\necho (\$a);\n", "<?php\nreturn (\$a && \$b) || \$c;\necho \$a;\n");
});


test('explicit-operator-precedence and symbolic-logical-operators agree on and/or', function () {
	interplay([
		Rules\Expressions\ExplicitOperatorPrecedenceRule::class => true,
		Rules\Expressions\SymbolicLogicalOperatorsRule::class => true,
	], "<?php\nif (\$b and \$c or \$d) {\n}\n", "<?php\nif ((\$b && \$c) || \$d) {\n}\n");
});


test('chain-indentation, statement-indentation and multi-line-call settle on one shape', function () {
	interplay([
		Rules\Expressions\ChainIndentationRule::class => true,
		Rules\Whitespace\StatementIndentationRule::class => true,
		Rules\Functions\MultiLineCallRule::class => true,
	], "<?php\nfunction f()\n{\n  \$a = \$foo\n  ->bar(\n    1,\n      2,\n    )\n        ->baz();\n}\n", "<?php\nfunction f()\n{\n\t\$a = \$foo\n\t\t->bar(\n\t\t\t1,\n\t\t\t2,\n\t\t)\n\t\t->baz();\n}\n");
});


test('useless-modifier and visibility-required settle on one shape', function () {
	interplay([
		Rules\Classes\UselessModifierRule::class => true,
		Rules\Classes\VisibilityRequiredRule::class => true,
	], "<?php\nfinal class A\n{\n\tfinal function a() {}\n\tstatic final public function b() {}\n}\n", "<?php\nfinal class A\n{\n\tpublic function a() {}\n\tpublic static function b() {}\n}\n");
});


test('import-notation combines what ordered-imports then sorts', function () {
	interplay([
		Rules\Namespaces\ImportNotationRule::class => ['functions' => 'combined'],
		Rules\Namespaces\OrderedImportsRule::class => true,
	], "<?php\nnamespace A;\nuse function b;\nuse function a;\nuse D, C;\n", "<?php\nnamespace A;\nuse C;\nuse D;\nuse function a, b;\n");
});
