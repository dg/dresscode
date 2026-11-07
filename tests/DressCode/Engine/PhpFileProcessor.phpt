<?php declare(strict_types=1);

use DressCode\{Analyses, Config, ConvergenceException, FileResult, NodeRule, Rule, RuleContext, RuleInfo, Stage, Style};
use DressCode\Config\RuleBuilder;
use DressCode\Engine\{Baseline, PhpFileProcessor, ReportPolicy};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\{IdentifierNode, UseItemNode};
use PhpSyntax\Nodes\Scalar\IntegerNode;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


#[RuleInfo(Stage::Structure)]
final class ProcessorRename extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof VariableNode && $node->name instanceof Token && $node->name->text === '$a') {
			if ($context->report($node, 'Rename $a.')) {
				$node->name->setText('$b');
			}
		}
	}
}


/** Renames a variable the parser read with a token of its own, so that only the next round sees the new name. */
#[RuleInfo(Stage::Structure)]
final class RenameParsed extends NodeRule
{
	use ProjectDecision;

	public function __construct(
		/** @var Closure(string): ?string */
		private readonly Closure $rename,
	) {
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$name = $node instanceof VariableNode && $node->name instanceof Token && $node->name->line >= 0 ? $node->name : null;
		$renamed = $name === null ? null : ($this->rename)($name->text);
		if ($node instanceof VariableNode && $renamed !== null && $context->report($node, 'Rename.')) {
			$node->name = new Token(Token::Variable, $renamed);
		}
	}
}


/** Removes the only expression of a statement, which leaves code PHP refuses. */
#[RuleInfo(Stage::Structure)]
final class RemoveInteger extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [IntegerNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof IntegerNode && $context->report($node, 'Remove.')) {
			$node->remove();
		}
	}
}


/** Gives the import aliased `Other` the alias `Form`, which another import may already have. */
#[RuleInfo(Stage::Structure)]
final class RenameAlias extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [UseItemNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof UseItemNode && $node->alias?->text === 'Other' && $context->report($node, 'Rename.')) {
			$node->alias = IdentifierNode::fromText('Form');
		}
	}
}


#[RuleInfo(Stage::Finishing)]
final class ReportLineEnding extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [];
	}


	public function beforePass(RuleContext $context): void
	{
		$context->report($context->file, json_encode($context->style->lineEnding));
	}
}


/** @param list<Rule> $rules */
function processor(array $rules, bool $detectLineEnding = true, bool $strict = false): PhpFileProcessor
{
	return new PhpFileProcessor(
		$rules,
		new Analyses\Registry,
		Config::DefaultPhpVersion,
		new Style,
		$detectLineEnding,
		new ReportPolicy(strict: $strict),
	);
}


/** @param list<Rule> $rules */
function processWithBaseline(array $rules, string $code, ?Baseline $baseline): FileResult
{
	return new PhpFileProcessor($rules, new Analyses\Registry, Config::DefaultPhpVersion, policy: new ReportPolicy(baseline: $baseline))
		->process('a.php', $code);
}


test('a clean file passes through unchanged', function () {
	$result = processor([new ProcessorRename])->process('a.php', "<?php\n\$x;\n");
	Assert::same("<?php\n\$x;\n", $result->output);
	Assert::false($result->changed);
	Assert::same([], $result->violations);
	Assert::same(1, $result->passes);
});


test('a fix changes the output and keeps the original', function () {
	$result = processor([new ProcessorRename])->process('a.php', "<?php\n\$a;\n");
	Assert::same("<?php\n\$a;\n", $result->code);
	Assert::same("<?php\n\$b;\n", $result->output);
	Assert::true($result->changed);
	Assert::same(['Rename $a.'], array_map(fn($v) => $v->message, $result->violations));
	Assert::same([], $result->remaining);
});


test('a claim a comment keeps from being fixed remains, whatever the rest of the traversal fixed after it', function () {
	$code = "<?php\nif (\$a) {\n\t\$b;\n}\n// why\nelseif (\$c) {\n\t\$d;\n}\nif (\$e)\n{\n\t\$f;\n}\n";
	$result = processor([RuleBuilder::createRule(DressCode\Rules\Whitespace\BracesPositionRule::class, [
		'braces.position.class' => 'nextLine', 'braces.position.function' => 'nextLine', 'braces.position.multilineSignature' => 'sameLine',
		'braces.position.closure' => 'sameLine', 'braces.position.anonymousClass' => 'sameLine',
		'braces.position.controlStructure' => 'sameLine',
		'braces.position.continuingKeyword' => 'sameLine', 'braces.emptyBody' => 'expanded', 'braces.emptyBody.anonymousClass' => 'keep',
		'braces.singlelineClosure' => 'keep',
	])])->process('a.php', $code);
	Assert::same("<?php\nif (\$a) {\n\t\$b;\n}\n// why\nelseif (\$c) {\n\t\$d;\n}\nif (\$e) {\n\t\$f;\n}\n", $result->output);
	Assert::count(2, $result->violations);
	Assert::same(['Expected no line break before the `elseif` keyword.'], array_map(fn($v) => $v->message, $result->remaining));
});


test('a violation the baseline holds is not recognized in a later round on a line a fix rewrote, and remains', function () {
	$rules = fn() => [new ProcessorRename, RuleBuilder::createRule(DressCode\Rules\Variables\NoGlobalStatementsRule::class)];
	$code = "<?php\nglobal \$a;\n";
	$global = array_values(array_filter(
		processWithBaseline($rules(), $code, null)->violations,
		fn($v) => $v->decision === 'mistakes.globalStatement',
	));
	$baseline = Baseline::fromViolations(['a.php' => $global]);

	$result = processWithBaseline($rules(), $code, $baseline);
	Assert::same("<?php\nglobal \$b;\n", $result->output);
	Assert::same(['project.processorRename'], array_map(fn($v) => $v->decision, $result->violations));
	Assert::count(1, $result->baselined);
	// what the fix left is what the next check reports
	Assert::same(['mistakes.globalStatement'], array_map(fn($v) => $v->decision, $result->remaining));
	$next = processWithBaseline($rules(), (string) $result->output, $baseline);
	Assert::same(array_map(fn($v) => $v->fingerprint, $result->remaining), array_map(fn($v) => $v->fingerprint, $next->violations));
});


test('a violation the baseline holds is not recorded, and a rule that can fix it fixes it', function () {
	$rules = fn() => [RuleBuilder::createRule(DressCode\Rules\Literals\StringQuotesRule::class, ['literals.quotes' => 'single'])];
	$code = "<?php\n\$a = \"x\";\n";
	$baseline = Baseline::fromViolations(['a.php' => processWithBaseline($rules(), $code, null)->violations]);

	$result = processWithBaseline($rules(), $code, $baseline);
	Assert::same("<?php\n\$a = 'x';\n", $result->output);
	Assert::same([], $result->violations);
	Assert::count(1, $result->baselined);
	Assert::same([], $result->remaining);
});


test('a fix another round has to finish settles in that round', function () {
	$result = processor([new RenameParsed(fn(string $name) => $name === '$a' ? '$b' : null)])->process('a.php', "<?php\nf(\$a);\n");
	Assert::same("<?php\nf(\$b);\n", $result->output);
	Assert::same([], $result->remaining);
});


test('a decision by the width of a line waits a round for the line to be indented', function () {
	$classes = [DressCode\Rules\Whitespace\IndentationRule::class, DressCode\Rules\Functions\MultilineSignatureRule::class];
	$values = RuleBuilder::resolveValues($classes, [
		'indentation.unit' => 'tab', 'indentation.binaryOperator' => 0, 'indentation.ternary' => 1,
		'indentation.switchCase' => 1, 'indentation.chain' => 'flat',
		'multiline.items.parameter' => 'perLine', 'multiline.edges.parameter' => 'apart',
	]);
	$style = new Style(maxLineLength: 50);
	$registry = new Analyses\Registry;
	Analyses\IndentationPlan::register($registry, $values, $style);
	$processor = new PhpFileProcessor(
		RuleBuilder::createRules($classes, $values),
		$registry,
		Config::DefaultPhpVersion,
		$style,
		detectLineEnding: false,
	);
	$code = "<?php\nclass A\n{\n\t\t\t\tpublic function f(int \$a, int \$b): void {}\n}\n";
	Assert::same("<?php\nclass A\n{\n\tpublic function f(int \$a, int \$b): void {}\n}\n", $processor->process('a.php', $code)->output);
});


test(
	'a run that is not strict takes what the last pass left in the tree for the next round, and only parses the printed text',
	function () {
		$result = processor([new RenameParsed(fn(string $name) => $name . 'a')])->process('a.php', "<?php\nf(\$a);\n");
		Assert::same("<?php\nf(\$aa);\n", $result->output);
		Assert::same([], $result->remaining);
		Assert::same(2, $result->passes); // the fix and the pass that changed nothing, no round over the printed text
	},
);


test('a fixed code that no longer parses fails the file in any run', function () {
	foreach ([false, true] as $strict) {
		$result = processor([new RemoveInteger], strict: $strict)->process('a.php', "<?php\necho 1;\n");
		Assert::null($result->output);
		Assert::match('The fixed code no longer parses: %a%', (string) $result->failure);
	}
});


test('in a strict run a text the rounds come back to is a cycle, and one still changing in the last round fails too', function () {
	$cycle = processor([new RenameParsed(fn(string $name) => ['$a' => '$b', '$b' => '$a'][$name] ?? null)], strict: true);
	Assert::exception(
		fn() => $cycle->process('a.php', "<?php\nf(\$a);\n"),
		ConvergenceException::class,
		'Rule `RenameParsed` does not converge in `a.php`.',
	);

	$growth = processor([new RenameParsed(fn(string $name) => $name . 'a')], strict: true);
	$e = Assert::exception(
		fn() => $growth->process('a.php', "<?php\nf(\$a);\n"),
		ConvergenceException::class,
		'Rule `RenameParsed` does not converge in `a.php`.',
	);
	Assert::type(ConvergenceException::class, $e);
	Assert::match("--- a.php\n+++ a.php\n@@ %A%\n-f(\$aaaaa);\n+f(\$aaaaaa);\n", $e->diff);
});


test('a syntax error is a result, not an exception', function () {
	$result = processor([new ProcessorRename])->process('a.php', "<?php\n\$a = ;\n");
	Assert::null($result->output);
	Assert::match('Unexpected `;`%a?%', (string) $result->syntaxError);
	Assert::same(2, $result->syntaxErrorLine);
	Assert::false($result->changed);
});


test('two imports under one name are a syntax error, which PHP refuses to compile', function () {
	$code = "<?php\nnamespace App;\n\nuse Acme\\{Control, Form};\nuse Acme\\Form;\nuse function Acme\\form;\n";
	$result = processor([new ProcessorRename])->process('a.php', $code);
	Assert::null($result->output);
	Assert::same('Cannot use Acme\Form as Form because the name is already in use', $result->syntaxError);
	Assert::same(5, $result->syntaxErrorLine);

	// in another namespace of the file, or of another kind, the name is free
	$code = "<?php\nnamespace A {\n\tuse Acme\\Form;\n\tuse function Acme\\form;\n}\nnamespace B {\n\tuse Acme\\Form;\n}\n";
	Assert::null(processor([new ProcessorRename])->process('a.php', $code)->syntaxError);

	// a function is one name in any letter case, a constant only in its own
	$cases = [
		"use function Acme\\run as go;\nuse function Acme\\walk as GO;\n" => 'Cannot use Acme\walk as GO because the name is already in use',
		"use Acme\\{function run as go, const VALUE as go, function walk as Go};\n" => 'Cannot use Acme\walk as Go because the name is already in use',
		"use const Acme\\VALUE as Flag;\nuse const Acme\\OTHER as Flag;\n" => 'Cannot use Acme\OTHER as Flag because the name is already in use',
		"use const Acme\\VALUE as Flag;\nuse const Acme\\OTHER as flag;\n" => null,
		"use Acme\\{const VALUE as Flag, const OTHER as flag, Flag};\n" => null,
	];
	foreach ($cases as $imports => $error) {
		Assert::same($error, processor([new ProcessorRename])->process('a.php', "<?php\nnamespace App;\n\n$imports")->syntaxError);
	}
});


test('a fix writing two imports under one name fails the file in any run', function () {
	foreach ([false, true] as $strict) {
		$result = processor([new RenameAlias], strict: $strict)->process('a.php', "<?php\nuse Acme\\Form;\nuse Acme\\Other as Other;\n");
		Assert::null($result->output);
		Assert::same(
			'The fixed code no longer parses: Cannot use Acme\Other as Form because the name is already in use on line 3.',
			$result->failure,
		);
	}
});


test('the style follows the line ending of the file unless told otherwise', function () {
	Assert::same(
		['"\r\n"'],
		array_map(fn($v) => $v->message, processor([new ReportLineEnding])->process('a.php', "<?php\r\n\$x;\r\n")->violations),
	);
	Assert::same(
		['"\n"'],
		array_map(
			fn($v) => $v->message,
			processor([new ReportLineEnding], detectLineEnding: false)->process('a.php', "<?php\r\n\$x;\r\n")->violations,
		),
	);
});


test('the result names the imports the fixes removed, nothing using them', function () {
	$rule = RuleBuilder::createRule(DressCode\Rules\Namespaces\NoUnusedImportsRule::class);
	$code = implode("\n", [
		'<?php',
		'namespace App;',
		'',
		'use Acme\Form;',
		'use Acme\Mail as Post;',
		'use function Acme\run as go;',
		'use const Acme\{LIMIT, USED};',
		'',
		'echo USED;',
		'',
	]);
	Assert::same([
		['kind' => 'class', 'name' => 'Acme\Form', 'alias' => null, 'namespace' => 'App'],
		['kind' => 'class', 'name' => 'Acme\Mail', 'alias' => 'Post', 'namespace' => 'App'],
		['kind' => 'function', 'name' => 'Acme\run', 'alias' => 'go', 'namespace' => 'App'],
		['kind' => 'const', 'name' => 'Acme\LIMIT', 'alias' => null, 'namespace' => 'App'],
	], processor([$rule])->process('a.php', $code)->removedImports);

	Assert::same([], processor([$rule])->process('a.php', "<?php\nuse Acme\\Form;\nnew Form;\n")->removedImports);
});
