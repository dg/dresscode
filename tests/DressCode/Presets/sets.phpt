<?php declare(strict_types=1);

/**
 * What a set may carry, so that using one never fights the standard the project chose.
 */

use DressCode\Config;
use DressCode\Config\{ConfigResolver, PluginRegistry};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


const Standards = ['dresscode/perCs', 'dresscode/psr12'];

$registry = new PluginRegistry;
$resolver = new ConfigResolver($registry);

/** @var array<string, list<string>>  preset => the decisions it writes, those of what it uses included, `keep` left out */
$written = [];
foreach ($registry->presets as $name => $file) {
	foreach ($resolver->resolve(new Config(use: [$name]), '8.6')->decisions as $path => $decision) {
		if ($decision->layers !== [] && !$decision->value->isKept()) {
			$written[$name][] = $path;
		}
	}
}


test('a set decides nothing about the layout', function () use ($written) {
	// the areas a standard owns: a decision of them in a set would fight the standard the project chose
	$layout = '~^(indentation|spacing|multiline|blankLines|naming|braces)\.~';
	foreach (array_diff_key($written, array_flip(Standards)) as $set => $paths) {
		Assert::same([], array_values(array_diff(preg_grep($layout, $paths), ['braces.bareStatementGroup'])), $set);
	}
});


test('every construct and function newer PHP brought or retired is in a set', function () use ($written, $resolver) {
	// a decision added there reaches every project using the set, so none may be left out by mistake
	$sets = array_merge(...array_values(array_diff_key($written, array_flip(Standards))));
	$upgrading = array_keys(array_filter(
		$resolver->getCatalogue()->getDecisions(),
		fn(DressCode\Decision $decision) => $decision->isRequirement() && preg_match('~^upgrading\.(syntax|functions|php)\.~', $decision->path),
	));
	Assert::same([], array_values(array_diff($upgrading, $sets)));
});
