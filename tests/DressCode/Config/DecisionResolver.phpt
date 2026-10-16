<?php declare(strict_types=1);

use DressCode\Config\{Catalogue, DecisionResolver, InactiveReason, Layer, LayerKind};
use DressCode\{ConfigurationException, Decision, DecisionKind, Domain, NodeRule, RuleInfo, Stage};
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
			new Decision(
				'qualification.globalFunction.except',
				new Map(new Words(['imported' => '', 'backslashed' => ''])),
				'The names over the two',
				kind: DecisionKind::Parameter,
				default: [],
			),
			new Decision('qualification.globalClass', $words, 'A global class'),
			new Decision('qualification.renamed', new Map(new Words(['imported' => ''])), 'The names written otherwise'),
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
			new Decision(
				'correctness.debugOutputFunctions',
				new Names,
				'The functions printing it',
				kind: DecisionKind::Parameter,
				default: ['var_dump'],
			),
			new Decision(
				'spacing.call',
				new Shapes(['compact' => ['foo()', ''], 'spaced' => ['foo ()', '']]),
				'The space before the parenthesis',
			),
		];
	}


	public function getVisitedNodes(): array
	{
		return [];
	}
}


#[RuleInfo(Stage::Formatting)]
final class ResolvedLayoutRule extends NodeRule
{
	public static function getDecisions(): array
	{
		$shapes = new Words(['perLine' => '', 'fill' => ''], tolerance: true);
		return [
			new Decision('multiline.items.array', $shapes, 'The items of an array'),
			new Decision('multiline.items.argument', $shapes, 'The arguments'),
			new Decision('multiline.items.for', new Words(['perLine' => ''], tolerance: true), 'The sections of `for`'),
			new Decision('multiline.items.logical', $shapes, 'The operands of a boolean operator'),
		];
	}


	public function getVisitedNodes(): array
	{
		return [];
	}
}


$resolver = new DecisionResolver(new Catalogue([QualificationRule::class, ResolvedDebugRule::class, ResolvedLayoutRule::class]));


test('a path the catalogue does not know is named with the nearest known one', function () use ($resolver) {
	Assert::exception(
		fn() => $resolver->resolve([[new Layer(LayerKind::Configuration), ['spacing' => ['cal' => 'foo()']]]]),
		ConfigurationException::class,
		'Key `spacing.cal` is unknown; write `spacing.call`.',
	);
	Assert::exception(
		fn() => $resolver->resolve([[new Layer(LayerKind::Configuration), ['spacng' => ['call' => 'foo()']]]]),
		ConfigurationException::class,
		'Key `spacng` is unknown; write `spacing`.',
	);
	Assert::exception(
		fn() => $resolver->resolve([[new Layer(LayerKind::Configuration), ['native_function_invocation' => true]]]),
		ConfigurationException::class,
		'Key `native_function_invocation` is unknown. It is the name of a rule of another tool, covered by %a%; `dresscode import` translates a configuration of another tool.',
	);
	Assert::exception(
		fn() => $resolver->resolve([[new Layer(LayerKind::Configuration), ['qualification' => 'bare']]]),
		ConfigurationException::class,
		'Key `qualification` holds keys of its own; write a map of them or `keep`.',
	);
	Assert::exception(
		fn() => $resolver->resolve([[new Layer(LayerKind::Configuration), ['spacing' => ['call' => 'foo( )']]]]),
		ConfigurationException::class,
		'Key `spacing.call` does not take `foo( )`; write `compact` (`"foo()"`), `spaced` (`"foo ()"`) or `keep`.',
	);
});


test('the layers merge by path, each value with its origin, a decision nobody named takes its default', function () use ($resolver) {
	$resolved = $resolver->resolve([
		[
			new Layer(LayerKind::Preset, 'perCs'),
			['spacing' => ['call' => 'foo()'], 'correctness' => ['debugOutput' => 'forbidden']],
		],
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
	Assert::true($resolved['qualification.renamed']->value->isKept(), 'a map is kept as a whole too');
	Assert::same('the configuration', $resolved['qualification.globalFunction.except']->value->origin?->describe());
});


test('a word of a structure is the word of each of its decisions that takes it', function () use ($resolver) {
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


test('a list of a structure gives each decision the words it takes, in their order', function () use ($resolver) {
	$resolved = $resolver->resolve([[new Layer(LayerKind::Configuration), ['multiline' => ['items' => ['fill', 'perLine']]]]]);
	Assert::same(['fill', 'perLine'], $resolved['multiline.items.array']->value->getWords());
	Assert::same(['perLine'], $resolved['multiline.items.for']->value->getWords());
	Assert::exception(
		fn() => $resolver->resolve([[new Layer(LayerKind::Configuration), ['multiline' => ['items' => ['perLine', 'fil']]]]]),
		ConfigurationException::class,
		'Key `multiline.items.array` does not take `fil`; %a%',
	);
	Assert::exception(
		fn() => $resolver->resolve([[new Layer(LayerKind::Configuration), ['multiline' => ['items' => ['fill']]]]]),
		ConfigurationException::class,
		'Key `multiline.items` leaves `multiline.items.for` none of its values; write a value for `multiline.items.for` of its own.',
	);
	Assert::exception(
		fn() => $resolver->resolve([[new Layer(LayerKind::Configuration), ['multiline' => ['items' => ['perLine', 'keep']]]]]),
		ConfigurationException::class,
		'Key `multiline.items` holds `keep` in a list; `keep` stands alone.',
	);
});


test('a kind of places gives its value to its places, a place the same map names winning', function () use ($resolver) {
	$resolved = $resolver->resolve([
		[new Layer(LayerKind::Preset, 'perCs'), ['multiline' => ['items' => ['logical' => 'fill', 'argument' => 'fill']]]],
		[new Layer(LayerKind::Configuration), ['multiline' => ['items' => ['argument' => 'perLine', 'lists' => ['perLine', 'fill']]]]],
	]);
	Assert::same(['perLine', 'fill'], $resolved['multiline.items.array']->value->getWords());
	Assert::same(['perLine'], $resolved['multiline.items.for']->value->getWords());
	Assert::same(['perLine'], $resolved['multiline.items.argument']->value->getWords());
	Assert::same(['fill'], $resolved['multiline.items.logical']->value->getWords(), 'a place of another kind keeps the layer below');
	Assert::exception(
		fn() => $resolver->resolve([[new Layer(LayerKind::Configuration), ['qualification' => ['lists' => 'bare']]]]),
		ConfigurationException::class,
		'Key `qualification.lists` is unknown%a%',
	);
});


test('the values carry the mask of the run without changing what was resolved', function () use ($resolver) {
	$resolved = $resolver->resolve([[
		new Layer(LayerKind::Configuration),
		['spacing' => ['call' => 'foo()'], 'correctness' => ['debugOutput' => 'forbidden']],
	]]);
	$values = $resolver->createValues($resolved, ['spacing']);
	Assert::true($values->isSelected('spacing.call'));
	Assert::false($values->isSelected('correctness.debugOutput'));
	Assert::same('forbidden', $values->get('correctness.debugOutput')->getWord());
});
