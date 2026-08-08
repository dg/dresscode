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
	$key = ResultCache::hashContent("<?php\n");
	Assert::false($cache->isClean($key));
	$cache->markClean($key);
	Assert::true($cache->isClean($key));
	$cache->save();

	$cache = ResultCache::load($file, 'config-a');
	Assert::same(1, $cache->count());
	Assert::true($cache->isClean($key));
	Assert::false($cache->isClean(ResultCache::hashContent("<?php\n\n")));
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
	file_put_contents($file, json_encode(['config' => 'config-c', 'entries' => ['old' => time() - 40 * 24 * 3600, 'recent' => time() - 3600, 'used' => 1]]));
	$cache = ResultCache::load($file, 'config-c');
	Assert::same(3, $cache->count());
	Assert::true($cache->isClean('used'));
	$cache->save();
	$data = json_decode((string) file_get_contents($file), associative: true);
	Assert::same(['recent', 'used'], array_keys($data['entries']));
	Assert::true($data['entries']['used'] > time() - 60);
});


test('a broken file is an empty cache', function () use ($file) {
	file_put_contents($file, '{');
	Assert::same(0, ResultCache::load($file, 'config-c')->count());
});
