<?php declare(strict_types=1);

use DressCode\{Analyses, Config, ConvergenceException, NodeRule, Rule, RuleContext, RuleInfo, Stage, Style};
use DressCode\Engine\FileProcessor;
use PhpSyntax\{Node, Token, TokenKind};
use PhpSyntax\Nodes\Expression\VariableNode;
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
#[RuleInfo('test/rename-parsed', Stage::Structure)]
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
		$name = $node instanceof VariableNode && $node->name instanceof Token && $node->name->originalLine !== null ? $node->name : null;
		$renamed = $name === null ? null : ($this->rename)($name->text);
		if ($node instanceof VariableNode && $renamed !== null && $context->report($node, 'Rename')) {
			$node->name = new Token(TokenKind::Variable, $renamed);
		}
	}
}


#[RuleInfo('test/eol', Stage::Cleanup)]
final class ReportEol extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}


	public function beforeFile(RuleContext $context): void
	{
		$context->report($context->getFile(), json_encode($context->getStyle()->eol));
	}
}


/** @param list<Rule> $rules */
function processor(array $rules, bool $detectEol = true): FileProcessor
{
	return new FileProcessor($rules, new Analyses\Registry, fn(string $name) => [$name], Config::DefaultPhpVersion, new Style, $detectEol);
}


test('a clean file passes through unchanged', function () {
	$result = processor([new ProcessorRename])->process('a.php', "<?php\n\$x;\n");
	Assert::same("<?php\n\$x;\n", $result->output);
	Assert::false($result->isChanged());
	Assert::same([], $result->violations);
	Assert::same(1, $result->passes);
});


test('a fix changes the output and keeps the original', function () {
	$result = processor([new ProcessorRename])->process('a.php', "<?php\n\$a;\n");
	Assert::same("<?php\n\$a;\n", $result->code);
	Assert::same("<?php\n\$b;\n", $result->output);
	Assert::true($result->isChanged());
	Assert::same(['Rename $a'], array_map(fn($v) => $v->message, $result->violations));
	Assert::same([], $result->remaining);
});


test('a fix another round has to finish settles in that round', function () {
	$result = processor([new RenameParsed(fn(string $name) => $name === '$a' ? '$b' : null)])->process('a.php', "<?php\nf(\$a);\n");
	Assert::same("<?php\nf(\$b);\n", $result->output);
	Assert::same([], $result->remaining);
});


test('a text the rounds come back to is a cycle, and one still changing in the last round fails too', function () {
	$cycle = processor([new RenameParsed(fn(string $name) => ['$a' => '$b', '$b' => '$a'][$name] ?? null)]);
	Assert::exception(
		fn() => $cycle->process('a.php', "<?php\nf(\$a);\n"),
		ConvergenceException::class,
		'Rule test/rename-parsed does not converge in a.php.',
	);

	$growth = processor([new RenameParsed(fn(string $name) => $name . 'a')]);
	$e = Assert::exception(
		fn() => $growth->process('a.php', "<?php\nf(\$a);\n"),
		ConvergenceException::class,
		'Rule test/rename-parsed does not converge in a.php.',
	);
	Assert::type(ConvergenceException::class, $e);
	Assert::match("--- a.php\n+++ a.php\n@@ %A%\n-f(\$aaaaa);\n+f(\$aaaaaa);\n", $e->diff);
});


test('a syntax error is a result, not an exception', function () {
	$result = processor([new ProcessorRename])->process('a.php', "<?php\n\$a = ;\n");
	Assert::null($result->output);
	Assert::match("Unexpected ';'%a?%", (string) $result->error);
	Assert::same(2, $result->errorLine);
	Assert::false($result->isChanged());
});


test('the style follows the line ending of the file unless told otherwise', function () {
	Assert::same(['"\r\n"'], array_map(fn($v) => $v->message, processor([new ReportEol])->process('a.php', "<?php\r\n\$x;\r\n")->violations));
	Assert::same(['"\n"'], array_map(fn($v) => $v->message, processor([new ReportEol], detectEol: false)->process('a.php', "<?php\r\n\$x;\r\n")->violations));
});
