<?php declare(strict_types=1);

/**
 * The rules a resolution of the decisions builds: a requirement or a fact taking effect turns its rule on, a
 * parameter never does, and a rule that cannot run here leaves its decisions a reason.
 */

use DressCode\Config\{Catalogue, DecisionResolver, InactiveReason, Layer, LayerKind, RuleBuilder};
use DressCode\{Decision, DecisionKind, Domain, NodeRule, RuleInfo, Stage, Values};
use DressCode\Domains\Names;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


abstract class TestRule extends NodeRule
{
	public function getVisitedNodes(): array
	{
		return [];
	}
}


#[RuleInfo(Stage::Structure)]
final class DebugRule extends TestRule
{
	/** @var list<string> */
	public array $functions = [];


	public static function getDecisions(): array
	{
		return [
			new Decision('correctness.debugOutput.statement', Domain::state(), 'A statement printing debug output'),
			new Decision('correctness.debugOutput.functions', new Names, 'The functions printing it', kind: DecisionKind::Parameter, default: ['var_dump']),
		];
	}


	public function configure(Values $values): void
	{
		$this->functions = $values->get('correctness.debugOutput.functions')->getNames();
	}
}


#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.0'])]
final class MatchRule extends TestRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.match', Domain::adopted(), '`match` for a `switch`')];
	}
}


#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.5'])]
final class PipeRule extends TestRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.pipe', Domain::adopted(), 'The pipe for nested calls')];
	}
}


#[RuleInfo(Stage::Structure, typesRequired: true, analyses: [DressCode\Analyses\Types::class])]
final class OverrideRule extends TestRule
{
	public static function getDecisions(): array
	{
		return [new Decision('classes.overriding.signature', Domain::adopted(), 'Types as the ancestor declares them')];
	}
}


#[RuleInfo(Stage::Structure, decisions: ['upgrading.syntax.firstClassCallables'])]
final class CallableRule extends TestRule
{
}


#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.4'], decisions: ['upgrading.syntax.firstClassCallables'])]
final class PartialCallableRule extends TestRule
{
}


#[RuleInfo(Stage::Structure)]
final class GuardRule extends TestRule
{
	public static function getDecisions(): array
	{
		return [new Decision('namespaces.functions', new Names, 'The functions the namespaces declare', kind: DecisionKind::Fact, default: [])];
	}
}


$catalogue = Catalogue::fromRules([
	PipeRule::class, DebugRule::class, MatchRule::class, OverrideRule::class, CallableRule::class, PartialCallableRule::class, GuardRule::class,
]);
$everything = [[
	new Layer(LayerKind::Configuration),
	[
		'correctness' => ['debugOutput' => ['statement' => 'forbidden', 'functions' => ['dump']]],
		'upgrading' => ['match' => 'adopted', 'pipe' => 'adopted', 'syntax' => ['firstClassCallables' => 'adopted']],
		'classes' => ['overriding' => ['signature' => 'adopted']],
	],
]];


test('a rule that cannot run here leaves its decisions a reason', function () use ($catalogue, $everything) {
	$resolved = new DecisionResolver($catalogue, phpTarget: '8.2')->resolve($everything);
	Assert::same(InactiveReason::Php, $resolved['upgrading.pipe']->inactive);
	Assert::null($resolved['upgrading.match']->inactive);
	Assert::same(InactiveReason::Types, $resolved['classes.overriding.signature']->inactive);
	Assert::same(InactiveReason::NameResolution, $resolved['namespaces.functions']->inactive);

	$typed = new DecisionResolver($catalogue, phpTarget: '8.5', typesAnalyzed: true, certainNames: true)->resolve($everything);
	Assert::null($typed['upgrading.pipe']->inactive);
	Assert::null($typed['classes.overriding.signature']->inactive);
	Assert::null($typed['namespaces.functions']->inactive);
});


test('the rules taking effect are built in the order of the registration and configured with the values', function () use ($catalogue, $everything) {
	$resolver = new DecisionResolver($catalogue, phpTarget: '8.2', certainNames: true);
	$resolved = $resolver->resolve($everything);
	$rules = RuleBuilder::buildFromDecisions($resolver, $resolved, $resolver->createValues($resolved));
	Assert::same([DebugRule::class, MatchRule::class, CallableRule::class, GuardRule::class], array_map(fn($rule) => $rule::class, $rules));
	Assert::type(DebugRule::class, $rules[0]);
	Assert::same(['dump'], $rules[0]->functions);
});


test('a parameter alone builds no rule', function () use ($catalogue) {
	$resolver = new DecisionResolver($catalogue);
	$resolved = $resolver->resolve([[new Layer(LayerKind::Configuration), ['correctness' => ['debugOutput' => ['functions' => ['dump']]]]]]);
	Assert::same([], RuleBuilder::buildFromDecisions($resolver, $resolved, $resolver->createValues($resolved)));
});


test('a decision of several rules turns on every one that can run, and takes effect while one does', function () use ($catalogue, $everything) {
	$resolver = new DecisionResolver($catalogue, phpTarget: '8.4', certainNames: true);
	$resolved = $resolver->resolve($everything);
	Assert::same([CallableRule::class, PartialCallableRule::class], $resolved['upgrading.syntax.firstClassCallables']->rules);
	Assert::null($resolved['upgrading.syntax.firstClassCallables']->inactive);
	$rules = RuleBuilder::buildFromDecisions($resolver, $resolved, $resolver->createValues($resolved));
	Assert::same([DebugRule::class, MatchRule::class, CallableRule::class, PartialCallableRule::class, GuardRule::class], array_map(fn($rule) => $rule::class, $rules));

	$old = Catalogue::fromRules([PartialCallableRule::class]);
	Assert::same(InactiveReason::Php, new DecisionResolver($old, phpTarget: '8.2')->resolve([[new Layer(LayerKind::Configuration), ['upgrading' => ['syntax' => ['firstClassCallables' => 'adopted']]]]])['upgrading.syntax.firstClassCallables']->inactive);
});
