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


test('a section of a version for each series holds where the series of the lowest version has it', function () {
	$upgrading = readUpgradingFile(<<<'XX'
		package: acme/lib

		since 3.1.1:
			replacedMembers:
				Acme\Lib\Order::ship: keep

		since 3.0.26 3.1.2:
			replacedMembers:
				Acme\Lib\Order::ship: send
			forbiddenMembers:
				Acme\Lib\Order::refund: gone
		XX);

	$layer = new Layer(LayerKind::Package, 'upgrading.neon', 'acme/lib');
	$select = fn(string $constraint) => $upgrading->selectFor($constraint, $layer)->maps['replacedMembers']['Acme\Lib\Order::ship'] ?? null;
	Assert::null($select('^3.0'));
	Assert::same('send', $select('^3.0.26'));
	Assert::same('keep', $select('^3.1.1'));
	Assert::same('send', $select('^3.1.2'));
	Assert::same('send', $select('^3.2'));

	// the line of the lowest version had the section later than the other one, and from its own version
	Assert::same(
		[['3.1.2', [
			'replacedMembers' => ['Acme\Lib\Order::ship' => 'send'],
			'forbiddenMembers' => ['Acme\Lib\Order::refund' => 'gone, since acme/lib 3.1.2'],
		]]],
		array_map(
			fn(array $layer) => [$layer[0]->since, $layer[1]],
			$upgrading->selectFor('^3.1.5', $layer)->buildDecisions(fn(string $map) => $map),
		),
	);

	Assert::same(['3.1.1', '3.1.2'], $upgrading->selectFor('^3.1', $layer)->unreached);
	Assert::same(['3.0.26', '3.1.1'], $upgrading->selectFor('^3.0', $layer)->unreached);
	Assert::same('3.1.2', $upgrading->selectFor('^3.0', $layer)->newest);
});


test('a section of a version for each series turns its requirements on where it holds, `from` stays one version', function () {
	$upgrading = readUpgradingFile(<<<'XX'
		package: acme/lib

		since 3.0.26 3.1.2:
			acme.retired: required
			replacedMembers:
				Acme\Lib\Order::pay: {write: settle, from: '3.0'}
		XX);

	$layer = new Layer(LayerKind::Package, 'upgrading.neon', 'acme/lib');
	Assert::same([], $upgrading->selectFor('^3.1', $layer)->requirements);
	Assert::same(['acme.retired' => 'required'], $upgrading->selectFor('^3.1.2', $layer)->requirements);
	Assert::same(['replacedMembers' => ['Acme\Lib\Order::pay' => 'settle']], $upgrading->selectFor('^3.1', $layer)->maps);
	Assert::same([], $upgrading->selectFor('^2.9', $layer)->maps);
});


test('a section names its versions each of another series', function () {
	Assert::exception(
		fn() => readUpgradingFile("package: acme/lib\nsince 3.1.2 3.0.26:\n\treplacedClasses: []\n"),
		DressCode\ConfigurationException::class,
		'Upgrading file `upgrading.neon`, `since 3.1.2 3.0.26`: The versions `3.1.2 3.0.26` must be each of another series, the oldest first.',
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

	$data = $upgrading->selectFor('>=8.5', new Layer(LayerKind::Package, 'php.neon', 'php'));
	// every entry in the layer of its section, with the version its fix is right from
	Assert::same(
		[
			['php', '8.5', null, ['forbiddenFunctions' => ['socket_set_timeout' => 'since PHP 8.5']]],
			['php', '8.5', '8.0', ['replacedCalls' => ['curl_close($handle)' => 'removed']]],
		],
		array_map(
			fn(array $layer) => [$layer[0]->library, $layer[0]->since, $layer[0]->from, $layer[1]],
			$data->buildDecisions(fn(string $map) => $map),
		),
	);
});
