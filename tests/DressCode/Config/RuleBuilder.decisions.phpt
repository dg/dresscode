<?php declare(strict_types=1);

/**
 * The rules a resolution of the decisions builds: a requirement or a fact taking effect turns its rule on, a
 * parameter never does, and a rule that cannot run here leaves its decisions a reason.
 */

use DressCode\Config\{Catalogue, DecisionResolver, InactiveReason, Layer, LayerKind, RuleBuilder};
use DressCode\{Decision, Domain, NodeRule, RuleInfo, Stage, Values};
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
			new Decision('correctness.debugOutput', Domain::state(), 'A statement printing debug output'),
			new Decision('correctness.debugOutputFunctions', new Names, 'The functions printing it', parameter: true, default: ['var_dump']),
		];
	}


	public function configure(Values $values): void
	{
		$this->functions = $values->get('correctness.debugOutputFunctions')->getNames();
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


#[RuleInfo(Stage::Structure)]
final class GuardRule extends TestRule
{
	public static function getDecisions(): array
	{
		return [new Decision('namespaces.functions', new Names, 'The functions the namespaces declare', fact: true, default: [])];
	}
}


$catalogue = Catalogue::fromRules([
	PipeRule::class, DebugRule::class, MatchRule::class, GuardRule::class,
]);
$everything = [[
	new Layer(LayerKind::Configuration),
	[
		'correctness' => ['debugOutput' => 'forbidden', 'debugOutputFunctions' => ['dump']],
		'upgrading' => ['match' => 'adopted', 'pipe' => 'adopted'],
	],
]];


test('a rule that cannot run here leaves its decisions a reason', function () use ($catalogue, $everything) {
	$resolved = new DecisionResolver($catalogue, phpTarget: '8.2')->resolve($everything);
	Assert::same(InactiveReason::Php, $resolved['upgrading.pipe']->inactive);
	Assert::null($resolved['upgrading.match']->inactive);
	Assert::same(InactiveReason::NameResolution, $resolved['namespaces.functions']->inactive);

	$runnable = new DecisionResolver($catalogue, phpTarget: '8.5', certainNames: true)->resolve($everything);
	Assert::null($runnable['upgrading.pipe']->inactive);
	Assert::null($runnable['namespaces.functions']->inactive);
});


test('the rules taking effect are built in the order of the registration and configured with the values', function () use ($catalogue, $everything) {
	$resolver = new DecisionResolver($catalogue, phpTarget: '8.2', certainNames: true);
	$resolved = $resolver->resolve($everything);
	$rules = RuleBuilder::buildFromDecisions($resolver, $resolved, $resolver->createValues($resolved));
	Assert::same([DebugRule::class, MatchRule::class, GuardRule::class], array_map(fn($rule) => $rule::class, $rules));
	Assert::type(DebugRule::class, $rules[0]);
	Assert::same(['dump'], $rules[0]->functions);
});


test('a parameter alone builds no rule', function () use ($catalogue) {
	$resolver = new DecisionResolver($catalogue);
	$resolved = $resolver->resolve([[new Layer(LayerKind::Configuration), ['correctness' => ['debugOutputFunctions' => ['dump']]]]]);
	Assert::same([], RuleBuilder::buildFromDecisions($resolver, $resolved, $resolver->createValues($resolved)));
});
