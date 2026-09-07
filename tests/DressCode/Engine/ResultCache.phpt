<?php declare(strict_types=1);

use DressCode\Engine\ResultCache;
use Tester\Assert;
use Tester\Helpers;

require __DIR__ . '/../../bootstrap.php';


$dir = __DIR__ . '/../../temp/result-cache';
@mkdir($dir, recursive: true); // @ - may exist
Helpers::purge($dir);
$file = "$dir/sub/cache.json";


test('a missing file is an empty cache; entries survive a save and load under the same configuration', function () use ($file) {
	$cache = ResultCache::load($file, 'config-a');
	Assert::same(0, $cache->count());
	$key = ResultCache::hashContent('src/a.php', "<?php\n");
	Assert::null($cache->findClean($key));
	$cache->markClean($key, ['f1']);
	Assert::same(['f1'], $cache->findClean($key));
	$cache->save();

	$cache = ResultCache::load($file, 'config-a');
	Assert::same(1, $cache->count());
	Assert::same(['f1'], $cache->findClean($key));
	Assert::null($cache->findClean(ResultCache::hashContent('src/a.php', "<?php\n\n")));
	Assert::null($cache->findClean(ResultCache::hashContent('src/b.php', "<?php\n"))); // the same text of another file is not known
});


test('another configuration starts empty and replaces the file', function () use ($file) {
	$cache = ResultCache::load($file, 'config-b');
	Assert::same(0, $cache->count());
	$cache->markClean('x');
	$cache->save();
	Assert::same(0, ResultCache::load($file, 'config-a')->count());
	Assert::same(1, ResultCache::load($file, 'config-b')->count());
});


test('expired entries are dropped on save, touched ones are refreshed', function () use ($file) {
	file_put_contents($file, json_encode(['config' => 'config-c', 'entries' => [
		'old' => [time() - 40 * 24 * 3600, []],
		'recent' => [time() - 3600, []],
		'used' => [1, ['f']],
	]]));
	$cache = ResultCache::load($file, 'config-c');
	Assert::same(3, $cache->count());
	Assert::same(['f'], $cache->findClean('used'));
	$cache->save();
	$data = json_decode((string) file_get_contents($file), associative: true);
	Assert::same(['recent', 'used'], array_keys($data['entries']));
	Assert::same(['f'], $data['entries']['used'][1]);
	Assert::true($data['entries']['used'][0] > time() - 60);
});


test('a broken file is an empty cache, and an entry of another shape is none', function () use ($file) {
	file_put_contents($file, '{');
	Assert::same(0, ResultCache::load($file, 'config-c')->count());
	file_put_contents($file, json_encode(['config' => 'config-c', 'entries' => ['a' => 1, 'b' => [1, [2]], 'c' => [1, ['f']]]]));
	Assert::same(1, ResultCache::load($file, 'config-c')->count());
});
