<?php declare(strict_types=1);

use DressCode\Config\{Layer, LayerKind};
use DressCode\{ConfigurationException, Decision, Domain, Values};
use DressCode\Domains\{Count, GrammarEntry, Map, Names, Shapes};
use Nette\Schema\Expect;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


/**
 * @param  array<string, mixed>  $raw
 * @param  ?list<string>  $selection
 */
function createValues(array $raw, ?array $selection = null): Values
{
	$decisions = [];
	foreach ([
		new Decision('spacing.call', new Shapes(['compact' => ['foo()', ''], 'spaced' => ['foo ()', '']]), 'The space before the parenthesis'),
		new Decision('spacing.comma', new Shapes(['spaced' => ['$a, $b', '']]), 'The space around a comma'),
		new Decision('controlFlow.trailingIf', Domain::state(), 'An `if` ending a body becomes a guard'),
		new Decision('controlFlow.trailingIfMinStatements', new Count(1), 'The statements its body has at least', parameter: true, default: 2),
		new Decision('namespaces.functions', new Names, 'The functions the namespaces declare', fact: true, default: []),
	] as $decision) {
		$decisions[$decision->path] = $decision;
	}

	$values = [];
	foreach ($raw as $path => $value) {
		$values[$path] = $decisions[$path]->accept($value)->withOrigin(new Layer(LayerKind::Configuration));
	}

	return new Values($decisions, $values, $selection);
}


test('a value is what a layer said, a default where none did, keep for a requirement nobody named', function () {
	$values = createValues(['spacing.call' => 'foo ()']);
	Assert::same('spaced', $values->get('spacing.call')->getShape());
	Assert::same('the configuration', $values->get('spacing.call')->origin?->describe());
	Assert::false($values->isKept('spacing.call'));
	Assert::true($values->isKept('spacing.comma'));
	Assert::same([2, 2], $values->get('controlFlow.trailingIfMinStatements')->getCount());
	Assert::null($values->get('controlFlow.trailingIfMinStatements')->origin);
	Assert::exception(fn() => $values->get('spacing.cal'), ConfigurationException::class, 'Decision `spacing.cal` is unknown; write `spacing.call`.');
});


test('the mask selects requirements by path or prefix and changes no value', function () {
	$all = createValues(['spacing.call' => 'foo()', 'controlFlow.trailingIf' => 'forbidden']);
	Assert::true($all->isSelected('spacing.call'));
	Assert::true($all->isSelected('controlFlow.trailingIf'));
	Assert::false($all->isSelected('spacing.comma'), 'keep is never selected');
	Assert::false($all->isSelected('controlFlow.trailingIfMinStatements'), 'a parameter reports nothing of its own');

	$narrowed = createValues(['spacing.call' => 'foo()', 'controlFlow.trailingIf' => 'forbidden'], ['spacing']);
	Assert::true($narrowed->isSelected('spacing.call'));
	Assert::false($narrowed->isSelected('controlFlow.trailingIf'));
	Assert::same('forbidden', $narrowed->get('controlFlow.trailingIf')->getWord());
	Assert::same([2, 2], $narrowed->get('controlFlow.trailingIfMinStatements')->getCount());
	Assert::true($narrowed->isSelected('namespaces.functions'), 'a fact is guarded whatever the mask says');

	$prefix = createValues(['spacing.call' => 'foo()'], ['spacing.ca']);
	Assert::false($prefix->isSelected('spacing.call'), 'a prefix ends at a dot');
});


test('a map is read by its grammar without the entries a layer withdrew', function () {
	$decision = new Decision('upgrading.map', new Map(new GrammarEntry, grammar: Expect::arrayOf(Expect::string())), 'A map');
	$below = $decision->accept(['A' => 'old', 'B' => 'other'])->withOrigin(new Layer(LayerKind::Package, 'upgrading.neon', 'acme/lib'));
	$above = $decision->accept(['A' => 'keep'])->withOrigin(new Layer(LayerKind::Configuration));
	$values = new Values(['upgrading.map' => $decision], ['upgrading.map' => $decision->domain->merge($below, $above)]);
	Assert::same(['B' => 'other'], $values->readMap('upgrading.map'));
});


test('a map whose every entry a layer withdrew turns nothing on, its tombstones still merged with a map above', function () {
	$decision = new Decision('upgrading.map', new Map(new GrammarEntry, grammar: Expect::arrayOf(Expect::string())), 'A map');
	$tombstones = $decision->accept(['A' => 'keep'])->withOrigin(new Layer(LayerKind::Package, 'upgrading.neon', 'acme/lib'));
	$values = new Values(['upgrading.map' => $decision], ['upgrading.map' => $tombstones]);
	Assert::true($values->isKept('upgrading.map'));
	Assert::false($values->isSelected('upgrading.map'));

	$above = $decision->accept(['B' => 'other'])->withOrigin(new Layer(LayerKind::Configuration));
	Assert::same(['A' => 'keep', 'B' => 'other'], $decision->domain->merge($tombstones, $above)->toData());
});


test('a map without a grammar is read normalized by the domain of its values', function () {
	$decision = new Decision('spacing.calls', new Map(new Shapes(['compact' => ['foo()', ''], 'spaced' => ['foo ()', '']])), 'A map');
	$values = new Values(['spacing.calls' => $decision], ['spacing.calls' => $decision->accept(['a' => 'foo()', 'b' => 'spaced'])]);
	Assert::same(['a' => 'compact', 'b' => 'spaced'], $values->readMap('spacing.calls'));
});


test('a parameter has a default, a requirement has none', function () {
	Assert::exception(fn() => new Decision('a.b', new Names, '', parameter: true), InvalidArgumentException::class, 'Parameter `a.b` must have a default.');
	Assert::exception(fn() => new Decision('a.b', Domain::state(), '', default: 'forbidden'), InvalidArgumentException::class, 'Requirement `a.b` has no default, one nobody names requiring nothing.');
	Assert::true(new Decision('a.b', Domain::state(), '')->getDefault()->isKept());
	Assert::same(['Traversable'], new Decision('a.b', new Names, '', parameter: true, default: ['Traversable'])->getDefault()->getNames());
});
