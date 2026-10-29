<?php declare(strict_types=1);

use DressCode\Console\TasksPrinter;
use DressCode\{Risk, Severity, Violation};
use DressCode\Upgrade\Task;
use Nette\CommandLine\{ColorDepth, Console};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('a place whose fix waits says what for, and the data keep the risk with its reason', function () {
	$place = fn(string $decision, ?Risk $risk, ?string $because = null) => new Task(
		'src/Order.php',
		new Violation(
			$decision,
			'Write it anew.',
			3,
			1,
			Severity::Error,
			'x',
			fixable: $risk !== null,
			risk: $risk,
			refused: $risk !== null,
			because: $because,
		),
	);
	$printer = new TasksPrinter(['php' => [
		$place('upgrading.php.deprecatedApi', null),
		$place('upgrading.syntax.match', Risk::BehaviorChanges, 'a case compares loosely'),
		$place('upgrading.api.replacedMembers', Risk::TypeUnknown),
	]]);
	$console = new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::None, terminal: false);

	Assert::match(
		<<<'XX'
			Manual     3 tasks the fixes left
			  src/Order.php:3  Write it anew.  [upgrading.php.deprecatedApi]
			  src/Order.php:3  Write it anew.  [upgrading.syntax.match]
			    its fix is risky, as a case compares loosely; once you checked it, `fixRisky: [upgrading.syntax.match]` allows it
			  src/Order.php:3  Write it anew.  [upgrading.api.replacedMembers]
			    its fix needs the type of the expression, which the analysis does not know; where the types are off, `--types` turns them on

			XX,
		$printer->print($console),
	);
	Assert::same(
		[[false, null, null], [true, 'behaviorChanges', 'a case compares loosely'], [true, 'typeUnknown', null]],
		array_map(fn(array $task) => [$task['fixable'], $task['risk'], $task['because']], $printer->toArray()['tasks']),
	);
});
