<?php declare(strict_types=1);

use DressCode\Engine\ProcessRunner;
use DressCode\Upgrade\Git;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('a project below the top of its repository commits its files and those of Composer above it where they are', function () {
	$repository = createTempDir('git-subdirectory');
	mkdir("$repository/pkg/src", recursive: true);
	file_put_contents("$repository/composer.json", 'before');
	file_put_contents("$repository/.gitattributes", '/pkg/src/A.php text eol=lf');
	file_put_contents("$repository/pkg/src/A.php", 'before');
	file_put_contents("$repository/pkg/src/B.php", 'before');
	$git = fn(string ...$args) => str_replace("\r", '', ProcessRunner::run(['git', ...array_values($args)], $repository, 60)[1]);
	$git('init', '--quiet');
	$git('add', '--all');
	$git('-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '--quiet', '-m', 'start');
	file_put_contents("$repository/pkg/src/A.php", "after\r\n");
	file_put_contents("$repository/pkg/src/B.php", 'mine');
	file_put_contents("$repository/composer.json", 'after');

	putenv('GIT_AUTHOR_NAME=Test');
	putenv('GIT_COMMITTER_NAME=Test');
	putenv('GIT_AUTHOR_EMAIL=test@example.com');
	putenv('GIT_COMMITTER_EMAIL=test@example.com');
	$project = new Git("$repository/pkg");
	Assert::same(['src/A.php', '../composer.json'], $project->listUnignored(['src/A.php', '../composer.json', 'src/C.php']));
	$project->commit(['src/A.php' => "after\r\n", '../composer.json' => 'after'], 'subject', 'body');

	Assert::same(".gitattributes\ncomposer.json\npkg/src/A.php\npkg/src/B.php\n", $git('ls-tree', '-r', '--name-only', 'HEAD'));
	// the text is cleaned by the attributes of its path in the repository
	Assert::same(["after\n", 'after', 'before'], [
		$git('show', 'HEAD:pkg/src/A.php'),
		$git('show', 'HEAD:composer.json'),
		$git('show', 'HEAD:pkg/src/B.php'),
	]);
	// the index takes the files committed as they are, and the change of the other one stays the user's own
	Assert::same(" M pkg/src/B.php\n", $git('status', '--porcelain'));
});
