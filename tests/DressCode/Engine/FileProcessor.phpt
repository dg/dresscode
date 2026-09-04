<?php declare(strict_types=1);

use DressCode\Analyses;
use DressCode\Config;
use DressCode\Config\PresetResolver;
use DressCode\ConvergenceException;
use DressCode\Engine\Baseline;
use DressCode\Engine\FileProcessor;
use DressCode\FileResult;
use DressCode\NodeRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use DressCode\Style;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
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


/** @param list<Rule> $rules */
function processWithBaseline(array $rules, string $code, ?Baseline $baseline): FileResult
{
	return new FileProcessor($rules, new Analyses\Registry, fn(string $name) => [$name], Config::DefaultPhpVersion, baseline: $baseline)
		->process('a.php', $code);
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


test('a claim a comment keeps from being fixed remains, whatever the rest of the traversal fixed after it', function () {
	$code = "<?php\nif (\$a) {\n\t\$b;\n}\n// why\nelseif (\$c) {\n\t\$d;\n}\nif (\$e)\n{\n\t\$f;\n}\n";
	$result = processor([PresetResolver::createRule(DressCode\Rules\Whitespace\BracesPositionRule::class)])->process('a.php', $code);
	Assert::same("<?php\nif (\$a) {\n\t\$b;\n}\n// why\nelseif (\$c) {\n\t\$d;\n}\nif (\$e) {\n\t\$f;\n}\n", $result->output);
	Assert::count(2, $result->violations);
	Assert::same(['No line break before the elseif keyword'], array_map(fn($v) => $v->message, $result->remaining));
});


test('a violation the baseline knows is not recognized in a later round on a line a fix rewrote, and remains', function () {
	$rules = fn() => [new ProcessorRename, PresetResolver::createRule(DressCode\Rules\Variables\NoGlobalKeywordRule::class)];
	$code = "<?php\nglobal \$a;\n";
	$global = array_values(array_filter(
		processWithBaseline($rules(), $code, null)->violations,
		fn($v) => $v->ruleName === 'dresscode/no-global-keyword',
	));
	$baseline = Baseline::fromResults([new FileResult('a.php', $code, $code, $global)]);

	$result = processWithBaseline($rules(), $code, $baseline);
	Assert::same("<?php\nglobal \$b;\n", $result->output);
	Assert::same(['test/rename'], array_map(fn($v) => $v->ruleName, $result->violations));
	Assert::count(1, $result->baselined);
	// what the fix left is what the next check reports
	Assert::same(['dresscode/no-global-keyword'], array_map(fn($v) => $v->ruleName, $result->remaining));
	$next = processWithBaseline($rules(), (string) $result->output, $baseline);
	Assert::same(array_map(fn($v) => $v->fingerprint, $result->remaining), array_map(fn($v) => $v->fingerprint, $next->violations));
});


test('a violation the baseline knows is not recorded, and a rule that can fix it fixes it', function () {
	$rules = fn() => [PresetResolver::createRule(DressCode\Rules\Literals\StringQuotesRule::class)];
	$code = "<?php\n\$a = \"x\";\n";
	$baseline = Baseline::fromResults([processWithBaseline($rules(), $code, null)]);

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


test('a text the rounds come back to is a cycle, and one still changing in the last round fails too', function () {
	$cycle = processor([new RenameParsed(fn(string $name) => ['$a' => '$b', '$b' => '$a'][$name] ?? null)]);
	Assert::exception(
		fn() => $cycle->process('a.php', "<?php\nf(\$a);\n"),
		ConvergenceException::class,
		'Rules test/rename-parsed do not converge in a.php.',
	);

	$growth = processor([new RenameParsed(fn(string $name) => $name . 'a')]);
	$e = Assert::exception(
		fn() => $growth->process('a.php', "<?php\nf(\$a);\n"),
		ConvergenceException::class,
		'Rules test/rename-parsed do not converge in a.php.',
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
