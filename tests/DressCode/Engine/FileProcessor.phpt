<?php declare(strict_types=1);

use DressCode\AnalysisRegistry;
use DressCode\Engine\FileProcessor;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Style;
use PhpSyntax\Token;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


#[RuleInfo('test/rename', Stage::Structure)]
final class ProcessorRename extends Rule
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


#[RuleInfo('test/eol', Stage::Cleanup)]
final class ReportEol extends Rule
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
	return new FileProcessor($rules, new AnalysisRegistry, fn(string $name) => $name, new Style, $detectEol);
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
	Assert::same([], $result->getUnfixedViolations());
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


test('a subset of the rules', function () {
	$result = processor([new ProcessorRename])->process('a.php', "<?php\n\$a;\n", []);
	Assert::false($result->isChanged());
});
