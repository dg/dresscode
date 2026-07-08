<?php declare(strict_types=1);

use DressCode\{Analyses, Claim, Config, ConvergenceException, GapRule, Line, NodeRule, Rule, RuleContext, RuleException, RuleInfo, Severity, Stage, Style};
use DressCode\Engine\PassRunner;
use DressCode\Rules\Whitespace\IndentationRule;
use PhpSyntax\{Node, Parser, Token};
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\Statement\{ExpressionStatementNode, IfNode};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


#[RuleInfo('test/report-variables', Stage::Formatting)]
final class ReportVariables extends NodeRule
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


/**
 * Upper-cases two variables of one statement in one callback, and $b is the risky occurrence, so that the
 * accounting sees a safe and a refused report side by side; the flag reports them in the other order.
 */
#[RuleInfo('test/rename-pair', Stage::Structure)]
final class RenamePair extends NodeRule
{
	public function __construct(
		private bool $riskyFirst = false,
	) {
	}


	public function getVisitedTypes(): array
	{
		return [ExpressionStatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$variables = $node instanceof Node ? $node->find(VariableNode::class) : [];
		foreach ($this->riskyFirst ? array_reverse($variables) : $variables as $variable) {
			$name = $variable->name;
			if (!$name instanceof Token || ($upper = strtoupper($name->text)) === $name->text) {
				continue;
			}

			if ($context->report($variable, "Variable {$name->text}", risky: $name->text === '$b')) {
				$name->setText($upper);
			}
		}
	}
}


/** The body of an if on a line of its own, a blank line above it. */
#[RuleInfo('test/break-body', Stage::Formatting)]
final class BreakBody extends GapRule
{
	public function getClaims(): array
	{
		return [IfNode::class => ['body' => [new Claim(line: Line::Next, blank: 1), null]]];
	}
}


/** The body of an if on the line of the if. */
#[RuleInfo('test/join-body', Stage::Formatting)]
final class JoinBody extends GapRule
{
	public function getClaims(): array
	{
		return [IfNode::class => ['body' => [Claim::sameLine(), null]]];
	}
}


/** Reports the whitespace before the body of an if, whatever it is. */
#[RuleInfo('test/report-body-space', Stage::Cleanup)]
final class ReportBodySpace extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [IfNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$before = $node instanceof IfNode ? $node->body?->getFirstToken()?->getPrevious() : null;
		$trivia = $before?->trailingTrivia[0] ?? null;
		if ($before !== null && $trivia !== null) {
			$context->report($before, 'Whitespace before the body', trivia: $trivia);
		}
	}
}


/** Reports the shape of the line every statement stands on, which is nobody's whitespace. */
#[RuleInfo('test/report-statement-line', Stage::Cleanup)]
final class ReportStatementLine extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [ExpressionStatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$token = $node instanceof ExpressionStatementNode ? $node->getFirstToken() : null;
		if ($token !== null) {
			$context->report($token, "The line of $token->text", byLine: true);
		}
	}
}


/**
 * Places every statement by the one above it: the second is a risky move, the third follows the second,
 * so what the third is derived from depends on whether the run allowed the move.
 */
#[RuleInfo('test/move-chain', Stage::Cleanup)]
final class MoveChain extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}


	public function afterFile(RuleContext $context): void
	{
		$tokens = [];
		foreach ($context->getFile()->find(ExpressionStatementNode::class) as $statement) {
			$tokens[] = $statement->getFirstToken() ?? throw new LogicException;
		}

		[$a, $b, $c] = $tokens;
		$context->report($b, 'Move $b', trivia: $b->leadingTrivia[0], risky: true, follows: $a);
		$context->report($c, 'Move $c', trivia: $c->leadingTrivia[0], follows: $b);
	}
}


#[RuleInfo('test/rename', Stage::Structure)]
final class RenameA extends NodeRule
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
final class SilentMutation extends NodeRule
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
final class Stubborn extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$context->report($node, 'x');
		if ($node instanceof Node && ($token = $node->getFirstToken())) {
			$token->setText('$x');
		}
	}
}


/** Reports a variable and puts a fresh node in its place, which has no position of its own. */
#[RuleInfo('test/replace', Stage::Structure)]
final class ReplaceVariable extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof VariableNode && $node->name instanceof Token && $node->name->text === '$a') {
			if ($context->report($node, 'Replace $a')) {
				$node->replaceWith((new Parser)->parseExpression('$b'));
			}
		}
	}
}


/** Reports every variable of the file in one callback and renames the ones it was allowed to. */
#[RuleInfo('test/batch', Stage::Cleanup)]
final class BatchRename extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}


	public function afterFile(RuleContext $context): void
	{
		foreach ($context->getFile()->find(VariableNode::class) as $var) {
			if ($var->name instanceof Token && $var->name->text === '$a' && $context->report($var, 'Rename $a')) {
				$var->name->setText('$b');
			}
		}
	}
}


#[RuleInfo('test/toggle', Stage::Cleanup)]
final class Toggle extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}


	public function afterFile(RuleContext $context): void
	{
		$forward = !($context->storage['back'] ?? false);
		$context->storage['back'] = $forward;
		foreach ($context->getFile()->find(VariableNode::class) as $var) {
			if ($var->name instanceof Token && $context->report($var, 'toggle')) {
				$var->name->setText($forward ? '$b' : '$a');
			}
		}
	}
}


#[RuleInfo('test/remover', Stage::Structure)]
final class RemoveStatement extends NodeRule
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
final class CountStatements extends NodeRule
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
		$context->storage['done'] = true;
		self::$seen[] = 'after';
	}
}


#[RuleInfo('test/thrower', Stage::Cleanup)]
final class Thrower extends NodeRule
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
 * @param  array<string, true>  $fixRiskyRules
 * @return array{PhpSyntax\Nodes\FileNode, DressCode\Engine\PassResult}
 */
function run(
	string $code,
	array $rules,
	bool $strict = true,
	bool $fixRisky = false,
	array $fixRiskyRules = [],
): array
{
	$file = (new Parser)->parse($code);
	$runner = new PassRunner($rules, new Analyses\Registry, fn(string $name) => [$name], strict: $strict, fixRisky: $fixRisky, fixRiskyRules: $fixRiskyRules);
	$result = $runner->run($file, $code, 'test.php', new Style, Config::DefaultPhpVersion);
	return [$file, $result];
}


test('reports become violations with original positions and fingerprints', function () {
	[, $result] = run("<?php\n\t\$a = \$b;\n\$a;", [new ReportVariables]);
	Assert::count(3, $result->violations);
	[$a, $b, $a2] = $result->violations;
	Assert::same(['test/report-variables', 'Variable $a', 2, 2, Severity::Warning, null], [$a->ruleName, $a->message, $a->line, $a->column, $a->severity, $a->derivedFrom]);
	Assert::same([2, 7], [$b->line, $b->column]);
	Assert::same([3, 1], [$a2->line, $a2->column]);
	Assert::notSame($a->fingerprint, $a2->fingerprint);
	Assert::same($a->fingerprint, run("<?php\n\t\$a = \$b;\n\$a;", [new ReportVariables])[1]->violations[0]->fingerprint);
	Assert::same(1, $result->passes);
	Assert::false($result->mutated);
});


test('a violation keeps the position the reported code still had', function () {
	// the node is replaced by one without a position, so a position resolved afterwards would fall
	// back to the nearest original token before it, which stands on the line above
	[$file, $result] = run("<?php\nf(\n\t\$a,\n);\n", [new ReplaceVariable]);
	Assert::same("<?php\nf(\n\t\$b,\n);\n", (string) $file);
	Assert::count(1, $result->violations);
	Assert::same([3, 2], [$result->violations[0]->line, $result->violations[0]->column]);
});


test('a fix takes one more pass', function () {
	[$file, $result] = run('<?php $a; $a;', [new RenameA]);
	Assert::same('<?php $b; $b;', (string) $file);
	Assert::count(2, $result->violations);
	Assert::same(2, $result->passes);
	Assert::true($result->mutated);
});


test('stages run in order within a pass', function () {
	[$file, $result] = run('<?php $a;', [new ReportVariables, new RenameA]);
	Assert::same('<?php $b;', (string) $file);
	Assert::same(['Rename $a', 'Variable $b'], array_map(fn($v) => $v->message, $result->violations));
	// a report on a tree another rule changed is not derived by that alone
	Assert::same([null, null], array_map(fn($v) => $v->derivedFrom, $result->violations));
});


test('a violation about the gap of a line the fixer opened is derived from the one the break was written for', function () {
	// the break goes in first, the blank lines wait for the next pass and their report is derived
	[$file, $result] = run("<?php\nif (\$a) \$b;\n", [new BreakBody]);
	Assert::same("<?php\nif (\$a)\n\n\t\$b;\n", (string) $file);
	Assert::count(2, $result->violations);
	$byMessage = array_column($result->violations, null, 'message');
	$break = $byMessage['A line break before the statement'];
	$blank = $byMessage['Expected 1 blank line before the statement, 0 found'];
	Assert::same([null, $break->fingerprint], [$break->derivedFrom, $blank->derivedFrom]);
	Assert::same([2, 2], [$break->line, $blank->line]);
});


test('a violation about the whitespace of a line the fixer closed is derived from the one the break was taken out for', function () {
	[$file, $result] = run("<?php\nif (\$a)\n\t\$b;\n", [new JoinBody, new ReportBodySpace]);
	Assert::same("<?php\nif (\$a) \$b;\n", (string) $file);
	$byMessage = array_column($result->violations, null, 'message');
	$join = $byMessage['No line break before the statement'];
	Assert::null($join->derivedFrom);
	Assert::same($join->fingerprint, $byMessage['Whitespace before the body']->derivedFrom);
});


test('a line that follows a move the run refused is not derived from it', function () {
	$code = "<?php\n\t\$a;\n\t\$b;\n\t\$c;\n";
	foreach ([false, true] as $fixRisky) {
		[, $result] = run($code, [new MoveChain], fixRisky: $fixRisky);
		$byMessage = array_column($result->violations, null, 'message');
		Assert::same($fixRisky ? $byMessage['Move $b']->fingerprint : null, $byMessage['Move $c']->derivedFrom, $fixRisky ? 'allowed' : 'refused');
	}
});


test('a line placed by the line of its construct follows that line, and its ancestor is the first', function () {
	// $b is wrong on its own; the inner if is wrong on its own, and $d and the inner brace count from it
	$code = "<?php\nif (\$a) {\n\$b;\nif (\$c) {\n\$d;\n}\n}\n";
	[$file, $result] = run($code, [new IndentationRule]);
	Assert::same("<?php\nif (\$a) {\n\t\$b;\n\tif (\$c) {\n\t\t\$d;\n\t}\n}\n", (string) $file);
	[$b, $if, $d, $brace] = $result->violations;
	Assert::same([3, 4, 5, 6], array_map(fn($v) => $v->line, $result->violations));
	Assert::same([null, null, $if->fingerprint, $if->fingerprint], [$b->derivedFrom, $if->derivedFrom, $d->derivedFrom, $brace->derivedFrom]);

	// the line the fixer opened is the ancestor of what is placed by it, however deep
	[$file, $result] = run("<?php\nif (\$a) foreach (\$c as \$x) {\n\$d;\n}\n", [new BreakBody, new IndentationRule]);
	Assert::same("<?php\nif (\$a)\n\n\tforeach (\$c as \$x) {\n\t\t\$d;\n\t}\n", (string) $file);
	Assert::count(4, $result->violations);
	$break = null;
	foreach ($result->violations as $violation) {
		if (str_starts_with($violation->message, 'A line break')) {
			$break = $violation;
		}
	}

	Assert::notNull($break);
	Assert::null($break->derivedFrom);
	foreach ($result->violations as $violation) {
		if ($violation !== $break) {
			Assert::same($break->fingerprint, $violation->derivedFrom, $violation->message);
		}
	}
});


test('a violation about the shape of a line is derived from what opened it, and moves that line for nobody', function () {
	[, $result] = run("<?php\nif (\$a) \$b;\n", [new BreakBody, new ReportStatementLine]);
	$byMessage = array_column($result->violations, null, 'message');
	Assert::same($byMessage['A line break before the statement']->fingerprint, $byMessage['The line of $b']->derivedFrom);

	// a line nobody opened leaves such a report its own, and the report is no move the lines below count from
	[, $result] = run("<?php\n\t\$a;\n\t\$b;\n\t\$c;\n", [new ReportStatementLine, new MoveChain], fixRisky: true);
	$byMessage = array_column($result->violations, null, 'message');
	Assert::null($byMessage['The line of $b']->derivedFrom);
	Assert::same($byMessage['Move $b']->fingerprint, $byMessage['Move $c']->derivedFrom);
});


test('suppression stops the fix', function () {
	[$file, $result] = run("<?php\n\$a; // dresscode:ignore test/rename\n\$a;", [new RenameA]);
	Assert::same("<?php\n\$a; // dresscode:ignore test/rename\n\$b;", (string) $file);
	Assert::count(1, $result->violations);
});


test('contract violations: silent mutation and mutation after a suppressed report', function () {
	Assert::exception(fn() => run('<?php $a; ', [new SilentMutation]), RuleException::class, 'Rule test/silent failed in test.php: It changed the file without reporting a violation.');
	[, $result] = run('<?php $a; ', [new SilentMutation], strict: false);
	Assert::same(['Rule test/silent is faulty: it changed the file without reporting a violation.'], $result->warnings);
	Assert::exception(fn() => run("<?php\n\$x; // dresscode:ignore\n", [new Stubborn]), RuleException::class, '%a%changed the file after a suppressed report.');

	// one callback, one report silenced and the others fixed: what the rule wrote it wrote for the others
	$code = "<?php\n\$a;\n\$a; // dresscode:ignore test/batch\n\$a;\n";
	[$file, $result] = run($code, [new BatchRename]);
	Assert::same("<?php\n\$b;\n\$a; // dresscode:ignore test/batch\n\$b;\n", (string) $file);
	Assert::same([], $result->warnings);
	Assert::count(2, $result->violations);
});


test('a cycle is reported with the rules involved and a diff', function () {
	$e = Assert::exception(fn() => run("<?php\n\$a;\n", [new Toggle]), ConvergenceException::class, 'Rule test/toggle does not converge in test.php.');
	Assert::type(ConvergenceException::class, $e);
	Assert::match("--- test.php\n+++ test.php\n@@ -1,2 +1,2 @@\n <?php\n-\$b;\n+\$a;\n", $e->diff);

	$e = Assert::exception(fn() => run("<?php\n\$a;\n", [new RenameA('$a', '$b'), new RenameA('$b', '$a')]), ConvergenceException::class, 'Rule test/rename does not converge in test.php.');
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


test('a risky occurrence is reported and left alone, and the safe one beside it is fixed either way', function () {
	foreach ([false, true] as $riskyFirst) {
		$order = $riskyFirst ? 'risky first' : 'safe first';
		[$file, $result] = run("<?php\n\$a + \$b;\n", [new RenamePair($riskyFirst)]);
		Assert::same("<?php\n\$A + \$b;\n", (string) $file, $order);
		Assert::same([], $result->warnings, $order);
		Assert::count(2, $result->violations);
		Assert::same([false, true], array_map(fn($v) => $v->risky, $result->violations), $order);
		Assert::same([false, true], array_map(fn($v) => $v->refused, $result->violations), $order);
	}
});


test('with the fixes allowed the risky occurrence is fixed and says it was risky', function () {
	[$file, $result] = run("<?php\n\$a + \$b;\n", [new RenamePair], fixRisky: true);
	Assert::same("<?php\n\$A + \$B;\n", (string) $file);
	Assert::count(2, $result->violations);
	Assert::same([false, true], array_map(fn($v) => $v->risky, $result->violations));
	Assert::same([false, false], array_map(fn($v) => $v->refused, $result->violations));
});


#[RuleInfo('test/risky-rule', Stage::Structure, risky: true)]
final class RiskyRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			$node instanceof VariableNode
			&& $node->name instanceof Token
			&& $node->name->text === '$a'
			&& $context->report($node, 'Rename $a')
		) {
			$node->name->setText('$b');
		}
	}
}


test('a rule whose every fix may change what the code does makes every report of it risky', function () {
	foreach ([false, true] as $fixRisky) {
		[$file, $result] = run("<?php\n\$a;\n", [new RiskyRule], fixRisky: $fixRisky);
		Assert::same($fixRisky ? "<?php\n\$b;\n" : "<?php\n\$a;\n", (string) $file);
		Assert::same([true], array_map(fn($v) => $v->risky, $result->violations));
		Assert::same([!$fixRisky], array_map(fn($v) => $v->refused, $result->violations));
	}
});


test('a rule the project names in fixRisky has its risky fixes made without the switch of the run, and no other rule does', function () {
	[$file, $result] = run("<?php\n\$a;\n", [new RiskyRule], fixRiskyRules: ['test/risky-rule' => true]);
	Assert::same("<?php\n\$b;\n", (string) $file);
	Assert::false($result->violations[0]->refused);
	[$file, $result] = run("<?php\n\$a;\n", [new RiskyRule], fixRiskyRules: ['test/other' => true]);
	Assert::same("<?php\n\$a;\n", (string) $file);
	Assert::true($result->violations[0]->refused);
});


#[RuleInfo('test/report-without-fix', Stage::Structure, risky: true)]
final class ReportWithoutFix extends NodeRule
{
	public function __construct(
		private readonly bool $ignoresAnswer = false,
	) {
	}


	public function getVisitedTypes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			$node instanceof VariableNode
			&& $node->name instanceof Token
			&& $node->name->text === '$a'
			&& ($context->report($node, 'Report $a', fixable: false) || $this->ignoresAnswer)
		) {
			$node->name->setText('$b');
		}
	}
}


test('a report without a fix is never risky, never refused and never lets the rule mutate', function () {
	foreach ([false, true] as $fixRisky) {
		[$file, $result] = run("<?php\n\$a;\n", [new ReportWithoutFix], fixRisky: $fixRisky);
		Assert::same("<?php\n\$a;\n", (string) $file);
		Assert::same([[false, false]], array_map(fn($v) => [$v->risky, $v->refused], $result->violations));
	}

	Assert::exception(
		fn() => run("<?php\n\$a;\n", [new ReportWithoutFix(ignoresAnswer: true)], fixRisky: true),
		RuleException::class,
		'Rule test/report-without-fix failed in test.php: It changed the file after a report without a fix.',
	);
});
