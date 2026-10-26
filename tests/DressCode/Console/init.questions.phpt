<?php declare(strict_types=1);

/**
 * init asks where the measuring left the choice: what a round of questions holds, what an answer does to the
 * next one, and what a run without a terminal takes.
 */

use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';
require __DIR__ . '/init.helpers.php';


test('for a tool init writes the proposal as data, nothing else', function () {
	$root = createProject('agent', stragglers());
	[$code, $out] = runInit($root, ['--use', 'perCs', '--format', 'json']);
	Assert::same(0, $code);
	$data = json_decode($out, associative: true, flags: JSON_THROW_ON_ERROR);
	Assert::same(DressCode\Console\InitPrinter::Version, $data['version']);
	Assert::same(['perCs'], $data['standard']['use']);
	Assert::same('dresscode.neon', $data['written']);
	Assert::same($data['configuration'], (string) file_get_contents("$root/dresscode.neon"));
	Assert::contains("\tafterOpeningTag: keep", $data['configuration']);
});
