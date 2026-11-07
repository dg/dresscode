<?php declare(strict_types=1);

use DressCode\Console\CommandContext;
use DressCode\Engine\WorkerPool;
use Nette\CommandLine\{Console, Prompt};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


function createRerunContext(mixed $stdout): CommandContext
{
	$console = new Console($stdout);
	return new CommandContext(
		$stdout,
		STDERR,
		STDIN,
		$console,
		$console,
		__DIR__,
		WorkerPool::locateProgram(),
		null,
		false,
		new Prompt($console, STDIN),
	);
}


test('DressCode is started again as the program of this package, and never from a process started so', function () {
	Assert::same(realpath(__DIR__ . '/../../../bin/dresscode'), realpath(WorkerPool::locateProgram()));
	// embedded, the command line of the process is not its own
	Assert::null(createRerunContext(fopen('php://memory', 'w+'))->rerun(['--version']));

	// a command of its own names the script that carries its default configuration
	Assert::exception(
		fn() => new DressCode\Console\Application(defaultConfig: new DressCode\Config),
		InvalidArgumentException::class,
		'A command of its own giving a default configuration names its script, %a%',
	);

	putenv('DRESSCODE_RERUN=1');
	try {
		Assert::null(createRerunContext(STDOUT)->rerun(['--version']));
	} finally {
		putenv('DRESSCODE_RERUN');
	}
});
