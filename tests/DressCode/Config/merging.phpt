<?php declare(strict_types=1);

/**
 * The law of merging the layers of one decision, the transitions of `keep` above all, as a table: the layers
 * from the bottom up, each with its origin, and what the merged value answers.
 */

use DressCode\Config\{Layer, LayerKind};
use DressCode\{Domain, Value};
use DressCode\Domains\{Count, Map, Names, Shapes, Words};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** @param  array<string, mixed>  $layers  origin => the value the layer writes, from the bottom up */
function mergeLayers(Domain $domain, array $layers): Value
{
	$merged = null;
	foreach ($layers as $origin => $raw) {
		$value = $domain->accept($raw, 'x.y', keep: true)->withOrigin(new Layer(LayerKind::Preset, $origin));
		$merged = $merged === null ? $value : $domain->merge($merged, $value);
	}

	return $merged ?? throw new LogicException('No layers.');
}


$placement = Domain::placement();


test('a word, a shape and a count replace the one below', function () use ($placement) {
	$value = mergeLayers($placement, ['perCs' => 'sameLine', 'project' => 'nextLine']);
	Assert::same('nextLine', $value->getWord());
	Assert::same('project', $value->origin?->name);

	Assert::same('spaced', mergeLayers(new Shapes(['compact' => ['foo()', ''], 'spaced' => ['foo ()', '']]), ['perCs' => 'foo()', 'project' => 'foo ()'])->getShape());
	Assert::same([1, null], mergeLayers(new Count, ['perCs' => 2, 'project' => '1+'])->getCount());
});


test('keep wins over the value below, and a value above keep replaces it', function () use ($placement) {
	$kept = mergeLayers($placement, ['perCs' => 'nextLine', 'project' => 'keep']);
	Assert::true($kept->isKept());
	Assert::same('project', $kept->origin?->name);

	$revived = mergeLayers($placement, ['perCs' => 'keep', 'project' => 'sameLine']);
	Assert::same('sameLine', $revived->getWord());
	Assert::same('project', $revived->origin?->name);
});


test('a list replaces the list below, a tolerance too', function () {
	Assert::same(['Iterator'], mergeLayers(new Names, ['perCs' => ['Traversable', 'Iterator'], 'project' => ['Iterator']])->getNames());
	Assert::same([], mergeLayers(new Names, ['perCs' => ['Traversable'], 'project' => []])->getNames());

	$tolerance = new Words(['perLine' => '', 'compact' => ''], tolerance: true);
	Assert::same(['compact'], mergeLayers($tolerance, ['perCs' => ['perLine', 'compact'], 'project' => 'compact'])->getWords());
	Assert::same(['compact', 'perLine'], mergeLayers($tolerance, ['perCs' => 'perLine', 'project' => ['compact', 'perLine']])->getWords());
});


test('an entry withdrawn above stays as keep, with the layer that withdrew it', function () {
	$except = new Map(new Words(['imported' => '', 'backslashed' => '']));
	$merged = mergeLayers($except, ['perCs' => ['assert' => 'backslashed', 'strlen' => 'imported'], 'project' => ['strlen' => 'keep']]);
	Assert::same('backslashed', $merged->getEntries()['assert']->getWord());
	Assert::true($merged->getEntries()['strlen']->isKept());
	Assert::same('project', $merged->getEntries()['strlen']->origin?->name);
});


test('a map merges key by key, and {} brings no entries of its own', function () {
	$map = new Map(new Count);
	$value = mergeLayers($map, ['perCs' => ['return' => '1+'], 'project' => ['unset' => 1]]);
	Assert::same([1, null], $value->getEntries()['return']->getCount());
	Assert::same([1, 1], $value->getEntries()['unset']->getCount());
	Assert::same(['return'], array_keys(mergeLayers($map, ['perCs' => ['return' => '1+'], 'project' => []])->getEntries()));
});
