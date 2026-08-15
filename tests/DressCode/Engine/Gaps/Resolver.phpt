<?php declare(strict_types=1);

/**
 * The gap rules declare the sides of the slots they govern and the engine applies them in one walk:
 * a gap is decided by the stricter of its two sides, a slot has one owner, closures may share a slot as
 * long as they take turns abstaining, and what a rule leaves alone stays.
 */

use DressCode\Analyses;
use DressCode\Claim;
use DressCode\Config;
use DressCode\Config\PresetResolver;
use DressCode\Config\RuleRegistry;
use DressCode\ConfigurationException;
use DressCode\Engine\FileProcessor;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\Line;
use DressCode\Rule;
use DressCode\RuleInfo;
use DressCode\Rules;
use DressCode\Space;
use DressCode\Stage;
use PhpSyntax\Nodes;
use PhpSyntax\Style;
use Tester\Assert;


require __DIR__ . '/../../../bootstrap.php';


/**
 * @param list<Rule> $rules
 * @return array{?string, list<string>}  the output and the violations
 */
function apply(array $rules, string $code): array
{
	$registry = new RuleRegistry;
	$processor = new FileProcessor($rules, new Analyses\Registry, $registry->resolveNames(...), Config::DefaultPhpVersion, new Style("\t", "\n"), strict: true);
	$result = $processor->process('gaps.php', $code);
	Assert::null($result->error);
	Assert::same([], $result->warnings);
	return [$result->output, array_map(fn($v) => "$v->line: $v->message [$v->ruleName]", $result->violations)];
}


test('the stricter side of a gap wins: nothing before the semicolon, whatever the keyword asks for after itself', function () {
	[$output, $violations] = apply([
		PresetResolver::createRule(Rules\Whitespace\ConstructSpacingRule::class),
		PresetResolver::createRule(Rules\Whitespace\SemicolonSpacingRule::class),
	], "<?php\nreturn  ;\nreturn  \$a ;\n");
	Assert::same("<?php\nreturn;\nreturn \$a;\n", $output);
	Assert::same([
		'2: No whitespace before the semicolon [dresscode/semicolon-spacing]',
		'3: A single space after the return keyword [dresscode/construct-spacing]',
		'3: No whitespace before the semicolon [dresscode/semicolon-spacing]',
	], $violations);
});


test('a keyword alone knows what closes it', function () {
	[$output] = apply([PresetResolver::createRule(Rules\Whitespace\ConstructSpacingRule::class)], "<?php\nreturn;\nswitch (\$a) {\n\tdefault:\n}\n");
	Assert::same("<?php\nreturn;\nswitch (\$a) {\n\tdefault:\n}\n", $output);
});


test('two rules may govern one operator when each abstains where the other decides', function () {
	[$output, $violations] = apply([
		PresetResolver::createRule(Rules\Expressions\BinaryOperatorSpacingRule::class, ['spacing' => 'single']),
		PresetResolver::createRule(Rules\Expressions\ConcatSpacingRule::class, ['spacing' => 'none']),
	], "<?php\n\$a = \$b  +  \$c . \$d;\n");
	Assert::same("<?php\n\$a = \$b + \$c.\$d;\n", $output);
	Assert::same([
		'2: A single space before the + operator [dresscode/binary-operator-spacing]',
		'2: A single space after the + operator [dresscode/binary-operator-spacing]',
		'2: No whitespace before the . operator [dresscode/concat-spacing]',
		'2: No whitespace after the . operator [dresscode/concat-spacing]',
	], $violations);
});


test('a plain claim and a closure deciding one component of one slot are refused at the first gap they meet on', function () {
	$other = new #[RuleInfo('test/other-spacing', Stage::Formatting)] class extends GapRule {
		public function getClaims(): array
		{
			return ['*' => ['returnKeyword' => [null, Claim::none()]]];
		}
	};
	Assert::exception(
		fn() => apply([PresetResolver::createRule(Rules\Whitespace\ConstructSpacingRule::class), $other], "<?php\nreturn \$a;\n"),
		ConfigurationException::class,
		'Rules dresscode/construct-spacing and test/other-spacing both govern the whitespace after *.returnKeyword.',
	);
});


test('the whitespace of a string, of a comment and of a line ending is not a gap', function () {
	[$output, $violations] = apply([
		PresetResolver::createRule(Rules\Whitespace\ParenthesesSpacingRule::class),
		PresetResolver::createRule(Rules\Expressions\ObjectOperatorSpacingRule::class),
	], "<?php\nfoo( \"{\$a -> b}\" );\nfoo( // c\n\t\$a\n);\n\$x ?>\n<b> ?> </b>\n");
	Assert::same("<?php\nfoo(\"{\$a -> b}\");\nfoo( // c\n\t\$a\n);\n\$x ?>\n<b> ?> </b>\n", $output);
	Assert::count(2, $violations);
});


/**
 * A rule made of claims alone, for the tests of the components; the name tells two of them apart.
 * @param array<string, array<string, array{mixed, mixed}>> $gaps
 */
function claiming(array $gaps, bool $other = false): Rule
{
	return $other
		? new #[RuleInfo('test/other', Stage::Formatting)] class ($gaps) extends GapRule {
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
		: new #[RuleInfo('test/claiming', Stage::Formatting)] class ($gaps) extends GapRule {
			public function __construct(
				/** @var array<string, array<string, array{mixed, mixed}>> */
				private array $gaps,
			) {
			}


			public function getClaims(): array
			{
				return $this->gaps;
			}
		};
}


test('a claim on the items of a list governs the blank lines above each of them, the open tag standing for the line above the first', function () {
	[$output, $violations] = apply(
		[claiming([Nodes\FileNode::class => ['statements:item' => [Claim::blank(1), null]]])],
		"<?php\nfoo();\n\n\nbar();\n",
	);
	Assert::same("<?php\n\nfoo();\n\nbar();\n", $output);
	Assert::same([
		'1: Expected 1 blank line before the statement, 0 found [test/claiming]',
		'3: Expected 1 blank line before the statement, 2 found [test/claiming]',
	], $violations);
});


test('blank lines are counted above the comment that stands with the code below, and below the comment that stands with the code above a closing brace', function () {
	[$output, $violations] = apply(
		[claiming([Nodes\Statement\BlockNode::class => ['statements:item' => [Claim::blank(1), null], 'closeBrace' => [Claim::blank(0), null]]])],
		"<?php\nfunction f()\n{\n\n\t// a\n\tfoo();\n\t// b\n\n\tbar();\n\n\t// end\n\n}\n",
	);
	Assert::same("<?php\nfunction f()\n{\n\n\t// a\n\tfoo();\n\n\t// b\n\n\tbar();\n\n\t// end\n}\n", $output);
	Assert::same([
		'7: Expected 1 blank line before the statement, 0 found [test/claiming]',
		'12: Expected 0 blank lines before the closing brace, 1 found [test/claiming]',
	], $violations);
});


test('the two sides of a break meet in the range both allow, and the count is reported under the claim it violates', function () {
	$rule = claiming(['*' => ['statements:item' => [
		fn(Gap $gap) => $gap->token->is('return') ? Claim::blank([1, null]) : null,
		fn(Gap $gap) => $gap->token->is('}') ? Claim::blank([0, 1]) : null,
	]]]);
	[$output, $violations] = apply([$rule], "<?php\nif (\$a) {\n}\nreturn;\nif (\$b) {\n}\n\n\n\nreturn;\n");
	Assert::same("<?php\nif (\$a) {\n}\n\nreturn;\nif (\$b) {\n}\n\nreturn;\n", $output);
	Assert::same([
		'4: Expected at least 1 blank line before the return, 0 found [test/claiming]',
		'7: Expected at most 1 blank line after the if, 3 found [test/claiming]',
	], $violations);
});


test('two sides that exclude each other: the narrower wins, the one before the token on a tie', function () {
	$rule = claiming(['*' => ['statements:item' => [
		fn(Gap $gap) => $gap->token->is('return') ? Claim::blank(2) : null,
		fn(Gap $gap) => $gap->token->is('}') ? Claim::blank(0) : null,
	]]]);
	[$output, $violations] = apply([$rule], "<?php\nif (\$a) {\n}\nreturn;\n");
	Assert::same("<?php\nif (\$a) {\n}\n\n\nreturn;\n", $output);
	Assert::same(['4: Expected 2 blank lines before the return, 0 found [test/claiming]'], $violations);
});


test('a required line break is put in, after the comment on the line of what closes; blank lines wait for the next pass', function () {
	$rule = claiming([Nodes\FileNode::class => ['endOfFile' => [new Claim(line: Line::Next, blank: 0), null]]]);
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
	Assert::same(['3: A line break before the end of the file [test/claiming]'], $violations);
});


test('a forbidden line break is taken out, with the whitespace of the line the gap asks for', function () {
	$rule = claiming([Nodes\ElseNode::class => ['elseKeyword' => [new Claim(Space::Single, line: Line::Same), null]]]);
	[$output, $violations] = apply([$rule], "<?php\nif (\$a) {\n}\nelse {\n}\nif (\$b) {\n} // c\nelse {\n}\n");
	Assert::same("<?php\nif (\$a) {\n} else {\n}\nif (\$b) {\n} // c\nelse {\n}\n", $output);
	Assert::same(['4: No line break before the else keyword [test/claiming]'], $violations);
});


test('two plain claims on one side share a slot when they claim different components', function () {
	[$output] = apply([
		claiming([Nodes\Statement\BlockNode::class => ['closeBrace' => [Claim::none(), null]]]),
		claiming([Nodes\Statement\BlockNode::class => ['closeBrace' => [Claim::blank(0), null]]], other: true),
	], "<?php\nfunction f()\n{\n\tfoo();\n\n}\nfunction g() { }\n");
	Assert::same("<?php\nfunction f()\n{\n\tfoo();\n}\nfunction g() {}\n", $output);

	Assert::exception(
		fn() => apply([
			claiming([Nodes\Statement\BlockNode::class => ['closeBrace' => [Claim::blank(1), null]]]),
			claiming([Nodes\Statement\BlockNode::class => ['closeBrace' => [Claim::blank(0), null]]], other: true),
		], "<?php\n"),
		ConfigurationException::class,
		'Rules test/claiming and test/other both govern the whitespace before PhpSyntax\Nodes\Statement\BlockNode.closeBrace.',
	);
});


test('a claim on a slot the tree has not is refused, so a renamed slot cannot turn a rule off in silence', function () {
	$refuses = fn(array $gaps, string $message) => Assert::exception(
		fn() => apply([claiming($gaps)], "<?php\n"),
		ConfigurationException::class,
		"Rule test/claiming claims the whitespace of $message.",
	);

	$refuses(
		[Nodes\Statement\BlockNode::class => ['openParen' => [Claim::none(), null]]],
		"PhpSyntax\\Nodes\\Statement\\BlockNode.openParen, but it has no slot 'openParen'",
	);
	$refuses(
		['*' => ['thereIsNoSuchSlot' => [Claim::none(), null]]],
		"*.thereIsNoSuchSlot, but no node has a slot 'thereIsNoSuchSlot'",
	);
	$refuses(
		[Nodes\Statement\BlockNode::class => ['openBrace:item' => [Claim::none(), null]]],
		'PhpSyntax\\Nodes\\Statement\\BlockNode.openBrace:item, but the slot holds no list to have items',
	);
	$refuses(
		[Nodes\Statement\BlockNode::class => ['statements:separator' => [Claim::none(), null]]],
		'PhpSyntax\\Nodes\\Statement\\BlockNode.statements:separator, but the slot holds no list to have separators',
	);

	// the slots a node really has, wildcards included, are claimed as they always were
	Assert::noError(fn() => apply([claiming([
		Nodes\Statement\BlockNode::class => ['statements:item' => [Claim::none(), null]],
		Nodes\ArgumentListNode::class => ['items:separator' => [Claim::none(), null]],
		'*' => ['*:item' => [Claim::none(), null], 'openBrace' => [Claim::none(), null]],
	])], "<?php\n"));
});


test('the blank lines below a comment are a component of their own, and the line break after the open tag is a claim', function () {
	$rule = claiming([Nodes\FileNode::class => ['statements:item' => [new Claim(line: Line::Next, blank: 1, blankBelowComment: 0), null]]]);
	[$output, $violations] = apply([$rule], "<?php foo();\n// a\n\n// b\n\nbar();\n");
	Assert::same("<?php\n\nfoo();\n\n// a\n\n// b\nbar();\n", $output);
	Assert::same([
		'1: Expected 1 blank line before the statement, 0 found [test/claiming]',
		'1: A line break after the opening tag [test/claiming]',
		'2: Expected 1 blank line before the statement, 0 found [test/claiming]',
		'4: Expected 0 blank lines after the comment, 1 found [test/claiming]',
	], $violations);
});
