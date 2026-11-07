<?php declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';
require __DIR__ . '/Application.helpers.php';


$root = createConsoleProject();


test('upgrade refuses an argument its command does not take', function () use ($root) {
	foreach ([
		[['upgrade'], 'Missing command.'],
		[['upgrade', 'today', 'php'], 'Unexpected argument `php`.'],
	] as [$args, $message]) {
		[$exit, , $err] = runApp($root, $args);
		Assert::same(3, $exit);
		Assert::match("%A%$message%A%", $err);
	}

	// outside the repository, whose composer.json it would find
	$empty = sys_get_temp_dir() . '/dresscode-estimate-empty-' . getmypid();
	FileSystem::createDir($empty);
	[$exit, , $err] = runApp($empty, ['upgrade', 'today']);
	FileSystem::delete($empty);
	Assert::same(3, $exit);
	Assert::contains('There is nothing to upgrade', $err);
});
