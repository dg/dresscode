<?php declare(strict_types=1);

use DressCode\Config\{Catalogue, DecisionResolver, InactiveReason, Layer, LayerKind};
use DressCode\{ConfigurationException, Decision, Domain, NodeRule, RuleInfo, Stage};
use DressCode\Domains\{Map, Names, Shapes, Words};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


#[RuleInfo(Stage::Structure)]
final class QualificationRule extends NodeRule
{
	public static function getDecisions(): array
	{
		$words = new Words(['bare' => '', 'imported' => '', 'backslashed' => '']);
		return [
			new Decision('qualification.globalFunction.normally', $words, 'A global function'),
			new Decision('qualification.globalFunction.optimizedByCompiler', $words, 'A function the compiler optimizes'),
			new Decision('qualification.globalFunction.except', new Map(new Words(['imported' => '', 'backslashed' => ''])), 'The names over the two', parameter: true, default: []),
			new Decision('qualification.globalClass', $words, 'A global class'),
		];
	}


	public function getVisitedNodes(): array
	{
		return [];
	}
}


#[RuleInfo(Stage::Structure)]
final class ResolvedDebugRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [
			new Decision('correctness.debugOutput', Domain::state(), 'A statement printing debug output'),
			new Decision('correctness.debugOutputFunctions', new Names, 'The functions printing it', parameter: true, default: ['var_dump']),
			new Decision('spacing.call', new Shapes(['compact' => ['foo()', ''], 'spaced' => ['foo ()', '']]), 'The space before the parenthesis'),
		];
	}


	public function getVisitedNodes(): array
	{
		return [];
	}
}


$resolver = new DecisionResolver(new Catalogue([QualificationRule::class, ResolvedDebugRule::class]));


test('a path the catalogue does not know is named with the nearest known one', function () use ($resolver) {
	Assert::exception(fn() => $resolver->resolve([[new Layer(LayerKind::Configuration), ['spacing' => ['cal' => 'foo()']]]]), ConfigurationException::class, 'Key `spacing.cal` is unknown; write `spacing.call`.');
	Assert::exception(fn() => $resolver->resolve([[new Layer(LayerKind::Configuration), ['spacng' => ['call' => 'foo()']]]]), ConfigurationException::class, 'Key `spacng` is unknown; write `spacing`.');
	Assert::exception(fn() => $resolver->resolve([[new Layer(LayerKind::Configuration), ['qualification' => 'bare']]]), ConfigurationException::class, 'Key `qualification` holds keys of its own; write a map of them or `keep`.');
	Assert::exception(fn() => $resolver->resolve([[new Layer(LayerKind::Configuration), ['spacing' => ['call' => 'foo( )']]]]), ConfigurationException::class, 'Key `spacing.call` does not take `foo( )`; write `compact` (`"foo()"`), `spaced` (`"foo ()"`) or `keep`.');
});


test('the layers merge by path, each value with its origin, a decision nobody named takes its default', function () use ($resolver) {
	$resolved = $resolver->resolve([
		[new Layer(LayerKind::Preset, 'perCs'), ['spacing' => ['call' => 'foo()'], 'correctness' => ['debugOutput' => 'forbidden']]],
		[new Layer(LayerKind::Configuration), ['spacing' => ['call' => 'foo ()']]],
	]);
	Assert::same('spaced', $resolved['spacing.call']->value->getShape());
	Assert::same(['perCs', 'the configuration'], array_map(fn($value) => $value->origin?->describe(), $resolved['spacing.call']->layers));
	Assert::null($resolved['spacing.call']->inactive);
	Assert::same(['var_dump'], $resolved['correctness.debugOutputFunctions']->value->getNames());
	Assert::same(InactiveReason::Keep, $resolved['qualification.globalClass']->inactive, 'a requirement nobody named requires nothing');
	Assert::null($resolved['correctness.debugOutputFunctions']->inactive, 'a parameter is never inactive by its value');
});


test('a parameter alone turns nothing on', function () use ($resolver) {
	$resolved = $resolver->resolve([[new Layer(LayerKind::Configuration), ['correctness' => ['debugOutputFunctions' => ['dump']]]]]);
	Assert::same(InactiveReason::Keep, $resolved['correctness.debugOutput']->inactive);
	Assert::same(['dump'], $resolved['correctness.debugOutputFunctions']->value->getNames());
});


test('keep on a section is a tombstone of every key, a later layer brings back only what it names', function () use ($resolver) {
	$resolved = $resolver->resolve([
		[
			new Layer(LayerKind::Preset, 'perCs'),
			['qualification' => [
				'globalFunction' => ['normally' => 'bare', 'optimizedByCompiler' => 'imported', 'except' => ['assert' => 'backslashed']],
				'globalClass' => 'imported',
			]],
		],
		[new Layer(LayerKind::Configuration), ['qualification' => 'keep']],
		[new Layer(LayerKind::Override, 'tests/'), ['qualification' => ['globalFunction' => ['normally' => 'imported']]]],
	]);
	Assert::same('imported', $resolved['qualification.globalFunction.normally']->value->getWord());
	Assert::same('the override for tests/', $resolved['qualification.globalFunction.normally']->value->origin?->describe());
	Assert::same(InactiveReason::Keep, $resolved['qualification.globalFunction.optimizedByCompiler']->inactive);
	Assert::same(InactiveReason::Keep, $resolved['qualification.globalClass']->inactive);
	Assert::same([], $resolved['qualification.globalFunction.except']->value->getEntries(), 'nothing comes back from under the tombstone');
	Assert::same('the configuration', $resolved['qualification.globalFunction.except']->value->origin?->describe());
});


test('a word of a structure is the word of each of its requirements, its parameters left as they are', function () use ($resolver) {
	$resolved = $resolver->resolve([[new Layer(LayerKind::Configuration), ['qualification' => ['globalFunction' => 'imported']]]]);
	Assert::same('imported', $resolved['qualification.globalFunction.normally']->value->getWord());
	Assert::same('imported', $resolved['qualification.globalFunction.optimizedByCompiler']->value->getWord());
	Assert::same([], $resolved['qualification.globalFunction.except']->layers);
	Assert::exception(
		fn() => $resolver->resolve([[new Layer(LayerKind::Configuration), ['qualification' => ['globalFunction' => 'local']]]]),
		ConfigurationException::class,
		'Key `qualification.globalFunction.normally` does not take `local`; %a%',
	);
});


test('the values carry the mask of the run without changing what was resolved', function () use ($resolver) {
	$resolved = $resolver->resolve([[new Layer(LayerKind::Configuration), ['spacing' => ['call' => 'foo()'], 'correctness' => ['debugOutput' => 'forbidden']]]]);
	$values = $resolver->createValues($resolved, ['spacing']);
	Assert::true($values->isSelected('spacing.call'));
	Assert::false($values->isSelected('correctness.debugOutput'));
	Assert::same('forbidden', $values->get('correctness.debugOutput')->getWord());
});
