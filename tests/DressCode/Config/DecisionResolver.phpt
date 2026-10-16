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
			new Decision('classes.visibility.member', Domain::state('required'), 'The visibility of a member'),
			new Decision(
				'classes.visibility.publicWithSet',
				Domain::state('required', 'forbidden'),
				'The `public` beside a set visibility',
			),
			new Decision('controlFlow.switch.emptyCase', Domain::state('forbidden'), 'A `case` with no statement'),
			new Decision('controlFlow.switch.defaultLast', Domain::state('required'), 'The `default` last'),
			new Decision(
				'correctness.debugOrder',
				new Names(['first' => '', 'second' => '', 'third' => ''], ordered: true),
				'The order of the functions',
				kind: DecisionKind::Parameter,
				default: ['first', 'second'],
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
			new Decision(
				'multiline.items.minimum',
				new DressCode\Domains\Count,
				'The items a list spreads from',
				kind: DecisionKind::Parameter,
				default: 2,
			),
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
		'Key `qualification` is a section, which takes `keep` alone; write the values at its keys.',
	);
	Assert::exception(
		fn() => $resolver->resolve([[new Layer(LayerKind::Configuration), ['spacing' => ['call' => 'foo( )']]]]),
		ConfigurationException::class,
		"Key `spacing.call` does not take `foo( )`; write `'foo()'`, `'foo ()'` or `keep`.",
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


test('`...` in a list stands for the list below it, the default included, in its place', function () use ($resolver) {
	$resolve = fn(array ...$trees) => $resolver->resolve(array_values(array_map(
		fn(array $tree) => [new Layer(LayerKind::Configuration), $tree],
		$trees,
	)));
	Assert::same(
		['var_dump', 'dump'],
		$resolve(['correctness' => ['debugOutputFunctions' => ['...', 'dump']]])['correctness.debugOutputFunctions']->value->getNames(),
	);
	// a set merges what both give
	Assert::same(
		['dd', 'var_dump', 'dump'],
		$resolve(
			['correctness' => ['debugOutputFunctions' => ['var_dump', 'dump']]],
			['correctness' => ['debugOutputFunctions' => ['dd', '...', 'dump']]],
		)['correctness.debugOutputFunctions']->value->getNames(),
	);
	Assert::same(
		['perLine', 'fill'],
		$resolve(
			['multiline' => ['items' => ['array' => 'perLine']]],
			['multiline' => ['items' => ['array' => ['...', 'fill']]]],
		)['multiline.items.array']->value->getWords(),
	);
	// an order and a tolerance refuse an item given twice
	Assert::exception(
		fn() => $resolve(['correctness' => ['debugOrder' => ['...', 'first']]]),
		ConfigurationException::class,
		'Key `correctness.debugOrder` gives `first` itself and in the list `...` stands for; write it once.',
	);
	Assert::exception(
		fn() => $resolve(['correctness' => ['debugOutputFunctions' => ['...', 'dump', '...']]]),
		ConfigurationException::class,
		'Key `correctness.debugOutputFunctions` does not take `["...","dump","..."]`, `...` standing for the list below it once only; write a list of names.',
	);
	Assert::exception(
		fn() => $resolve(['multiline' => ['items' => ['...', 'fill']]]),
		ConfigurationException::class,
		'Key `multiline.items` holds `...` in a list, which every decision under it would read as another list; write it at the decision.',
	);
});


test('a map written with `!` after its key replaces the map of the layers below with its entries', function () use ($resolver) {
	$resolved = $resolver->resolve([
		[new Layer(LayerKind::Preset, 'perCs'), ['qualification' => ['renamed' => ['strlen' => 'imported']]]],
		[new Layer(LayerKind::Configuration), ['qualification' => ['renamed!' => ['count' => 'imported']]]],
	]);
	Assert::same(['count'], array_keys($resolved['qualification.renamed']->value->getEntries()));
	Assert::exception(
		fn() => $resolver->resolve([[new Layer(LayerKind::Configuration), ['qualification' => ['renamed!' => []]]]]),
		ConfigurationException::class,
		'Key `qualification.renamed!` replaces a map of the layers below with the entries it writes, and `qualification.renamed` is no such map or gives none; write `qualification.renamed` without `!`, or `keep` to withdraw the map.',
	);
	Assert::exception(
		fn() => $resolver->resolve([[new Layer(LayerKind::Configuration), ['qualification' => ['globalClass!' => 'bare']]]]),
		ConfigurationException::class,
		'Key `qualification.globalClass!` replaces %a%',
	);
});


test(
	'a group gives its value to the decisions of a closed vocabulary under it, a word of presence to every one of them',
	function () use ($resolver) {
		$resolve = fn(array $tree) => $resolver->resolve([[new Layer(LayerKind::Configuration), $tree]]);
		$resolved = $resolve(['classes' => ['visibility' => 'required'], 'multiline' => ['items' => ['array' => 'fill']]]);
		Assert::same(['required', 'required'], [
			$resolved['classes.visibility.member']->value->getWord(),
			$resolved['classes.visibility.publicWithSet']->value->getWord(),
		]);
		Assert::same([true, false], [$resolved['classes.visibility.member']->cascaded, $resolved['multiline.items.array']->cascaded]);
		Assert::exception(
			fn() => $resolve(['classes' => ['visibility' => 'forbidden']]),
			ConfigurationException::class,
			'Key `classes.visibility` gives `forbidden`, which `classes.visibility.member` does not take; a word of presence on a group holds for every decision under it, so write the decisions one by one.',
		);
		Assert::exception(
			fn() => $resolve(['multiline' => ['items' => 3]]),
			ConfigurationException::class,
			'Key `multiline.items` gives `3`, which only a parameter under it takes; write it at the parameter.',
		);
		Assert::exception(
			fn() => $resolve(['multiline' => ['items' => []]]),
			ConfigurationException::class,
			'Key `multiline.items` holds keys of its own, and an empty list or map says nothing of them; write the keys, or `keep`.',
		);
		Assert::exception(
			fn() => $resolve(['controlFlow' => ['switch' => 'forbidden']]),
			ConfigurationException::class,
			'Key `controlFlow.switch` names one thing several decisions ask about, so `forbidden` would read as one about the thing itself; write it at the decisions, or `keep` at the key.',
		);
		Assert::same(InactiveReason::Keep, $resolve(['controlFlow' => ['switch' => 'keep']])['controlFlow.switch.emptyCase']->inactive);
	},
);
