<?php declare(strict_types=1);

use DressCode\Config\{ProjectPackages, UnmetRequirement};
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the packages a project has, each with the version it stands for and the source it came from', function () {
	$root = createTempDir('project-packages');
	FileSystem::write("$root/composer.json", json_encode([
		'name' => 'app/project',
		'require' => ['acme/direct' => '^3.1 || ^4.0', 'acme/anything' => '*'],
		'require-dev' => ['acme/tool' => '~2.5.0'],
	], JSON_THROW_ON_ERROR));
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
	Assert::same('app/project', $project->rootName);

	Assert::true($project->has('app/project'));
	Assert::true($project->has('acme/unaliased'));
	Assert::false($project->has('acme/missing'));

	// the identity says what the files of the packages are: the version each stands for and where it came from
	$identity = $project->getIdentity();
	Assert::same(['3.2.1', 'abc'], $identity['acme/transitive']); // the source before the dist
	Assert::same([null, null], $identity['acme/unaliased']);
	Assert::same(array_keys($identity), ['acme/anything', 'acme/branch', 'acme/direct', 'acme/line', 'acme/tool', 'acme/transitive', 'acme/unaliased']);
});


test('a project without packages has none', function () {
	$project = new ProjectPackages;
	Assert::false($project->has('acme/lib'));
});


test('a requirement is met where every version the code is written for satisfies it', function () {
	$root = createTempDir('project-packages-requirement');
	FileSystem::write("$root/composer.json", json_encode([
		'require' => ['acme/old' => '^3.4', 'acme/both' => '^4.2 || ^5.0', 'acme/any' => '*'],
	], JSON_THROW_ON_ERROR));
	FileSystem::write("$root/vendor/composer/installed.json", json_encode(['packages' => [
		['name' => 'acme/old', 'version' => 'v3.6.0', 'version_normalized' => '3.6.0.0'],
		['name' => 'acme/both', 'version' => 'v5.1.0', 'version_normalized' => '5.1.0.0'],
		['name' => 'acme/any', 'version' => 'v5.1.0', 'version_normalized' => '5.1.0.0'],
	]], JSON_THROW_ON_ERROR));

	$project = ProjectPackages::read($root);
	Assert::same('^4.2 || ^5.0', $project->findConstraint('acme/both'));
	Assert::same('5.1', $project->findConstraint('acme/any')); // no lower bound, so the installed version
	Assert::null($project->findUnmetRequirement(['acme/old' => '>=3.3 <5.0', 'acme/any' => '>=3.3']));
	Assert::equal(new UnmetRequirement('acme/both', '>=3.3 <5.0', '^4.2 || ^5.0'), $project->findUnmetRequirement(['acme/both' => '>=3.3 <5.0']));
	Assert::equal(new UnmetRequirement('acme/any', '<5.0', '5.1'), $project->findUnmetRequirement(['acme/any' => '<5.0']));
	Assert::equal(new UnmetRequirement('acme/missing', '*', null), $project->findUnmetRequirement(['acme/missing' => '*']));
});
