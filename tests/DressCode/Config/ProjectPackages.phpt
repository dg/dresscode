<?php declare(strict_types=1);

use DressCode\Config\ProjectPackages;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the installed packages, each with the version it stands for and the source it came from', function () {
	$root = createTempDir('project-packages');
	FileSystem::write("$root/composer.json", json_encode(['name' => 'app/project'], JSON_THROW_ON_ERROR));
	FileSystem::write("$root/vendor/composer/installed.json", json_encode(['packages' => [
		['name' => 'acme/direct', 'version' => 'v4.2.0', 'version_normalized' => '4.2.0.0'],
		['name' => 'acme/anything', 'version' => 'v1.7.3', 'version_normalized' => '1.7.3.0'],
		['name' => 'acme/tool', 'version' => 'v2.5.4', 'version_normalized' => '2.5.4.0'],
		[
			'name' => 'acme/transitive',
			'version' => 'v3.2.1',
			'version_normalized' => '3.2.1.0',
			'source' => ['reference' => 'abc'],
			'dist' => ['reference' => 'def'],
		],
		[
			'name' => 'acme/branch',
			'version' => 'dev-master',
			'version_normalized' => 'dev-master',
			'extra' => ['branch-alias' => ['dev-master' => '3.3-dev']],
		],
		['name' => 'acme/line', 'version' => 'v1.4.x-dev', 'version_normalized' => '1.4.9999999.9999999-dev'],
		['name' => 'acme/unaliased', 'version' => 'dev-main', 'version_normalized' => 'dev-main'],
	]], JSON_THROW_ON_ERROR));

	$project = ProjectPackages::read("$root/src");

	// the identity says what the files of the packages are: the version each stands for and where it came from
	$identity = $project->getIdentity();
	Assert::same(['3.2.1', 'abc'], $identity['acme/transitive']); // the source before the dist
	Assert::same([null, null], $identity['acme/unaliased']);
	Assert::same(array_keys($identity), ['acme/anything', 'acme/branch', 'acme/direct', 'acme/line', 'acme/tool', 'acme/transitive', 'acme/unaliased']);
});
