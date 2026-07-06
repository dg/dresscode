<?php declare(strict_types=1);

use DressCode\AnalysisRegistry;
use DressCode\ConvergenceException;
use DressCode\Engine\PassRunner;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleException;
use DressCode\RuleInfo;
use DressCode\Severity;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\PhpVersion;
use PhpSyntax\Style;
use PhpSyntax\Token;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


#[RuleInfo('test/report-variables', Stage::Formatting)]
final class ReportVariables extends Rule
{
	public function getVisitedTypes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$context->report($node, 'Variable ' . ($node instanceof Node ? $node->getFirstToken()?->text : $node->text), Severity::Warning);
	}
}


#[RuleInfo('test/rename', Stage::Structure)]
final class RenameA extends Rule
{
	public function __construct(
		private string $from = '$a',
		private string $to = '$b',
	) {
	}


	public function getVisitedTypes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof VariableNode && $node->name instanceof Token && $node->name->text === $this->from) {
			if ($context->report($node, "Rename $this->from")) {
				$node->name->setText($this->to);
			}
		}
	}
}


#[RuleInfo('test/silent', Stage::Formatting)]
final class SilentMutation extends Rule
{
	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof Token && $node->trailingTrivia) {
			$node->setTrailingTrivia([]);
		}
	}
}


#[RuleInfo('test/stubborn', Stage::Formatting)]
final class Stubborn extends Rule
{
	public function getVisitedTypes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$context->report($node, 'x');
		if ($node instanceof Node) {
			$node->getFirstToken()?->setText('$x');
		}
	}
}


#[RuleInfo('test/toggle', Stage::Cleanup)]
final class Toggle extends Rule
{
	public function getVisitedTypes(): array
	{
		return [];
	}


	public function afterFile(RuleContext $context): void
	{
		$forward = !$context->getStorage()->get('back', false);
		$context->getStorage()->set('back', $forward);
		foreach ($context->getFile()->getDescendants(VariableNode::class) as $var) {
			if ($var->name instanceof Token && $context->report($var, 'toggle')) {
				$var->name->setText($forward ? '$b' : '$a');
			}
		}
	}
}


#[RuleInfo('test/remover', Stage::Structure)]
final class RemoveStatement extends Rule
{
	public function getVisitedTypes(): array
	{
		return [ExpressionStatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof Node && $context->report($node, 'remove')) {
			$node->remove();
		}
	}
}


#[RuleInfo('test/counter', Stage::Structure)]
final class CountStatements extends Rule
{
	/** @var list<string> */
	public static array $seen = [];


	public function getVisitedTypes(): array
	{
		return [ExpressionStatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		self::$seen[] = (string) $node;
	}


	public function afterFile(RuleContext $context): void
	{
		$context->getStorage()->set('done', true);
		self::$seen[] = 'after';
	}
}


#[RuleInfo('test/thrower', Stage::Cleanup)]
final class Thrower extends Rule
{
	public function getVisitedTypes(): array
	{
		return [];
	}


	public function beforeFile(RuleContext $context): void
	{
		throw new RuntimeException('boom');
	}
}


/**
 * @param  list<Rule>  $rules
 * @return array{PhpSyntax\Nodes\FileNode, DressCode\Engine\PassResult}
 */
function run(string $code, array $rules, bool $strict = true): array
{
	$file = (new Parser)->parse($code);
	$runner = new PassRunner($rules, new AnalysisRegistry, fn(string $name) => $name, strict: $strict);
	$result = $runner->run($file, $code, 'test.php', new Style, PhpVersion::current());
	return [$file, $result];
}


test('reports become violations with original positions and fingerprints', function () {
	[, $result] = run("<?php\n\t\$a = \$b;\n\$a;", [new ReportVariables]);
	Assert::count(3, $result->violations);
	[$a, $b, $a2] = $result->violations;
	Assert::same(['test/report-variables', 'Variable $a', 2, 2, Severity::Warning, false, false], [$a->ruleName, $a->message, $a->line, $a->column, $a->severity, $a->fixable, $a->followUp]);
	Assert::same([2, 7], [$b->line, $b->column]);
	Assert::same([3, 1], [$a2->line, $a2->column]);
	Assert::notSame($a->fingerprint, $a2->fingerprint);
	Assert::same($a->fingerprint, run("<?php\n\t\$a = \$b;\n\$a;", [new ReportVariables])[1]->violations[0]->fingerprint);
	Assert::same(1, $result->passes);
	Assert::false($result->mutated);
});


test('a fix marks the violation fixable and takes one more pass', function () {
	[$file, $result] = run('<?php $a; $a;', [new RenameA]);
	Assert::same('<?php $b; $b;', (string) $file);
	Assert::same([true, true], array_map(fn($v) => $v->fixable, $result->violations));
	Assert::same(2, $result->passes);
	Assert::true($result->mutated);
});


test('stages run in order within a pass and follow-up violations are marked', function () {
	[$file, $result] = run('<?php $a;', [new ReportVariables, new RenameA]);
	Assert::same('<?php $b;', (string) $file);
	Assert::same(['Rename $a', 'Variable $b'], array_map(fn($v) => $v->message, $result->violations));
	Assert::same([false, true], array_map(fn($v) => $v->followUp, $result->violations));
});


test('suppression stops the fix', function () {
	[$file, $result] = run("<?php\n\$a; // dresscode:ignore test/rename\n\$a;", [new RenameA]);
	Assert::same("<?php\n\$a; // dresscode:ignore test/rename\n\$b;", (string) $file);
	Assert::count(1, $result->violations);
});


test('contract violations: silent mutation and mutation after a suppressed report', function () {
	Assert::exception(fn() => run('<?php $a; ', [new SilentMutation]), RuleException::class, 'Rule  failed in test.php: Rule test/silent mutated the file without reporting a violation.');
	[, $result] = run('<?php $a; ', [new SilentMutation], strict: false);
	Assert::same(['Rule test/silent mutated the file without reporting a violation.'], $result->warnings);
	Assert::exception(fn() => run("<?php\n\$x; // dresscode:ignore\n", [new Stubborn]), RuleException::class, '%a%mutated the file after a suppressed report.');
});


test('a cycle is reported with the rules involved and a diff', function () {
	$e = Assert::exception(fn() => run("<?php\n\$a;\n", [new Toggle]), ConvergenceException::class, 'Rules test/toggle do not converge in test.php.');
	Assert::type(ConvergenceException::class, $e);
	Assert::match("--- test.php\n+++ test.php\n@@ -1,2 +1,2 @@\n <?php\n-\$b;\n+\$a;\n", $e->diff);

	$e = Assert::exception(fn() => run("<?php\n\$a;\n", [new RenameA('$a', '$b'), new RenameA('$b', '$a')]), ConvergenceException::class, 'Rules test/rename do not converge in test.php.');
	Assert::type(ConvergenceException::class, $e);
	Assert::same('', $e->diff);
});


test('a replaced or removed node is not seen by the rest of the chain', function () {
	CountStatements::$seen = [];
	[$file] = run('<?php $a; $b;', [new RemoveStatement, new CountStatements]);
	Assert::same('<?php  ', (string) $file);
	Assert::same(['after', 'after'], CountStatements::$seen);
});


test('an exception in a rule is wrapped', function () {
	$e = Assert::exception(fn() => run('<?php', [new Thrower]), RuleException::class, 'Rule test/thrower failed in test.php: boom');
	Assert::type(RuntimeException::class, $e?->getPrevious());
});
