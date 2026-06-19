<?php declare(strict_types=1);

use DressCode\{Decision, NodeRule, Risk, RuleContext, RuleInfo, Stage, Style, Values};
use DressCode\Domains\Words;
use DressCode\Testing\{RuleTester, TestFailure};
use PhpSyntax\Analyses\{NameResolver, NamespacedSymbols};
use PhpSyntax\{NameForm, Node, Token, UnqualifiedResolution};
use PhpSyntax\Nodes\Expression\{FunctionCallNode, VariableNode};
use PhpSyntax\Nodes\{FileNode, NameNode};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


#[RuleInfo(Stage::Structure)]
final class TestedRename extends NodeRule
{
	private string $to = '$b';


	public static function getDecisions(): array
	{
		return [new Decision('project.rename', new Words(['$b' => 'renamed to `$b`', '$c' => 'renamed to `$c`']), '`$a` written as another name')];
	}


	public function configure(Values $values): void
	{
		$this->to = $values->get('project.rename')->getWord();
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof VariableNode && $node->name instanceof Token && $node->name->text === '$a') {
			if ($context->report($node, 'Rename $a.')) {
				$node->name->setText($this->to);
			}
		}
	}
}


#[RuleInfo(Stage::Formatting)]
final class Broken extends NodeRule
{
	use ProjectDecision;

	public function __construct(
		private string $mode,
	) {
	}


	public function getVisitedNodes(): array
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
					$context->report($node, 'x.');
					$node->name->replaceWith(new Token(Token::Variable, '$' . $node->name->text));
				}

				break;

			case 'comments':
				$comments = $node instanceof Token ? array_filter($node->trailingTrivia, fn($t) => $t->isComment()) : [];
				if ($node instanceof Token && $comments && $context->report($node, 'x.')) {
					$node->setTrailingTrivia(array_values(array_filter($node->trailingTrivia, fn($t) => !$t->isComment())));
				}

				break;

			case 'ignores':
				if ($node instanceof VariableNode && $node->name instanceof Token && $node->name->text === '$a') {
					$context->report($node, 'x.');
					$node->name->setText('$b');
				}

				break;

			case 'unfixed':
				if ($node instanceof VariableNode) {
					$context->report($node, 'x.');
				}

				break;

			case 'unfinished':
				if ($node instanceof VariableNode) {
					$context->report($node, 'The variable `$a`', fixable: false);
				}

				break;

			case 'undeclared':
				$context->findAnalysis(NameResolver::class);
				break;
		}
	}
}


#[RuleInfo(Stage::Structure)]
final class RiskyReport extends NodeRule
{
	use ProjectDecision;

	public function __construct(
		private bool $declared,
	) {
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof VariableNode) {
			$context->report($node, 'x.', risk: Risk::BehaviorChanges, fixable: !$this->declared);
		}
	}
}


/** Reports what every call resolves to, and whether that is uncertain. */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class TestedUncertain extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof FunctionCallNode && $node->name instanceof NameNode) {
			$resolver = $context->getAnalysis(NameResolver::class);
			$uncertain = $node->name->form === NameForm::Unqualified
				&& $resolver->getUnqualifiedResolution($node->name) === UnqualifiedResolution::Uncertain;
			$context->report($node, $resolver->resolveFunction($node->name) . ($uncertain ? ' uncertain.' : '.'), fixable: false);
		}
	}
}


/** An analysis of a plugin. */
final class TestedLabel
{
	public string $text = 'built';
}


/** Reports the analysis it is given and the style it runs with. */
#[RuleInfo(Stage::Structure, analyses: [TestedLabel::class])]
final class TestedProbe extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$style = $context->style;
		$context->report($node, $context->getAnalysis(TestedLabel::class)->text . ' ' . json_encode([$style->indent, $style->lineEnding, $style->maxLineLength]) . '.', fixable: false);
	}
}


test('fixtures of a rule', function () {
	Assert::same(3, RuleTester::run(TestedRename::class, __DIR__ . '/fixtures/rename'));
	Assert::same(3, RuleTester::run(fn() => new TestedRename, __DIR__ . '/fixtures/rename'));
});


test('check of a single code', function () {
	RuleTester::check(new TestedRename, "<?php\n\$a;\n", "<?php\n\$b;\n", ['2: Rename $a.']);
	RuleTester::check(new TestedRename, "<?php\n\$x;\n");
	Assert::exception(fn() => RuleTester::check(new TestedRename, "<?php\n\$a;\n"), TestFailure::class, "The output differs from the input, which must stay unchanged without an `.expected` file:\n--- code (input)\n+++ code (output)\n@@ -1,2 +1,2 @@\n <?php\n-\$a;\n+\$b;\n");
	Assert::exception(fn() => RuleTester::check(new TestedRename, "<?php\n\$a;\n", "<?php\n\$b;\n", ['1: Rename $a.']), TestFailure::class, "The violations differ from the expected ones:\n%A%-1: Rename \$a.\n+2: Rename \$a.\n");
	Assert::exception(fn() => RuleTester::check(new TestedRename, "<?php\n\$a = ;\n"), TestFailure::class, 'The code does not parse: %a%');
});


test('contract checks', function () {
	Assert::exception(fn() => RuleTester::check(new Broken('silent'), "<?php \$a;\n"), TestFailure::class, 'Rule `Broken` failed in `%a%`: It changed the file without reporting a violation.');
	Assert::exception(fn() => RuleTester::check(new Broken('stubborn'), "<?php\n\$a;\n", "<?php\n\$\$a;\n"), TestFailure::class, 'Rule `Broken` does not converge in `code`.');
	Assert::exception(fn() => RuleTester::check(new Broken('comments'), "<?php\n\$a; // gone\n", "<?php\n\$a; \n"), TestFailure::class, 'The rule lost or changed the comment `// gone`; declare `modifiesComments` in its `#[RuleInfo]` if it changes them on purpose.');
	Assert::exception(fn() => RuleTester::check(new Broken('ignores'), "<?php\n\$a;\n", "<?php\n\$b;\n"), TestFailure::class, 'Rule `Broken` failed in `%a%`: It changed the file although `report()` returned `false`.');
	Assert::exception(fn() => RuleTester::check(new Broken('unfixed'), "<?php\n\$a;\n"), TestFailure::class, 'Rule `Broken` failed in `%a%`: It reported an occurrence it then left unfixed without saying `fixable: false`.');
	Assert::exception(fn() => RuleTester::check(new Broken('unfinished'), "<?php\n\$a;\n"), TestFailure::class, 'Rule `Broken` failed in `%a%`: It reported a message not ending with a period, `` The variable `$a` ``.');
	Assert::exception(fn() => RuleTester::check(new Broken('undeclared'), "<?php\n\$a;\n"), TestFailure::class, 'Rule `Broken` failed in `%a%`: It asks for analysis `PhpSyntax\Analyses\NameResolver`, which it does not name in `RuleInfo::$analyses`.');
});


test('a violation left although the run allowed its fix needs a report without a fix', function () {
	RuleTester::check(new RiskyReport(declared: false), "<?php\n\$a;\n", violations: ['2: x.', "\trisky BehaviorChanges"]);
	Assert::exception(
		fn() => RuleTester::check(new RiskyReport(declared: false), "<?php\n\$a;\n", fixRisky: true),
		TestFailure::class,
		'Rule `RiskyReport` failed in `code`: It reported an occurrence it then left unfixed without saying `fixable: false`.',
	);
	RuleTester::check(new RiskyReport(declared: true), "<?php\n\$a;\n", fixRisky: true);
});


test('what the namespaces declare outside the code is given to the run, by a fixture in its header', function () {
	$code = "<?php\nnamespace App;\nf(); g();\n";
	RuleTester::check(new TestedUncertain, $code, violations: ['3: f uncertain.', '3: g uncertain.']);
	RuleTester::check(new TestedUncertain, $code, violations: ['3: App\f.', '3: g.'], namespacedSymbols: new NamespacedSymbols(['App\f'], complete: true));
	Assert::same(1, RuleTester::run(TestedUncertain::class, __DIR__ . '/fixtures/uncertain'));
});


test('an analysis of a plugin reaches the rule', function () {
	$code = "<?php\n\$a;\n";
	RuleTester::check(new TestedProbe, $code, violations: ['2: built ["\t","\n",120].'], analyses: [TestedLabel::class]);
	$factory = function (FileNode $file, string $path): TestedLabel {
		$label = new TestedLabel;
		$label->text = 'made';
		return $label;
	};
	RuleTester::check(new TestedProbe, $code, violations: ['2: made ["\t","\n",120].'], analyses: [TestedLabel::class => $factory]);
	Assert::same(['4: built ["\t","\n",80].'], RuleTester::collectViolations(TestedProbe::class, __DIR__ . '/fixtures/probe/width.code', analyses: [TestedLabel::class]));
	Assert::same(1, RuleTester::run(TestedProbe::class, __DIR__ . '/fixtures/probe', analyses: [TestedLabel::class]));
});


test('a style given is used, with the line ending of the code', function () {
	$style = new Style('    ', "\r\n", 2, 60);
	RuleTester::check(new TestedProbe, "<?php\n\$a;\n", violations: ['2: built ["    ","\n",60].'], style: $style, analyses: [TestedLabel::class]);
	RuleTester::check(new TestedProbe, "<?php\r\n\$a;\r\n", violations: ['2: built ["\t","\r\n",120].'], analyses: [TestedLabel::class]);
});


test('fixture errors', function () {
	Assert::exception(fn() => RuleTester::run(TestedRename::class, __DIR__ . '/fixtures/none'), TestFailure::class, 'Directory `%a%` has no `*.code` fixtures.');
	Assert::exception(fn() => RuleTester::runFixture(TestedRename::class, __DIR__ . '/fixtures/rename/clean.expected'), TestFailure::class, 'Cannot read `%a%');

	$file = createTempDir('rule-tester') . '/header.code';
	file_put_contents(dirname($file) . '/values.neon', "project:\n\trename: '\$b'\n");
	foreach ([
		'// php 8' => 'The header `// php 8` must name a version such as `8.4`.',
		'// lineLength wide' => 'The header `// lineLength wide` must name a positive whole number.',
		'// {"to": }' => 'The options header is not valid JSON: Syntax error.',
	] as $header => $message) {
		file_put_contents($file, "<?php\n$header\n\$x;\n");
		Assert::exception(fn() => RuleTester::runFixture(TestedRename::class, $file), TestFailure::class, "`$file`: $message");
		Assert::exception(fn() => RuleTester::collectViolations(TestedRename::class, $file), TestFailure::class, "`$file`: $message");
	}
});
