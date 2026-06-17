<?php declare(strict_types=1);

use DressCode\{Analyses, Config, ConvergenceException, NodeRule, Rule, RuleContext, RuleInfo, Stage, Style};
use DressCode\Engine\FileProcessor;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\Scalar\IntegerNode;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


#[RuleInfo('test/rename', Stage::Structure)]
final class ProcessorRename extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof VariableNode && $node->name instanceof Token && $node->name->text === '$a') {
			if ($context->report($node, 'Rename $a')) {
				$node->name->setText('$b');
			}
		}
	}
}


/** Renames a variable the parser read with a token of its own, so that only the next round sees the new name. */
#[RuleInfo('test/renameParsed', Stage::Structure)]
final class RenameParsed extends NodeRule
{
	public function __construct(
		/** @var \Closure(string): ?string */
		private readonly Closure $rename,
	) {
	}


	public function getVisitedTypes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$name = $node instanceof VariableNode && $node->name instanceof Token && $node->name->line >= 0 ? $node->name : null;
		$renamed = $name === null ? null : ($this->rename)($name->text);
		if ($node instanceof VariableNode && $renamed !== null && $context->report($node, 'Rename')) {
			$node->name = new Token(Token::Variable, $renamed);
		}
	}
}


/** Removes the only expression of a statement, which leaves code PHP refuses. */
#[RuleInfo('test/removeInteger', Stage::Structure)]
final class RemoveInteger extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [IntegerNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof IntegerNode && $context->report($node, 'Remove')) {
			$node->remove();
		}
	}
}


#[RuleInfo('test/lineEnding', Stage::Finishing)]
final class ReportLineEnding extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}


	public function beforePass(RuleContext $context): void
	{
		$context->report($context->file, json_encode($context->style->lineEnding));
	}
}


/** @param list<Rule> $rules */
function processor(array $rules, bool $detectLineEnding = true, bool $strict = false): FileProcessor
{
	return new FileProcessor($rules, new Analyses\Registry, fn(string $name) => [$name], Config::DefaultPhpVersion, new Style, $detectLineEnding, strict: $strict);
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
	Assert::same(['Rename $a'], array_map(fn($v) => $v->message, $result->violations));
	Assert::same([], $result->remaining);
});


test('a fix another round has to finish settles in that round', function () {
	$result = processor([new RenameParsed(fn(string $name) => $name === '$a' ? '$b' : null)])->process('a.php', "<?php\nf(\$a);\n");
	Assert::same("<?php\nf(\$b);\n", $result->output);
	Assert::same([], $result->remaining);
});


test('a run that is not strict takes what the last pass left in the tree for the next round, and only parses the printed text', function () {
	$result = processor([new RenameParsed(fn(string $name) => $name . 'a')])->process('a.php', "<?php\nf(\$a);\n");
	Assert::same("<?php\nf(\$aa);\n", $result->output);
	Assert::same([], $result->remaining);
	Assert::same(2, $result->passes); // the fix and the pass that changed nothing, no round over the printed text
});


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
		'Rule `test/renameParsed` does not converge in `a.php`.',
	);

	$growth = processor([new RenameParsed(fn(string $name) => $name . 'a')], strict: true);
	$e = Assert::exception(
		fn() => $growth->process('a.php', "<?php\nf(\$a);\n"),
		ConvergenceException::class,
		'Rule `test/renameParsed` does not converge in `a.php`.',
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


test('the style follows the line ending of the file unless told otherwise', function () {
	Assert::same(['"\r\n"'], array_map(fn($v) => $v->message, processor([new ReportLineEnding])->process('a.php', "<?php\r\n\$x;\r\n")->violations));
	Assert::same(['"\n"'], array_map(fn($v) => $v->message, processor([new ReportLineEnding], detectLineEnding: false)->process('a.php', "<?php\r\n\$x;\r\n")->violations));
});
