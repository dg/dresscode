<?php declare(strict_types=1);

/**
 * The gap rules declare the sides of the slots they govern and the engine applies them in one walk:
 * a gap is decided by the stricter of its two sides, a slot has one owner, closures may share a slot as
 * long as they take turns abstaining, and what a rule leaves alone stays.
 */

use DressCode\{Analyses, Claim, Config, ConfigurationException, Decision, Domain, Gap, GapRule, Line, Rule, RuleInfo, Rules, Space, Stage, Style};
use DressCode\Config\{PluginRegistry, RuleBuilder};
use DressCode\Engine\{FileProcessor, ReportPolicy};
use PhpSyntax\Nodes;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';


/**
 * @param list<Rule> $rules
 * @return array{?string, list<string>}  the output and the violations
 */
function apply(array $rules, string $code): array
{
	$registry = new PluginRegistry;
	foreach ($rules as $rule) {
		$registry->registerRule($rule::class);
	}

	$processor = new FileProcessor($rules, new Analyses\Registry, Config::DefaultPhpVersion, new Style("\t", "\n"), policy: new ReportPolicy($registry->expandSuppressedName(...), strict: true));
	$result = $processor->process('gaps.php', $code);
	Assert::null($result->syntaxError);
	Assert::same([], $result->warnings);
	return [$result->output, array_map(fn($v) => "$v->line: $v->message [$v->decision]", $result->violations)];
}


test('the stricter side of a gap wins: nothing before the semicolon, whatever the keyword asks for after itself', function () {
	[$output, $violations] = apply([
		RuleBuilder::createRule(Rules\Whitespace\ConstructSpacingRule::class, [
			'spacing.languageConstruct' => 'spaced', 'spacing.controlKeyword' => 'spaced', 'spacing.connectingKeyword' => 'spaced',
			'spacing.functionKeyword' => 'spaced', 'spacing.modifier' => 'spaced',
			'spacing.fnKeyword' => 'compact', 'multiline.expressionBelowReturn' => 'forbidden',
		]),
		RuleBuilder::createRule(Rules\Whitespace\SemicolonSpacingRule::class, [
			'spacing.beforeSemicolon' => 'compact',
			'spacing.afterSemicolon' => 'spaced',
			'multiline.semicolonOnOwnLine' => 'forbidden',
		]),
	], "<?php\nreturn  ;\nreturn  \$a ;\n");
	Assert::same("<?php\nreturn;\nreturn \$a;\n", $output);
	Assert::same([
		'2: Expected no whitespace before the semicolon. [spacing.beforeSemicolon]',
		'3: Expected a single space after the `return` keyword. [spacing.languageConstruct]',
		'3: Expected no whitespace before the semicolon. [spacing.beforeSemicolon]',
	], $violations);
});


test('a claim on the whitespace and the line reports the line under its own decision where it names one', function () {
	[$output, $violations] = apply([
		RuleBuilder::createRule(Rules\Whitespace\SemicolonSpacingRule::class, [
			'spacing.beforeSemicolon' => 'compact',
			'multiline.semicolonOnOwnLine' => 'forbidden',
		]),
	], "<?php\nfoo(\n\t1\n) ;\nbar(\n\t2\n)\n;\n");
	Assert::same("<?php\nfoo(\n\t1\n);\nbar(\n\t2\n);\n", $output);
	Assert::same([
		'4: Expected no whitespace before the semicolon. [spacing.beforeSemicolon]',
		'8: Expected no line break before the semicolon. [multiline.semicolonOnOwnLine]',
	], $violations);
});


test('a keyword alone knows what closes it', function () {
	[$output] = apply([RuleBuilder::createRule(Rules\Whitespace\ConstructSpacingRule::class, [
		'spacing.languageConstruct' => 'spaced', 'spacing.controlKeyword' => 'spaced', 'spacing.connectingKeyword' => 'spaced',
		'spacing.functionKeyword' => 'spaced', 'spacing.modifier' => 'spaced',
		'spacing.fnKeyword' => 'compact', 'multiline.expressionBelowReturn' => 'forbidden',
	])], "<?php\nreturn;\nswitch (\$a) {\n\tdefault:\n}\n");
	Assert::same("<?php\nreturn;\nswitch (\$a) {\n\tdefault:\n}\n", $output);
});


test('two rules may govern one operator when each abstains where the other decides', function () {
	[$output, $violations] = apply([
		RuleBuilder::createRule(Rules\Expressions\BinaryOperatorSpacingRule::class, ['spacing.binaryOperator' => 'spaced', 'spacing.binaryOperatorAlignment' => 'none']),
		RuleBuilder::createRule(Rules\Expressions\ConcatenationSpacingRule::class, ['spacing.concatenation' => 'compact']),
	], "<?php\n\$a = \$b  +  \$c . \$d;\n");
	Assert::same("<?php\n\$a = \$b + \$c.\$d;\n", $output);
	Assert::same([
		'2: Expected a single space before the `+` operator. [spacing.binaryOperator]',
		'2: Expected a single space after the `+` operator. [spacing.binaryOperator]',
		'2: Expected no whitespace before the `.` operator. [spacing.concatenation]',
		'2: Expected no whitespace after the `.` operator. [spacing.concatenation]',
	], $violations);
});


test('a plain claim and a closure deciding one component of one slot are refused at the first gap they meet on', function () {
	$other = new #[RuleInfo(Stage::Formatting)] class extends GapRule {
		public static function getDecisions(): array
		{
			return [new Decision('project.returnSpacing', Domain::state(), 'No space after `return`')];
		}


		public function getClaims(): array
		{
			return ['*' => ['returnKeyword' => [null, Claim::noSpace()]]];
		}
	};
	Assert::exception(
		fn() => apply([
			RuleBuilder::createRule(Rules\Whitespace\ConstructSpacingRule::class, [
				'spacing.languageConstruct' => 'spaced', 'spacing.controlKeyword' => 'spaced',
				'spacing.connectingKeyword' => 'spaced', 'spacing.functionKeyword' => 'spaced',
				'spacing.modifier' => 'spaced', 'spacing.fnKeyword' => 'compact',
				'multiline.expressionBelowReturn' => 'forbidden',
			]),
			$other,
		], "<?php\nreturn \$a;\n"),
		ConfigurationException::class,
		'Rules `DressCode\Rules\Whitespace\ConstructSpacingRule` and `%a%` both govern the whitespace after `*.returnKeyword`.',
	);
});


test('the whitespace of a string, of a comment and of a line ending is not a gap', function () {
	[$output, $violations] = apply([
		RuleBuilder::createRule(Rules\Whitespace\ParenthesesSpacingRule::class, ['spacing.parentheses' => 'compact']),
		RuleBuilder::createRule(Rules\Expressions\ObjectOperatorSpacingRule::class, ['spacing.objectOperator' => 'compact']),
	], "<?php\nfoo( \"{\$a -> b}\" );\nfoo( // c\n\t\$a\n);\n\$x ?>\n<b> ?> </b>\n");
	Assert::same("<?php\nfoo(\"{\$a -> b}\");\nfoo( // c\n\t\$a\n);\n\$x ?>\n<b> ?> </b>\n", $output);
	Assert::count(2, $violations);
});


test('whitespace a mutation left in the leading trivia of a token is part of the gap before it', function () {
	// renames the returned variable and moves the whitespace after `return` into its leading trivia, where it does
	// not belong, once
	$misplacing = new #[RuleInfo(Stage::Structure)] class extends DressCode\NodeRule {
		public bool $done = false;


		public static function getDecisions(): array
		{
			return [new Decision('project.misplacing', Domain::state(), 'The returned variable is renamed')];
		}


		public function getVisitedNodes(): array
		{
			return [Nodes\Statement\ReturnNode::class];
		}


		public function enter(PhpSyntax\Node|PhpSyntax\Token $node, DressCode\RuleContext $context): void
		{
			$first = $node instanceof Nodes\Statement\ReturnNode ? $node->expression?->getFirstToken() : null;
			$space = $node instanceof Nodes\Statement\ReturnNode ? $node->returnKeyword->getTrailingSpace() : null;
			if (!$this->done && $first !== null && $space !== null && $space !== '' && $context->report($node, 'Misplaced.')) {
				$this->done = true;
				$node->returnKeyword->setTrailingSpace('');
				$first->setText('$b')->setLeadingTrivia([new PhpSyntax\Trivia(PhpSyntax\Trivia::Whitespace, $space)]);
			}
		}
	};
	$spacing = RuleBuilder::createRule(Rules\Whitespace\ConstructSpacingRule::class, [
		'spacing.languageConstruct' => 'spaced', 'spacing.controlKeyword' => 'keep', 'spacing.connectingKeyword' => 'keep',
		'spacing.functionKeyword' => 'keep', 'spacing.modifier' => 'keep', 'spacing.fnKeyword' => 'keep', 'multiline.expressionBelowReturn' => 'keep',
	]);

	[$output, $violations] = apply([$misplacing, $spacing], "<?php\nreturn \$a;\n");
	Assert::same("<?php\nreturn \$b;\n", $output);
	Assert::same(['2: Misplaced. [project.misplacing]'], $violations);

	$misplacing->done = false;
	[$output, $violations] = apply([$misplacing, $spacing], "<?php\nreturn   \$a;\n");
	Assert::same("<?php\nreturn \$b;\n", $output);
	Assert::contains('2: Expected a single space after the `return` keyword. [spacing.languageConstruct]', $violations);
});


#[RuleInfo(Stage::Formatting)]
class ClaimingRule extends GapRule
{
	use ProjectDecision;

	public function __construct(
		/** @var array<string, array<string, array{mixed, mixed}>> */
		private array $gaps,
	) {
	}


	public function getClaims(): array
	{
		return $this->gaps;
	}
}


#[RuleInfo(Stage::Formatting)]
final class OtherClaimingRule extends ClaimingRule
{
}


/**
 * A rule made of claims alone, for the tests of the components; the class tells two of them apart.
 * @param array<string, array<string, array{mixed, mixed}>> $gaps
 */
function claiming(array $gaps, bool $other = false): Rule
{
	return $other ? new OtherClaimingRule($gaps) : new ClaimingRule($gaps);
}


test('a claim on the items of a list governs the blank lines above each of them, the open tag standing for the line above the first', function () {
	[$output, $violations] = apply(
		[claiming([Nodes\FileNode::class => ['statements:item' => [Claim::blankLines(1), null]]])],
		"<?php\nfoo();\n\n\nbar();\n",
	);
	Assert::same("<?php\n\nfoo();\n\nbar();\n", $output);
	Assert::match(<<<'XX'
		1: Expected 1 blank line before the statement, 0 found. [%a%]
		3: Expected 1 blank line before the statement, 2 found. [%a%]
		XX, implode("\n", $violations));
});


test('blank lines are counted above the comment that stands with the code below, and below the comment that stands with the code above a closing brace', function () {
	[$output, $violations] = apply(
		[claiming([Nodes\Statement\BlockNode::class => ['statements:item' => [Claim::blankLines(1), null], 'closeBrace' => [Claim::blankLines(0), null]]])],
		"<?php\nfunction f()\n{\n\n\t// a\n\tfoo();\n\t// b\n\n\tbar();\n\n\t// end\n\n}\n",
	);
	Assert::same("<?php\nfunction f()\n{\n\n\t// a\n\tfoo();\n\n\t// b\n\n\tbar();\n\n\t// end\n}\n", $output);
	Assert::same([
		'7: Expected 1 blank line before the statement, 0 found. [project.claiming]',
		'12: Expected no blank line before the closing brace, 1 found. [project.claiming]',
	], $violations);
});


test('the two sides of a break meet in the range both allow, and the count is reported under the claim it violates', function () {
	$rule = claiming(['*' => ['statements:item' => [
		fn(Gap $gap) => $gap->token->is('return') ? Claim::blankLines([1, null]) : null,
		fn(Gap $gap) => $gap->token->is('}') ? Claim::blankLines([0, 1]) : null,
	]]]);
	[$output, $violations] = apply([$rule], "<?php\nif (\$a) {\n}\nreturn;\nif (\$b) {\n}\n\n\n\nreturn;\n");
	Assert::same("<?php\nif (\$a) {\n}\n\nreturn;\nif (\$b) {\n}\n\nreturn;\n", $output);
	Assert::same([
		'4: Expected at least 1 blank line before the return, 0 found. [project.claiming]',
		'7: Expected at most 1 blank line after the if, 3 found. [project.claiming]',
	], $violations);
});


test('two sides that exclude each other: the narrower wins, the one before the token on a tie', function () {
	$rule = claiming(['*' => ['statements:item' => [
		fn(Gap $gap) => $gap->token->is('return') ? Claim::blankLines(2) : null,
		fn(Gap $gap) => $gap->token->is('}') ? Claim::blankLines(0) : null,
	]]]);
	[$output, $violations] = apply([$rule], "<?php\nif (\$a) {\n}\nreturn;\n");
	Assert::same("<?php\nif (\$a) {\n}\n\n\nreturn;\n", $output);
	Assert::same(['4: Expected 2 blank lines before the return, 0 found. [project.claiming]'], $violations);
});


test('a required line break is put in, after the comment on the line of what closes; blank lines wait for the next pass', function () {
	$rule = claiming([Nodes\FileNode::class => ['endOfFile' => [new Claim(line: Line::Next, blankLines: 0), null]]]);
	foreach ([
		"<?php\n\$a;" => "<?php\n\$a;\n",
		"<?php\n\$a;   " => "<?php\n\$a;\n",
		"<?php\n\$a;\n// end" => "<?php\n\$a;\n// end\n",
		"<?php\n\$a;\n\n// end\n\n\n" => "<?php\n\$a;\n\n// end\n",
		"<?php\n\$a;\n\n\n" => "<?php\n\$a;\n",
		"<?php\n" => "<?php\n",
		"<?php \$a ?>\n\n" => "<?php \$a ?>\n\n",
	] as $code => $expected) {
		[$output] = apply([$rule], $code);
		Assert::same($expected, $output, json_encode($code));
	}

	[, $violations] = apply([$rule], "<?php\n\$a;\n// end");
	Assert::same(['3: Expected a line break before the end of the file. [project.claiming]'], $violations);
});


test('the gaps of a construct a closure decided about once are one violation, placed and silenced as the first of them', function () {
	$rule = claiming([Nodes\Expression\ArrayNode::class => ['items:item' => [
		fn(Gap $gap) => ($array = $gap->value->parent?->parent) instanceof Nodes\Expression\ArrayNode
			? $gap->once($array, Claim::nextLine(...))
			: null,
		null,
	]]]);
	[$output, $violations] = apply([$rule], "<?php\n\$a = [1, 2, 3];\n\$b = [\n\t4, 5];\n// dresscode:ignore ClaimingRule\n\$c = [6, 7];\n");
	Assert::same("<?php\n\$a = [\n\t1,\n\t2,\n\t3];\n\$b = [\n\t4,\n\t5];\n// dresscode:ignore ClaimingRule\n\$c = [6, 7];\n", $output);
	Assert::same([
		'2: Expected a line break before the array item. [project.claiming]',
		'4: Expected a line break before the array item. [project.claiming]',
	], $violations);

	// a claim decided at every gap on its own is a violation at every gap
	$rule = claiming([Nodes\Expression\ArrayNode::class => ['items:item' => [fn(Gap $gap) => Claim::nextLine(), null]]]);
	[, $violations] = apply([$rule], "<?php\n\$a = [1, 2];\n");
	Assert::same([
		'2: Expected a line break before the array item. [project.claiming]',
		'2: Expected a line break before the array item. [project.claiming]',
	], $violations);
});


test('a forbidden line break is taken out, and a comment that has nowhere to go leaves it reported', function () {
	$rule = claiming([Nodes\ElseNode::class => ['elseKeyword' => [new Claim(Space::Single, line: Line::Same), null]]]);
	[$output, $violations] = apply([$rule], "<?php\nif (\$a) {\n}\nelse {\n}\nif (\$b) {\n} // c\nelse {\n}\n");
	Assert::same("<?php\nif (\$a) {\n} else {\n}\nif (\$b) {\n} // c\nelse {\n}\n", $output);
	Assert::same([
		'4: Expected no line break before the `else` keyword. [project.claiming]',
		'8: Expected no line break before the `else` keyword. [project.claiming]',
	], $violations);
});


test('two plain claims on one side share a slot when they claim different components', function () {
	[$output] = apply([
		claiming([Nodes\Statement\BlockNode::class => ['closeBrace' => [Claim::noSpace(), null]]]),
		claiming([Nodes\Statement\BlockNode::class => ['closeBrace' => [Claim::blankLines(0), null]]], other: true),
	], "<?php\nfunction f()\n{\n\tfoo();\n\n}\nfunction g() { }\n");
	Assert::same("<?php\nfunction f()\n{\n\tfoo();\n}\nfunction g() {}\n", $output);

	Assert::exception(
		fn() => apply([
			claiming([Nodes\Statement\BlockNode::class => ['closeBrace' => [Claim::blankLines(1), null]]]),
			claiming([Nodes\Statement\BlockNode::class => ['closeBrace' => [Claim::blankLines(0), null]]], other: true),
		], "<?php\n"),
		ConfigurationException::class,
		'Rules `ClaimingRule` and `OtherClaimingRule` both govern the whitespace before `PhpSyntax\Nodes\Statement\BlockNode.closeBrace`.',
	);
});


test('a claim on a slot the tree has not is refused, so a renamed slot cannot turn a rule off in silence', function () {
	$refuses = fn(array $gaps, string $message) => Assert::exception(
		fn() => apply([claiming($gaps)], "<?php\n"),
		ConfigurationException::class,
		"Rule `ClaimingRule` claims the whitespace of `$message.",
	);

	$refuses(
		[Nodes\Statement\BlockNode::class => ['openParen' => [Claim::noSpace(), null]]],
		'PhpSyntax\\Nodes\\Statement\\BlockNode.openParen`, but it has no slot `openParen`',
	);
	$refuses(
		['*' => ['thereIsNoSuchSlot' => [Claim::noSpace(), null]]],
		'*.thereIsNoSuchSlot`, but no node has a slot `thereIsNoSuchSlot`',
	);
	$refuses(
		[Nodes\Statement\BlockNode::class => ['openBrace:item' => [Claim::noSpace(), null]]],
		'PhpSyntax\\Nodes\\Statement\\BlockNode.openBrace:item`, but the slot holds no list to have items',
	);
	$refuses(
		[Nodes\Statement\BlockNode::class => ['statements:separator' => [Claim::noSpace(), null]]],
		'PhpSyntax\\Nodes\\Statement\\BlockNode.statements:separator`, but the slot holds no list to have separators',
	);
	$refuses(
		[Nodes\Expression\ArrayNode::class => ['*:item' => [Claim::noSpace(), null]]],
		'PhpSyntax\\Nodes\\Expression\\ArrayNode.*:item`, but every slot is claimed only as `*.*:item` or `*.*:separator`',
	);
	$refuses(
		['*' => ['*' => [Claim::noSpace(), null]]],
		'*.*`, but every slot is claimed only as `*.*:item` or `*.*:separator`',
	);

	// the slots a node really has, wildcards included, are claimed as they always were
	Assert::noError(fn() => apply([claiming([
		Nodes\Statement\BlockNode::class => ['statements:item' => [Claim::noSpace(), null]],
		Nodes\ArgumentListNode::class => ['items:separator' => [Claim::noSpace(), null]],
		'*' => ['*:item' => [Claim::noSpace(), null], 'openBrace' => [Claim::noSpace(), null]],
	])], "<?php\n"));
});


test('the blank lines below a comment are a component of their own, and the line break after the open tag is a claim', function () {
	$rule = claiming([Nodes\FileNode::class => ['statements:item' => [new Claim(line: Line::Next, blankLines: 1, blankLinesBelowComment: 0), null]]]);
	[$output, $violations] = apply([$rule], "<?php foo();\n// a\n\n// b\n\nbar();\n");
	Assert::same("<?php\n\nfoo();\n\n// a\n\n// b\nbar();\n", $output);
	Assert::same([
		'1: Expected 1 blank line before the statement, 0 found. [project.claiming]',
		'1: Expected a line break after the opening tag. [project.claiming]',
		'2: Expected 1 blank line before the statement, 0 found. [project.claiming]',
		'4: Expected no blank line after the comment, 1 found. [project.claiming]',
	], $violations);
});
