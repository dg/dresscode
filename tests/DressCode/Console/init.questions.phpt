<?php declare(strict_types=1);

/**
 * The output of init beyond its text: the proposal as data for a tool, and the code drawn once where there are colors.
 */

use DressCode\Console\InitPrinter;
use DressCode\Measuring\{InProcessProbeRunner, ProjectFacts, Proposal, Scope};
use Nette\CommandLine\{ColorDepth, Console};
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


test('with colors the printer draws all its code itself, so that no backtick is left for a second drawing', function () {
	$root = createProject('drawn', stragglers());
	$proposal = Proposal::measure(
		ProjectFacts::detect($root),
		Scope::fromAutoload($root, Scope::Extensions),
		new InProcessProbeRunner($root),
		['perCs'],
	);
	$proposal->verify();
	$printer = new InitPrinter(new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::Ansi256));
	$out = $printer->printScope($proposal->project, $proposal->scope)
		. $printer->printMeasurements($proposal)
		. $printer->printVerification($proposal)
		. $printer->printNext($proposal);
	Assert::contains("\e[", $out);
	Assert::notContains('`', Nette\CommandLine\Ansi::strip($out));
});
