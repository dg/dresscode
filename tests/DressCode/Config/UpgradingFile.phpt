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


test('a section above the lowest version says nothing, though the constraint allows a version it holds for', function () {
	$upgrading = readUpgradingFile(<<<'XX'
		package: acme/lib

		since 3.0:
			replacedMembers:
				'Acme\Lib\Order::pay()': settle

		since 3.5:
			replacedMembers:
				Acme\Lib\Order::cancel: revoke
				Acme\Lib\Order::ship: {write: send, from: '3.0'}

		since 4.0:
			forbiddenMembers:
				Acme\Lib\Order::refund: gone
		XX);

	$data = $upgrading->selectFor('^3.2 || ^4.0', new Layer(LayerKind::Package, 'upgrading.neon', 'acme/lib'));
	Assert::same(
		['replacedMembers' => ['Acme\Lib\Order::pay()' => 'settle', 'Acme\Lib\Order::ship' => 'send']],
		$data->maps,
	);
	Assert::same([], $data->retirements);
	Assert::same(['3.5', '4.0'], $data->unreached);
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

	$data = $upgrading->selectFor('>=8.5', new Layer(LayerKind::Package, 'php.neon', 'php'));
	Assert::same(
		[
			'forbiddenFunctions' => ['socket_set_timeout' => 'since PHP 8.5'],
			'replacedCalls' => ['curl_close($handle)' => 'removed'],
		],
		$data->buildDecisions(fn(string $map) => $map),
	);
});
