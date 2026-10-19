<?php declare(strict_types=1);

/**
 * The annotated configuration of `docs/` is one the loader takes, sets every decision of the catalogue, and the comment
 * of each key of words names the other words the key takes.
 */

use DressCode\Config\{ConfigResolver, LayerKind, Loader, PluginRegistry};
use DressCode\DecisionKind;
use DressCode\Domains\Words;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

$file = __DIR__ . '/../../../docs/dresscode.annotated.neon';
$config = Loader::loadFile($file);
$resolved = new ConfigResolver(new PluginRegistry)->resolve($config, '8.2');


test(
	'every decision of the catalogue is set by the file, the facts among the keys of the environment',
	function () use ($config, $resolved) {
		$unset = [];
		foreach ($resolved->decisions as $path => $decision) {
			$origin = $decision->findTopLayer()?->origin;
			if ($decision->decision->kind !== DecisionKind::Fact && $origin?->kind !== LayerKind::Configuration) {
				$unset[] = $path;
			}
		}

		Assert::same([], $unset);
		Assert::notSame([], $config->namespaces['functions']);
		Assert::notSame([], $config->namespaces['constants']);
	},
);


test('the comment of a key of words names every other word it takes', function () use ($file, $resolved) {
	$comments = $stack = [];
	foreach (file($file) ?: [] as $line) {
		if (preg_match('~^(\t*)([\w.]+):\s*(.*?)\s*(?:#\s?(.*))?$~', rtrim($line), $m)) {
			$stack = [...array_slice($stack, 0, strlen($m[1])), $m[2]];
			$comments[implode('.', $stack)] = $m[4] ?? '';
		}
	}

	$unnamed = [];
	foreach ($resolved->decisions as $path => $decision) {
		$domain = $decision->decision->domain;
		if (!$domain instanceof Words || !isset($comments[$path])) {
			continue; // a key the file sets through its group or a kind of places has its comment there
		}

		$also = strstr($comments[$path], 'Also:') ?: '';
		$written = array_map(strval(...), (array) $decision->findTopLayer()?->toWrittenData());
		$words = [...array_map(strval(...), array_keys($domain->toArray()['words'])), ...$decision->decision->takesKeep() ? ['keep'] : []];
		foreach (array_diff($words, $written) as $word) {
			if (!preg_match('~(?<![\w`])`?' . preg_quote($word, '~') . '`?(?!\w)~', $also)) {
				$unnamed[] = "$path: $word";
			}
		}
	}

	Assert::same([], $unnamed);
});
