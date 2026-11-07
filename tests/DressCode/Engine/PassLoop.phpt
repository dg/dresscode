<?php declare(strict_types=1);

use DressCode\{
	Analyses, Claim, Config, ConvergenceException, Gap, GapRule, Line, NodeRule,
	Risk, Rule, RuleContext, RuleException, RuleInfo, Severity, Space, Stage, Style,
};
use DressCode\Engine\{PassLoop, ReportPolicy, RulePlan};
use DressCode\Rules\Whitespace\IndentationRule;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Parser, Token};
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\Statement\{ExpressionStatementNode, IfNode};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


#[RuleInfo(Stage::Formatting)]
final class ReportVariables extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$context->report($node, 'Variable ' . ($node instanceof Node ? $node->getFirstToken()?->text : $node->text) . '.', fixable: false);
	}
}


/**
 * Upper-cases two variables of one statement in one callback, and $b is the risky occurrence, so that the
 * accounting sees a safe and a refused report side by side; the flag reports them in the other order.
 */
#[RuleInfo(Stage::Structure)]
final class RenamePair extends NodeRule
{
	use ProjectDecision;

	public function __construct(
		private bool $riskyFirst = false,
	) {
	}


	public function getVisitedNodes(): array
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

			$risky = $name->text === '$b';
			if ($context->report(
				$variable,
				"Variable {$name->text}.",
				risk: $risky ? Risk::TypeUnknown : null,
				because: $risky ? 'b may be anything' : null,
			)) {
				$name->setText($upper);
			}
		}
	}
}


/** The body of an if on a line of its own, a blank line above it. */
#[RuleInfo(Stage::Formatting)]
final class BreakBody extends GapRule
{
	use ProjectDecision;

	public function getClaims(): array
	{
		return [IfNode::class => ['body' => [new Claim(line: Line::Next, blankLines: 1), null]]];
	}
}


/** Asks the claim of the body of an if for an analysis it does not declare. */
#[RuleInfo(Stage::Formatting)]
final class UndeclaredGapAnalysis extends GapRule
{
	use ProjectDecision;

	public function getClaims(): array
	{
		return [IfNode::class => ['body' => [
			fn(Gap $gap) => $gap->findAnalysis(NameResolver::class) === null ? null : Claim::sameLine(),
			null,
		]]];
	}
}


/** The body of an if on the line of the if. */
#[RuleInfo(Stage::Formatting)]
final class JoinBody extends GapRule
{
	use ProjectDecision;

	public function getClaims(): array
	{
		return [IfNode::class => ['body' => [Claim::sameLine(), null]]];
	}
}


/** Reports the whitespace before the body of an if, whatever it is. */
#[RuleInfo(Stage::Finishing)]
final class ReportBodySpace extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [IfNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$before = $node instanceof IfNode ? $node->body?->getFirstToken()?->getPrevious() : null;
		$trivia = $before?->trailingTrivia[0] ?? null;
		if ($before !== null && $trivia !== null) {
			$context->report($before, 'Whitespace before the body.', trivia: $trivia, fixable: false);
		}
	}
}


/** Reports the statement, and the second line of the comment above it where it has one. */
#[RuleInfo(Stage::Finishing)]
final class ReportStatementAndComment extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [ExpressionStatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$first = $node->getFirstToken();
		$comment = array_find($first->leadingTrivia ?? [], fn($trivia) => $trivia->isComment());
		if ($first !== null && $comment !== null) {
			$context->report($first, 'Comment.', trivia: $comment, lineInTrivia: 1, fixable: false);
		}

		$context->report($node, 'Statement.', fixable: false);
	}
}


/** The body of an if one space after its condition. */
#[RuleInfo(Stage::Formatting)]
final class SpaceBody extends GapRule
{
	use ProjectDecision;

	public function getClaims(): array
	{
		return [IfNode::class => ['body' => [new Claim(space: Space::Single), null]]];
	}
}


/** Reports the shape of the line every statement stands on, which is nobody's whitespace. */
#[RuleInfo(Stage::Finishing)]
final class ReportStatementLine extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [ExpressionStatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$token = $node instanceof ExpressionStatementNode ? $node->getFirstToken() : null;
		if ($token !== null) {
			$context->report($token, "The line of $token->text.", byLine: true, fixable: false);
		}
	}
}


/**
 * Places every statement by the one above it: the second is a risky move, the third follows the second,
 * so what the third is derived from depends on whether the run allowed the move.
 */
#[RuleInfo(Stage::Finishing)]
final class MoveChain extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [];
	}


	public function afterPass(RuleContext $context): void
	{
		$tokens = [];
		foreach ($context->file->find(ExpressionStatementNode::class) as $statement) {
			$tokens[] = $statement->getFirstToken();
		}

		[$a, $b, $c] = $tokens;
		$context->report($b, 'Move $b.', trivia: $b->leadingTrivia[0], risk: Risk::BehaviorChanges, follows: $a);
		$context->report($c, 'Move $c.', trivia: $c->leadingTrivia[0], follows: $b);
	}
}


final class PassSnapshotStub implements Analyses\PassAnalysis
{
}


/** Renames `$a` to `$b`, asking for an analysis of the file and one of the pass before and after the mutation. */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class, PassSnapshotStub::class])]
final class AsksAcrossMutation extends NodeRule
{
	use ProjectDecision;

	/** @var list<array{object, object, object, object}>  the file and the pass analysis before, then after */
	public array $seen = [];


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof VariableNode && $node->name instanceof Token && $node->name->text === '$a') {
			$before = [$context->getAnalysis(NameResolver::class), $context->getAnalysis(PassSnapshotStub::class)];
			if ($context->report($node, 'Rename $a.')) {
				$node->name->setText('$b');
			}

			$this->seen[] = [...$before, $context->getAnalysis(NameResolver::class), $context->getAnalysis(PassSnapshotStub::class)];
		}
	}
}


#[RuleInfo(Stage::Structure)]
final class RenameA extends NodeRule
{
	use ProjectDecision;

	public function __construct(
		private string $from = '$a',
		private string $to = '$b',
	) {
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof VariableNode && $node->name instanceof Token && $node->name->text === $this->from) {
			if ($context->report($node, "Rename $this->from.")) {
				$node->name->setText($this->to);
			}
		}
	}
}


#[RuleInfo(Stage::Formatting)]
final class ReasonWithoutRisk extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$context->report($node, 'Reason.', because: 'it may go wrong');
	}
}


#[RuleInfo(Stage::Formatting)]
final class SilentMutation extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
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


#[RuleInfo(Stage::Formatting)]
final class Stubborn extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$context->report($node, 'x', fixable: false);
		if ($node instanceof Node && ($token = $node->getFirstToken())) {
			$token->setText('$y');
		}
	}
}


/** Reports a variable and puts a fresh node in its place, which has no position of its own. */
#[RuleInfo(Stage::Structure)]
final class ReplaceVariable extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof VariableNode && $node->name instanceof Token && $node->name->text === '$a') {
			if ($context->report($node, 'Replace $a.')) {
				$node->replaceWith((new Builder)->expression('$b'));
			}
		}
	}
}


/** Reports every variable of the file in one callback and renames the ones it was allowed to. */
#[RuleInfo(Stage::Finishing)]
final class BatchRename extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [];
	}


	public function afterPass(RuleContext $context): void
	{
		foreach ($context->file->find(VariableNode::class) as $var) {
			if ($var->name instanceof Token && $var->name->text === '$a' && $context->report($var, 'Rename $a.')) {
				$var->name->setText('$b');
			}
		}
	}
}


/** Reports every variable of the file in one callback before it renames the ones it was allowed to. */
#[RuleInfo(Stage::Finishing)]
final class ReportThenRename extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [];
	}


	public function afterPass(RuleContext $context): void
	{
		$allowed = [];
		foreach ($context->file->find(VariableNode::class) as $var) {
			if ($var->name instanceof Token && $var->name->text === '$a' && $context->report($var, 'Rename $a.')) {
				$allowed[] = $var->name;
			}
		}

		foreach ($allowed as $name) {
			$name->setText('$b');
		}
	}
}


#[RuleInfo(Stage::Finishing)]
final class Toggle extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [];
	}


	public function afterPass(RuleContext $context): void
	{
		foreach ($context->file->find(VariableNode::class) as $var) {
			if ($var->name instanceof Token && $context->report($var, 'toggle.')) {
				$var->name->setText($var->name->text === '$a' ? '$b' : '$a');
			}
		}
	}
}


#[RuleInfo(Stage::Structure)]
final class RemoveStatement extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [ExpressionStatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof Node && $context->report($node, 'remove.')) {
			$node->remove();
		}
	}
}


#[RuleInfo(Stage::Structure)]
final class CountStatements extends NodeRule
{
	use ProjectDecision;

	/** @var list<string> */
	public static array $seen = [];


	public function getVisitedNodes(): array
	{
		return [ExpressionStatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		self::$seen[] = (string) $node;
	}


	public function afterPass(RuleContext $context): void
	{
		$context->passStorage['done'] = true;
		self::$seen[] = 'after';
	}
}


#[RuleInfo(Stage::Finishing)]
final class Thrower extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [];
	}


	public function beforePass(RuleContext $context): void
	{
		throw new RuntimeException('boom');
	}
}


/**
 * @param  list<Rule>  $rules
 * @param  bool|array<string, true>  $fixRisky
 * @return array{PhpSyntax\Nodes\FileNode, DressCode\Engine\PassResult}
 */
function run(
	string $code,
	array $rules,
	bool $strict = true,
	bool|array $fixRisky = false,
	?DressCode\Engine\Baseline $baseline = null,
): array
{
	$file = (new Parser)->parse($code);
	$registry = new Analyses\Registry;
	// the plan an IndentationRule built without values reads, every level at its default
	$registry->register(Analyses\IndentationPlan::class, fn(FileNode $file) => new Analyses\IndentationPlan($file, new Style));
	$runner = new PassLoop(new RulePlan($rules), $registry, new ReportPolicy(baseline: $baseline, fixRisky: $fixRisky, strict: $strict));
	$result = $runner->run($file, $code, 'test.php', new Style, Config::DefaultPhpVersion);
	return [$file, $result];
}


test('reports become violations with original positions and fingerprints', function () {
	[, $result] = run("<?php\n\t\$a = \$b;\n\$a;", [new ReportVariables]);
	Assert::count(3, $result->violations);
	[$a, $b, $a2] = $result->violations;
	Assert::same(
		['project.reportVariables', 'Variable $a.', 2, 2, Severity::Error, null],
		[$a->decision, $a->message, $a->line, $a->column, $a->severity, $a->derivedFrom],
	);
	Assert::same([2, 7], [$b->line, $b->column]);
	Assert::same([3, 1], [$a2->line, $a2->column]);
	Assert::notSame($a->fingerprint, $a2->fingerprint);
	Assert::same($a->fingerprint, run("<?php\n\t\$a = \$b;\n\$a;", [new ReportVariables])[1]->violations[0]->fingerprint);
	Assert::same(1, $result->passes);
	Assert::false($result->mutated);
});


test('a column counts the characters of its line from 1, a tab one of them, and a token a fix wrote has none', function () {
	$positions = fn(string $code, Rule ...$rules) => array_map(
		fn($violation) => [$violation->message, $violation->line, $violation->column],
		run($code, array_values($rules))[1]->violations,
	);
	Assert::same(
		[['Variable $a.', 2, 3], ['Variable $b.', 3, 10], ['Variable $c.', 5, 7]],
		$positions("<?php\n\t\t\$a;\n'žluť' . \$b;\n/* x\n y */ \$c;\n", new ReportVariables),
	);
	Assert::same([['Variable $a.', 2, 1], ['Variable $b.', 3, 3]], $positions("<?php\r\n\$a;\r\n  \$b;\r\n", new ReportVariables));

	// the variable the first rule writes has no place in the file as it was read, and stands on the line of the code
	Assert::same(
		[['Variable $b.', 2, null], ['Replace $a.', 2, 3]],
		$positions("<?php\nf(\$a);\n", new ReplaceVariable, new ReportVariables),
	);
});


test('a violation spans what was reported: the node, the trivia or a line of it, the whitespace of a gap', function () {
	$spans = fn(string $code, Rule ...$rules) => array_map(
		fn($violation) => [$violation->message, $violation->offset, $violation->length],
		run($code, array_values($rules))[1]->violations,
	);
	Assert::same(
		[['Comment.', 11, 6], ['Statement.', 18, 8]],
		$spans("<?php\n/* x\n yy */\n\$a = \$b;\n", new ReportStatementAndComment),
	);
	Assert::same([['Whitespace before the body.', 13, 2]], $spans("<?php\nif (\$a)  \$b;\n", new ReportBodySpace));

	// the gap spans the whitespace between the tokens, empty where there is none; a line the fix wrote has no place
	Assert::same([['Expected no line break before the statement.', 13, 2]], $spans("<?php\nif (\$a)\n\t\$b;\n", new JoinBody));
	Assert::same([['Expected a single space before the variable.', 13, 0]], $spans("<?php\nif (\$a)\$b;\n", new SpaceBody));
	Assert::same(
		[['Expected 1 blank line before the statement, 0 found.', null, null], ['Expected a line break before the statement.', 13, 1]],
		$spans("<?php\nif (\$a) \$b;\n", new BreakBody),
	);
});


test('the span of a violation is no part of its fingerprint, which a baseline holds', function () {
	[$moved, $here] = array_map(fn($code) => run($code, [new ReportVariables])[1]->violations[0], ["<?php\n\n\n\$a;\n", "<?php\n\$a;\n"]);
	Assert::same([8, 6], [$moved->offset, $here->offset]);
	Assert::same($here->fingerprint, $moved->fingerprint);
});


test('a token whose text a fix changed has no place in the file as read, and the remaining violation spans the fixed text', function () {
	[, $result] = run("<?php\n\$a;\n", [new RenameA, new ReportVariables]);
	Assert::same(
		[['Rename $a.', 6, 2], ['Variable $b.', null, null]],
		array_map(fn($v) => [$v->message, $v->offset, $v->length], $result->violations),
	);
	Assert::same([['Variable $b.', 6, 2]], array_map(fn($v) => [$v->message, $v->offset, $v->length], $result->remaining ?? []));
});


test('a violation keeps the lines of the file as read its span covers, and so does what the fix leaves', function () {
	$lines = fn(array $violations) => array_map(fn($v) => [$v->message, $v->line, $v->originalLines], $violations);
	[, $result] = run("<?php\n/* x\n yy */\n\$a\n\t= 1;\n", [new ReportStatementAndComment]);
	Assert::same([['Comment.', 3, [3, 3]], ['Statement.', 4, [4, 5]]], $lines($result->violations));

	// the variable the fix wrote has no place in the file as read and takes the line of the code beside it
	[, $result] = run("<?php\n\$a = [\n\t1,\n];\nf(\$b);\n", [new ReplaceVariable, new ReportVariables]);
	Assert::same([['Variable $b.', 2, [2, 2]], ['Variable $b.', 5, [5, 5]]], $lines($result->remaining ?? []));
});


test('an analysis asked for again after a mutation is a new one, while one of the pass stays for the pass', function () {
	$rule = new AsksAcrossMutation;
	[$file] = run("<?php\n\$a;\n\$a;\n", [$rule]);
	Assert::same("<?php\n\$b;\n\$b;\n", (string) $file);
	Assert::count(2, $rule->seen);
	foreach ($rule->seen as [$resolver, $snapshot, $resolverAfter, $snapshotAfter]) {
		Assert::notSame($resolver, $resolverAfter);
		Assert::same($snapshot, $snapshotAfter);
	}

	// both renames are of the first pass, so they share its analysis; the pass after them asks for none
	Assert::same($rule->seen[0][1], $rule->seen[1][1]);
});


test('a violation says whether its rule has a fix for it', function () {
	[$file, $result] = run("<?php\n\$a;\n", [new RenameA, new ReportVariables]);
	Assert::same("<?php\n\$b;\n", (string) $file);
	Assert::same(
		[['project.renameA', true], ['project.reportVariables', false]],
		array_map(fn($v) => [$v->decision, $v->fixable], $result->violations),
	);
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
	Assert::same(['Rename $a.', 'Variable $b.'], array_map(fn($v) => $v->message, $result->violations));
	// a report on a tree another rule changed is not derived by that alone
	Assert::same([null, null], array_map(fn($v) => $v->derivedFrom, $result->violations));
});


test('a violation about the gap of a line the fixer opened is derived from the one the break was written for', function () {
	// the break goes in first, the blank lines wait for the next pass and their report is derived
	[$file, $result] = run("<?php\nif (\$a) \$b;\n", [new BreakBody]);
	Assert::same("<?php\nif (\$a)\n\n\t\$b;\n", (string) $file);
	Assert::count(2, $result->violations);
	$byMessage = array_column($result->violations, null, 'message');
	$break = $byMessage['Expected a line break before the statement.'];
	$blank = $byMessage['Expected 1 blank line before the statement, 0 found.'];
	Assert::same([null, $break->fingerprint], [$break->derivedFrom, $blank->derivedFrom]);
	Assert::same([2, 2], [$break->line, $blank->line]);
});


test('a violation about the whitespace of a line the fixer closed is derived from the one the break was taken out for', function () {
	[$file, $result] = run("<?php\nif (\$a)\n\t\$b;\n", [new JoinBody, new ReportBodySpace]);
	Assert::same("<?php\nif (\$a) \$b;\n", (string) $file);
	$byMessage = array_column($result->violations, null, 'message');
	$join = $byMessage['Expected no line break before the statement.'];
	Assert::null($join->derivedFrom);
	Assert::same($join->fingerprint, $byMessage['Whitespace before the body.']->derivedFrom);
});


test('a line that follows a move the run refused is not derived from it', function () {
	$code = "<?php\n\t\$a;\n\t\$b;\n\t\$c;\n";
	foreach ([false, true] as $fixRisky) {
		[, $result] = run($code, [new MoveChain], fixRisky: $fixRisky);
		$byMessage = array_column($result->violations, null, 'message');
		Assert::same(
			$fixRisky ? $byMessage['Move $b.']->fingerprint : null,
			$byMessage['Move $c.']->derivedFrom,
			$fixRisky ? 'allowed' : 'refused',
		);
	}
});


test('a line placed by the line of its construct follows that line, and its ancestor is the first', function () {
	// $b is wrong on its own; the inner if is wrong on its own, and $d and the inner brace count from it
	$code = "<?php\nif (\$a) {\n\$b;\nif (\$c) {\n\$d;\n}\n}\n";
	[$file, $result] = run($code, [new IndentationRule]);
	Assert::same("<?php\nif (\$a) {\n\t\$b;\n\tif (\$c) {\n\t\t\$d;\n\t}\n}\n", (string) $file);
	[$b, $if, $d, $brace] = $result->violations;
	Assert::same([3, 4, 5, 6], array_map(fn($v) => $v->line, $result->violations));
	Assert::same(
		[null, null, $if->fingerprint, $if->fingerprint],
		[$b->derivedFrom, $if->derivedFrom, $d->derivedFrom, $brace->derivedFrom],
	);

	// the line the fixer opened is the ancestor of what is placed by it, however deep
	[$file, $result] = run("<?php\nif (\$a) foreach (\$c as \$x) {\n\$d;\n}\n", [new BreakBody, new IndentationRule]);
	Assert::same("<?php\nif (\$a)\n\n\tforeach (\$c as \$x) {\n\t\t\$d;\n\t}\n", (string) $file);
	Assert::count(4, $result->violations);
	$break = null;
	foreach ($result->violations as $violation) {
		if (str_starts_with($violation->message, 'Expected a line break')) {
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
	Assert::same($byMessage['Expected a line break before the statement.']->fingerprint, $byMessage['The line of $b.']->derivedFrom);

	// a line nobody opened leaves such a report its own, and the report is no move the lines below count from
	[, $result] = run("<?php\n\t\$a;\n\t\$b;\n\t\$c;\n", [new ReportStatementLine, new MoveChain], fixRisky: true);
	$byMessage = array_column($result->violations, null, 'message');
	Assert::null($byMessage['The line of $b.']->derivedFrom);
	Assert::same($byMessage['Move $b.']->fingerprint, $byMessage['Move $c.']->derivedFrom);
});


test('suppression stops the fix', function () {
	[$file, $result] = run("<?php\n\$a; // dresscode:ignore project.renameA\n\$a;", [new RenameA]);
	Assert::same("<?php\n\$a; // dresscode:ignore project.renameA\n\$b;", (string) $file);
	Assert::count(1, $result->violations);
});


test('a rule may fix a violation the baseline holds, and the contract holds', function () {
	$code = "<?php\n\$a;\n\$a;\n";
	[, $result] = run($code, [new RenameA]);
	$baseline = DressCode\Engine\Baseline::fromViolations(['test.php' => [$result->violations[0]]]);

	[$file, $result] = run($code, [new RenameA], baseline: $baseline);
	Assert::same("<?php\n\$b;\n\$b;\n", (string) $file);
	Assert::same([3], array_map(fn($v) => $v->line, $result->violations));
	Assert::count(1, $result->baselined);
	Assert::same([], $result->warnings);
});


test('contract violations: silent mutation and mutation after a suppressed report', function () {
	Assert::exception(
		fn() => run('<?php $a; ', [new SilentMutation]),
		RuleException::class,
		'Rule `SilentMutation` failed in `test.php`: It changed the file without reporting a violation.',
	);
	[, $result] = run('<?php $a; ', [new SilentMutation], strict: false);
	Assert::same(['Rule `SilentMutation` is faulty: it changed the file without reporting a violation.'], $result->warnings);
	Assert::exception(
		fn() => run("<?php\n\$x; // dresscode:ignore\n", [new Stubborn]),
		RuleException::class,
		'%a%changed the file although `report()` returned `false`.',
	);

	// one callback, one report silenced and the others fixed: what the rule wrote it wrote for the others
	$code = "<?php\n\$a;\n\$a; // dresscode:ignore project.batchRename\n\$a;\n";
	[$file, $result] = run($code, [new BatchRename]);
	Assert::same("<?php\n\$b;\n\$a; // dresscode:ignore project.batchRename\n\$b;\n", (string) $file);
	Assert::same([], $result->warnings);
	Assert::count(2, $result->violations);

	// a rule reporting everything before it fixes what it was allowed, the last report silenced
	$code = "<?php\n\$a;\n\$a; // dresscode:ignore project.reportThenRename\n";
	[$file, $result] = run($code, [new ReportThenRename]);
	Assert::same("<?php\n\$b;\n\$a; // dresscode:ignore project.reportThenRename\n", (string) $file);
	Assert::same([], $result->warnings);
});


test('an analysis a claim asks for that its rule does not declare is a contract violation of a strict run', function () {
	$code = "<?php\nif (\$a)\n\t\$b;\n";
	Assert::exception(
		fn() => run($code, [new UndeclaredGapAnalysis]),
		RuleException::class,
		'Rule `UndeclaredGapAnalysis` failed in `test.php`: It asks for analysis `PhpSyntax\Analyses\NameResolver`, which it does not name in `RuleInfo::$analyses`.',
	);
	[$file] = run($code, [new UndeclaredGapAnalysis], strict: false);
	Assert::same("<?php\nif (\$a) \$b;\n", (string) $file);
});


test('a reason without a risk is a contract violation of a strict run, and is left out of the others', function () {
	Assert::exception(
		fn() => run('<?php $a;', [new ReasonWithoutRisk]),
		RuleException::class,
		'Rule `ReasonWithoutRisk` failed in `test.php`: It reported `because` without a `risk`, which it explains.',
	);
	[, $result] = run('<?php $a;', [new ReasonWithoutRisk], strict: false);
	Assert::count(1, $result->violations);
	Assert::null($result->violations[0]->because);
});


test('a cycle is reported with the rules involved and a diff', function () {
	$e = Assert::exception(
		fn() => run("<?php\n\$a;\n", [new Toggle]),
		ConvergenceException::class,
		'Rule `Toggle` does not converge in `test.php`.',
	);
	Assert::type(ConvergenceException::class, $e);
	Assert::match("--- test.php\n+++ test.php\n@@ -1,2 +1,2 @@\n <?php\n-\$b;\n+\$a;\n", $e->diff);

	$e = Assert::exception(
		fn() => run("<?php\n\$a;\n", [new RenameA, new RenameA('$b', '$a')]),
		ConvergenceException::class,
		'Rule `RenameA` does not converge in `test.php`.',
	);
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
	$e = Assert::exception(fn() => run('<?php', [new Thrower]), RuleException::class, 'Rule `Thrower` failed in `test.php`: boom');
	Assert::type(RuntimeException::class, $e?->getPrevious());
});


test('a risky occurrence is reported and left alone, and the safe one beside it is fixed either way', function () {
	foreach ([false, true] as $riskyFirst) {
		$order = $riskyFirst ? 'risky first' : 'safe first';
		[$file, $result] = run("<?php\n\$a + \$b;\n", [new RenamePair($riskyFirst)]);
		Assert::same("<?php\n\$A + \$b;\n", (string) $file, $order);
		Assert::same([], $result->warnings, $order);
		Assert::count(2, $result->violations);
		Assert::same([null, Risk::TypeUnknown], array_map(fn($v) => $v->risk, $result->violations), $order);
		Assert::same([false, true], array_map(fn($v) => $v->refused, $result->violations), $order);
		Assert::same([true, true], array_map(fn($v) => $v->fixable, $result->violations), $order);
		Assert::same([null, 'b may be anything'], array_map(fn($v) => $v->because, $result->violations), $order);
		Assert::same([Severity::Error, Severity::Warning], array_map(fn($v) => $v->severity, $result->violations), $order);
	}
});


test('with the fixes allowed the risky occurrence is fixed and says it was risky', function () {
	[$file, $result] = run("<?php\n\$a + \$b;\n", [new RenamePair], fixRisky: true);
	Assert::same("<?php\n\$A + \$B;\n", (string) $file);
	Assert::count(2, $result->violations);
	Assert::same([null, Risk::TypeUnknown], array_map(fn($v) => $v->risk, $result->violations));
	Assert::same([false, false], array_map(fn($v) => $v->refused, $result->violations));
	Assert::same([Severity::Error, Severity::Error], array_map(fn($v) => $v->severity, $result->violations));
});


#[RuleInfo(Stage::Structure)]
final class RiskyRule extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			$node instanceof VariableNode
			&& $node->name instanceof Token
			&& $node->name->text === '$a'
			&& $context->report($node, 'Rename $a.', risk: Risk::BehaviorChanges)
		) {
			$node->name->setText('$b');
		}
	}
}


test('a decision the project names in fixRisky has its risky fixes made without the switch of the run, and no other one does', function () {
	[$file, $result] = run("<?php\n\$a;\n", [new RiskyRule], fixRisky: ['project.risky' => true]);
	Assert::same("<?php\n\$b;\n", (string) $file);
	Assert::false($result->violations[0]->refused);
	[$file, $result] = run("<?php\n\$a;\n", [new RiskyRule], fixRisky: ['project.other' => true]);
	Assert::same("<?php\n\$a;\n", (string) $file);
	Assert::true($result->violations[0]->refused);
	Assert::same(Severity::Error, $result->violations[0]->severity); // a change of what the code does stays an error
});


#[RuleInfo(Stage::Structure)]
final class ReportWithoutFix extends NodeRule
{
	use ProjectDecision;

	public function __construct(
		private readonly bool $ignoresAnswer = false,
	) {
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			$node instanceof VariableNode
			&& $node->name instanceof Token
			&& $node->name->text === '$a'
			&& ($context->report($node, 'Report $a.', risk: Risk::BehaviorChanges, fixable: false) || $this->ignoresAnswer)
		) {
			$node->name->setText('$b');
		}
	}
}


test('a report without a fix is never risky, never refused and never lets the rule mutate', function () {
	foreach ([false, true] as $fixRisky) {
		[$file, $result] = run("<?php\n\$a;\n", [new ReportWithoutFix], fixRisky: $fixRisky);
		Assert::same("<?php\n\$a;\n", (string) $file);
		Assert::same([[null, false]], array_map(fn($v) => [$v->risk, $v->refused], $result->violations));
	}

	Assert::exception(
		fn() => run("<?php\n\$a;\n", [new ReportWithoutFix(ignoresAnswer: true)], fixRisky: true),
		RuleException::class,
		'Rule `ReportWithoutFix` failed in `test.php`: It changed the file after a report with `fixable: false`.',
	);
});
