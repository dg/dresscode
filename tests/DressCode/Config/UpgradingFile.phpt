<?php declare(strict_types=1);

use DressCode\Config\{Layer, LayerKind, UpgradingFile};
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


function readUpgradingFile(string $content): UpgradingFile
{
	$file = createTempDir('upgrading-file') . '/upgrading.neon';
	FileSystem::write($file, $content);
	return UpgradingFile::fromFile($file, '`upgrading.neon`');
}


test('what a section reached rewrites is not reported again, however its key is written', function () {
	$upgrading = readUpgradingFile(<<<'XX'
		package: acme/lib

		since 3.0:
			replacedMembers:
				'Acme\Lib\Order::pay()': settle
			replacedCalls:
				'acme_close($handle)': removed

		since 4.0:
			forbiddenMembers:
				Acme\Lib\Order::pay: gone
				Acme\Lib\Order::refund: gone
			forbiddenFunctions:
				acme_close: gone
				\Acme_Open: gone
		XX);

	$data = $upgrading->selectFor('^3.2 || ^4.0', new Layer(LayerKind::Package, 'upgrading.neon', 'acme/lib'));
	Assert::same(
		[
			'replacedMembers' => ['Acme\Lib\Order::pay()' => 'settle'],
			'replacedCalls' => ['acme_close($handle)' => 'removed'],
			'forbiddenMembers' => ['Acme\Lib\Order::refund' => 'gone'],
			'forbiddenFunctions' => ['\Acme_Open' => 'gone'],
		],
		$data->maps,
	);
	Assert::same(
		['forbiddenMembers' => ['Acme\Lib\Order::refund' => '4.0'], 'forbiddenFunctions' => ['\Acme_Open' => '4.0']],
		$data->retirements,
	);
});


test('the sentence names the package and the version, PHP as PHP', function () {
	$upgrading = readUpgradingFile(<<<'XX'
		package: php

		since 8.5:
			forbiddenFunctions:
				- socket_set_timeout
			replacedCalls:
				'curl_close($handle)': {write: removed, from: '8.0'}
		XX);

	$data = $upgrading->selectFor('>=8.0', new Layer(LayerKind::Package, 'php.neon', 'php'));
	Assert::same(
		[
			'replacedCalls' => ['curl_close($handle)' => 'removed'],
			'forbiddenFunctions' => ['socket_set_timeout' => 'since PHP 8.5'],
		],
		$data->buildDecisions(fn(string $map) => $map),
	);
});
