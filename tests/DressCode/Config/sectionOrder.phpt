<?php declare(strict_types=1);

/**
 * A preset opens with `use` and `severity` and ends with `suppressionComments`, and a preset and the annotated
 * configuration write the sections of the core in the order of the core.
 */

use DressCode\Config\{Catalogue, PluginRegistry};
use Nette\Neon\Neon;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('a preset opens with use and severity, its sections follow in the order of the core, and suppressionComments ends it', function () {
	foreach (new PluginRegistry()->presets as $file) {
		$keys = array_keys(Neon::decodeFile($file));
		$expected = [
			...array_intersect(['use', 'severity'], $keys),
			...array_intersect(Catalogue::CoreSections, $keys),
			...array_intersect(['suppressionComments'], $keys),
		];
		Assert::same($expected, $keys, $file);
	}
});


test('the sections of the annotated configuration follow the order of the core', function () {
	$keys = array_keys(Neon::decodeFile(__DIR__ . '/../../../docs/dresscode.annotated.neon'));
	$sections = array_values(array_intersect($keys, Catalogue::CoreSections));
	Assert::same(array_values(array_intersect(Catalogue::CoreSections, $sections)), $sections);
});
