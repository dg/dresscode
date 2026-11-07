<?php declare(strict_types=1);

use DressCode\Console\TasksPrinter;
use DressCode\{Risk, Severity, Violation};
use DressCode\Upgrade\Task;
use Nette\CommandLine\{ColorDepth, Console};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('past the limit the tasks are counted by topic, the command listing them named', function () {
	$task = new Task('src/A.php', new Violation('x.y', 'Write it.', 1, 1, Severity::Error, 'x'));
	$console = new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::None, terminal: false);
	Assert::same(
		"Manual     3 tasks the fixes left, `php` 2, `acme/lib` 1: `dresscode upgrade tasks` lists them\n",
		new TasksPrinter(['php' => [$task, $task], 'empty' => [], 'acme/lib' => [$task]], listed: 2)->print($console),
	);
	Assert::contains("  src/A.php:1  Write it.  [x.y]\n", new TasksPrinter(['php' => [$task, $task]], listed: 2)->print($console));
});


test('a task shows its line with the one above and the one below, as code, without the indentation they share', function () {
	$root = createTempDir('tasks');
	mkdir("$root/src");
	file_put_contents(
		"$root/src/Order.php",
		"<?php\n\nclass Order\n{\n\tpublic function total(): int\n\t{\n\t\t// the `old` call\n\t\treturn old_total(\$this);\n\t}\n}\n",
	);
	$task = new Task('src/Order.php', new Violation('upgrading.api.forbiddenFunctions', 'Write it anew.', 8, 3, Severity::Error, 'x'));
	$console = new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::None, terminal: false);
	Assert::same(
		"Manual     1 task the fixes left\n"
		. "  src/Order.php:8  Write it anew.  [upgrading.api.forbiddenFunctions]\n"
		. "      ``\t// the `old` call``\n" // a backtick in the code makes the run longer
		. "      `\treturn old_total(\$this);`\n"
		. "      `}`\n",
		new TasksPrinter(['php' => [$task]], root: $root)->print($console),
	);
});


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
