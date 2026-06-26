<?php declare(strict_types=1);

/**
 * The gate every report of a rule passes: a path the rule does not declare and a parameter are a mistake of the
 * rule in every mode, a requirement the run does not report records nothing and denies the fix.
 */

use DressCode\Analyses\Registry;
use DressCode\{Claim, Decision, Domain, GapRule, RuleContext, RuleInfo, Space, Stage, Style, Values};
use DressCode\Domains\{Count, Shapes};
use DressCode\Engine\{Fingerprints, Gate, ReportPolicy, Suppression};
use DressCode\Engine\Gaps\{DecidedClaim, Fixer};
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Parser;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


#[RuleInfo(Stage::Formatting)]
final class SpacingRule extends GapRule
{
	public static function getDecisions(): array
	{
		return [
			new Decision('spacing.call', new Shapes(['compact' => ['foo()', '']]), 'The space before the parenthesis'),
			new Decision('spacing.comma', new Shapes(['spaced' => ['$a, $b', '']]), 'The space around a comma'),
			new Decision('spacing.commaAlignment', Domain::state(), 'Tabs aligning a column stay', parameter: true, default: 'forbidden'),
		];
	}


	public function getClaims(): array
	{
		return [];
	}
}


/**
 * @param  array<string, mixed>  $raw
 * @param  ?list<string>  $selection
 */
function createContext(FileNode $file, array $raw, ?array $selection = null, bool $strict = false): RuleContext
{
	$decisions = [];
	foreach (SpacingRule::getDecisions() as $decision) {
		$decisions[$decision->path] = $decision;
	}

	$values = new Values($decisions, array_map(fn($path) => $decisions[$path]->accept($raw[$path]), array_combine(array_keys($raw), array_keys($raw))), $selection);
	return new RuleContext(
		$file,
		'a.php',
		new Style,
		'8.4',
		new Registry,
		Suppression::fromFile($file, fn() => []),
		new Fingerprints([]),
		policy: new ReportPolicy(strict: $strict),
		gate: Gate::fromValues(SpacingRule::getDecisions(), $values),
	);
}


$file = new Parser()->parse("<?php\nfoo(\$a,\$b);\n");
$token = $file->getFirstToken();


test('a selected requirement is reported, one the run does not report records nothing', function () use ($file, $token) {
	$context = createContext($file, ['spacing.call' => 'foo()', 'spacing.comma' => 'keep']);
	Assert::true($context->report($token, 'Expected no whitespace.', decision: 'spacing.call'));
	Assert::false($context->report($token, 'Expected a single space.', decision: 'spacing.comma'));
	Assert::count(1, $context->takeReports());
	Assert::true($context->isSilenced($token, decision: 'spacing.comma'));
	Assert::false($context->isSilenced($token, decision: 'spacing.call'));

	$narrowed = createContext($file, ['spacing.call' => 'foo()', 'spacing.comma' => 'spaced'], ['spacing.comma']);
	Assert::false($narrowed->report($token, 'Expected no whitespace.', decision: 'spacing.call'));
	Assert::false($narrowed->reportGap($token, $token, 'Expected no whitespace.', decision: 'spacing.call'));
	Assert::false($narrowed->hasReports());
});


test('a path the rule does not declare and a parameter are a mistake of the rule in every mode', function () use ($file, $token) {
	foreach ([false, true] as $strict) {
		$context = createContext($file, ['spacing.call' => 'foo()'], strict: $strict);
		Assert::exception(fn() => $context->report($token, 'Wrong.', decision: 'spacing.cast'), LogicException::class, 'It reported under `spacing.cast`, a decision it does not declare.');
		Assert::exception(fn() => $context->report($token, 'Wrong.', decision: 'spacing.commaAlignment'), LogicException::class, 'It reported under `spacing.commaAlignment`, a parameter, which reports nothing of its own.');
		Assert::exception(fn() => $context->report($token, 'Wrong.'), LogicException::class, 'It reported under no decision, which only a rule of one requirement may.');
		Assert::exception(fn() => $context->isSilenced($token, decision: 'spacing.cast'), LogicException::class);
		Assert::false($context->hasReports());
	}
});


test('the only requirement of a rule is the one a report without a path is under', function () use ($file, $token) {
	$decisions = ['blankLines.x' => new Decision('blankLines.x', new Count, 'Blank lines')];
	$values = new Values($decisions, ['blankLines.x' => $decisions['blankLines.x']->accept(1)]);
	$context = new RuleContext($file, 'a.php', new Style, '8.4', new Registry, Suppression::fromFile($file, fn() => []), new Fingerprints([]), gate: Gate::fromValues(array_values($decisions), $values));
	Assert::true($context->report($token, 'Wrong.'));
});


test('the engine reports a gap under the decision of the claim that decided it', function () use ($file) {
	$context = createContext($file, ['spacing.call' => 'foo()', 'spacing.comma' => 'spaced'], ['spacing.comma']);
	$fixer = new Fixer([SpacingRule::class => $context]);
	$comma = $file->find(PhpSyntax\Nodes\ArgumentListNode::class)[0]->getFirstToken()->getNext()?->getNext() ?? throw new LogicException;
	$next = $comma->getNext() ?? throw new LogicException;
	$rule = new SpacingRule;

	$fixer->takeSpace(new DecidedClaim($rule, Space::Single, $comma, Claim::singleSpace()->withDecision('spacing.call'), null, 'after'), $comma, $next, '');
	Assert::false($context->hasReports(), 'a claim of a decision narrowed away');

	$fixer->takeSpace(new DecidedClaim($rule, Space::Single, $comma, Claim::singleSpace()->withDecision('spacing.comma'), null, 'after'), $comma, $next, '');
	Assert::count(1, $context->takeReports());
	Assert::same(' ', $comma->getTrailingSpace());
});


test('a claim made for a decision keeps the rest of it', function () {
	$claim = new Claim(Space::None, because: 'the line is long')->withDecision('spacing.call');
	Assert::same('spacing.call', $claim->decision);
	Assert::same(Space::None, $claim->space);
	Assert::same('the line is long', $claim->because);
	Assert::null(Claim::noSpace()->decision);
});
