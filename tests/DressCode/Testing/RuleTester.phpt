<?php declare(strict_types=1);

use DressCode\Config\PresetResolver;
use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Testing\{RuleTester, TestFailure};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\Analyses\{NameResolver, NamespacedSymbols};
use PhpSyntax\{Node, SymbolKind, Token, UnqualifiedResolution};
use PhpSyntax\Nodes\Expression\{FunctionCallNode, VariableNode};
use PhpSyntax\Nodes\NameNode;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


#[RuleInfo('test/rename', Stage::Structure)]
final class TestedRename extends NodeRule implements ConfigurableRule
{
	private string $to = '$b';


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure(['to' => Expect::string('$b')]);
	}


	public function configure(array $options): void
	{
		$this->to = $options['to'];
	}


	public function getVisitedTypes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof VariableNode && $node->name instanceof Token && $node->name->text === '$a') {
			if ($context->report($node, 'Rename $a')) {
				$node->name->setText($this->to);
			}
		}
	}
}


#[RuleInfo('test/broken', Stage::Formatting)]
final class Broken extends NodeRule
{
	public function __construct(
		private string $mode,
	) {
	}


	public function getVisitedTypes(): array
	{
		return [VariableNode::class, Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		switch ($this->mode) {
			case 'silent':
				if ($node instanceof Token && $node->trailingTrivia) {
					$node->setTrailingTrivia([]);
				}

				break;

			case 'stubborn':
				if ($node instanceof VariableNode && $node->name instanceof Token) {
					$context->report($node, 'x');
					$node->name->setText('$' . $node->name->text);
				}

				break;

			case 'comments':
				$comments = $node instanceof Token ? array_filter($node->trailingTrivia, fn($t) => $t->isComment()) : [];
				if ($node instanceof Token && $comments && $context->report($node, 'x')) {
					$node->setTrailingTrivia(array_values(array_filter($node->trailingTrivia, fn($t) => !$t->isComment())));
				}

				break;

			case 'ignores':
				if ($node instanceof VariableNode && $node->name instanceof Token && $node->name->text === '$a') {
					$context->report($node, 'x');
					$node->name->setText('$b');
				}

				break;
		}
	}
}


#[RuleInfo('test/risky-report', Stage::Structure, risky: true)]
final class RiskyReport extends NodeRule
{
	public function __construct(
		private bool $declared,
	) {
	}


	public function getVisitedTypes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof VariableNode) {
			$context->report($node, 'x', fixable: !$this->declared);
		}
	}
}


/** Reports what every call resolves to, and whether that is uncertain. */
#[RuleInfo('test/uncertain', Stage::Structure)]
final class TestedUncertain extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof FunctionCallNode && $node->name instanceof NameNode) {
			$resolver = $context->getAnalysis(NameResolver::class);
			$uncertain = $node->name->isUnqualified()
				&& $resolver->getUnqualifiedResolution($node->name->text, SymbolKind::Function, $node) === UnqualifiedResolution::Uncertain;
			$context->report($node, $resolver->resolveFunction($node->name) . ($uncertain ? ' uncertain' : ''), fixable: false);
		}
	}
}


test('fixtures of a rule', function () {
	Assert::same(3, RuleTester::run(TestedRename::class, __DIR__ . '/fixtures/rename'));
	Assert::same(3, RuleTester::run(fn(array $options) => PresetResolver::createRule(TestedRename::class, $options ?: true), __DIR__ . '/fixtures/rename'));
});


test('check of a single code', function () {
	RuleTester::check(new TestedRename, "<?php\n\$a;\n", "<?php\n\$b;\n", ['2: Rename $a']);
	RuleTester::check(new TestedRename, "<?php\n\$x;\n");
	Assert::exception(fn() => RuleTester::check(new TestedRename, "<?php\n\$a;\n"), TestFailure::class, "The output differs from the expected one:\n--- code\n+++ code\n@@ -1,2 +1,2 @@\n <?php\n-\$a;\n+\$b;\n");
	Assert::exception(fn() => RuleTester::check(new TestedRename, "<?php\n\$a;\n", "<?php\n\$b;\n", ['1: Rename $a']), TestFailure::class, "The violations differ from the expected ones:\n%A%-1: Rename \$a\n+2: Rename \$a\n");
	Assert::exception(fn() => RuleTester::check(new TestedRename, "<?php\n\$a = ;\n"), TestFailure::class, 'The code does not parse: %a%');
});


test('contract checks', function () {
	Assert::exception(fn() => RuleTester::check(new Broken('silent'), "<?php \$a;\n"), TestFailure::class, 'Rule test/broken failed in %a%: It changed the file without reporting a violation.');
	Assert::exception(fn() => RuleTester::check(new Broken('stubborn'), "<?php\n\$a;\n", "<?php\n\$\$a;\n"), TestFailure::class, 'Rule test/broken does not converge in code.');
	Assert::exception(fn() => RuleTester::check(new Broken('comments'), "<?php\n\$a; // gone\n", "<?php\n\$a; \n"), TestFailure::class, 'The rule lost or changed comments: "// gone".');
	Assert::exception(fn() => RuleTester::check(new Broken('ignores'), "<?php\n\$a;\n", "<?php\n\$b;\n"), TestFailure::class, 'Rule test/broken failed in %a%: It changed the file after a suppressed report.');
});


test('a risky violation left although the run allowed its fix needs a report without a fix', function () {
	RuleTester::check(new RiskyReport(declared: false), "<?php\n\$a;\n");
	Assert::exception(
		fn() => RuleTester::check(new RiskyReport(declared: false), "<?php\n\$a;\n", fixRisky: true),
		TestFailure::class,
		'The rule leaves a risky violation although the run allowed its fix (2: x); a report the rule has no fix for says fixable: false.',
	);
	RuleTester::check(new RiskyReport(declared: true), "<?php\n\$a;\n", fixRisky: true);
});


test('what the namespaces declare outside the code is given to the run, by a fixture in its header', function () {
	$code = "<?php\nnamespace App;\nf(); g();\n";
	RuleTester::check(new TestedUncertain, $code, violations: ['3: f uncertain', '3: g uncertain']);
	RuleTester::check(new TestedUncertain, $code, violations: ['3: App\f', '3: g'], namespacedSymbols: new NamespacedSymbols(['App\f'], complete: true));
	Assert::same(1, RuleTester::run(TestedUncertain::class, __DIR__ . '/fixtures/uncertain'));
});


test('fixture errors', function () {
	Assert::exception(fn() => RuleTester::run(TestedRename::class, __DIR__ . '/fixtures/none'), TestFailure::class, 'No *.code fixtures in %a%.');
	Assert::exception(fn() => RuleTester::runFixture(TestedRename::class, __DIR__ . '/fixtures/rename/clean.expected'), TestFailure::class, 'Cannot read %a%');
});
