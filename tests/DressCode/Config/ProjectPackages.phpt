<?php declare(strict_types=1);

use DressCode\Config\ProjectPackages;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the version of a package the code must work with', function () {
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
			'install-path' => '../acme/transitive',
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
	Assert::same($root, $project->rootPath);
	Assert::same("$root/vendor/acme/transitive", $project->installed['acme/transitive']['path']);

	// required by the project: the lowest version its constraint allows, whatever is installed
	Assert::same('3.1', $project->findVersion('acme/direct'));
	Assert::same('2.5', $project->findVersion('acme/tool'));
	// a constraint without a lower bound says nothing, so the installed version answers
	Assert::same('1.7.3', $project->findVersion('acme/anything'));
	// only coming with another package: the installed version
	Assert::same('3.2.1', $project->findVersion('acme/transitive'));
	// a development branch stands for the newest of the line its alias names
	Assert::same('3.3.9999999.9999999', $project->findVersion('acme/branch'));
	Assert::same('1.4.9999999.9999999', $project->findVersion('acme/line'));

	// any version does for the project itself and for a branch without an alias
	Assert::null($project->findVersion('app/project'));
	Assert::true($project->has('app/project'));
	Assert::null($project->findVersion('acme/unaliased'));
	Assert::true($project->has('acme/unaliased'));

	Assert::null($project->findVersion('acme/missing'));
	Assert::false($project->has('acme/missing'));

	// the version the configuration says the code is written for comes before the constraint and the installed one,
	// and says nothing of a package the project does not have
	$targeted = $project->withTargets(['acme/direct' => '4.1', 'acme/transitive' => '4.0', 'acme/unaliased' => '2.0', 'acme/missing' => '1.0']);
	Assert::same('4.1', $targeted->findVersion('acme/direct'));
	Assert::same('4.0', $targeted->findVersion('acme/transitive'));
	Assert::same('2.0', $targeted->findVersion('acme/unaliased'));
	Assert::same('2.5', $targeted->findVersion('acme/tool'));
	Assert::null($targeted->findVersion('acme/missing'));
	Assert::false($targeted->has('acme/missing'));
	Assert::same('3.1', $project->findVersion('acme/direct'));
	Assert::same($project->getIdentity(), $targeted->getIdentity());

	// the identity says what the files of the packages are: the version each stands for and where it came from
	$identity = $project->getIdentity();
	Assert::same(['3.2.1', 'abc'], $identity['acme/transitive']); // the source before the dist
	Assert::same([null, null], $identity['acme/unaliased']);
	Assert::same(array_keys($identity), ['acme/anything', 'acme/branch', 'acme/direct', 'acme/line', 'acme/tool', 'acme/transitive', 'acme/unaliased']);
});


test('a package an installed one replaces is had in the version of the one replacing it', function () {
	$root = createTempDir('project-packages-replace');
	FileSystem::write("$root/composer.json", json_encode([
		'require' => ['acme/monorepo' => '^6.4', 'acme/part-required' => '^7.1'],
	], JSON_THROW_ON_ERROR));
	FileSystem::write("$root/vendor/composer/installed.json", json_encode(['packages' => [
		[
			'name' => 'acme/monorepo',
			'version' => 'v7.4.2',
			'version_normalized' => '7.4.2.0',
			'replace' => ['acme/part' => 'self.version', 'acme/part-required' => 'self.version', 'acme/polyfill' => '*'],
		],
	]], JSON_THROW_ON_ERROR));

	$project = ProjectPackages::read($root);
	Assert::true($project->has('acme/part'));
	Assert::same('6.4', $project->findVersion('acme/part')); // the lowest version the monorepo is required in
	Assert::same('7.1', $project->findVersion('acme/part-required')); // required itself
	Assert::same('7.0', $project->withTargets(['acme/part' => '7.0'])->findVersion('acme/part'));

	// a package replaced by any version says nothing of the one it stands for
	Assert::false($project->has('acme/polyfill'));
	Assert::false(isset($project->installed['acme/part']));
});


test('a project without packages has none', function () {
	$project = new ProjectPackages;
	Assert::null($project->rootPath);
	Assert::false($project->has('acme/lib'));
	Assert::null($project->findVersion('acme/lib'));
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
	Assert::same(['acme/both', '>=3.3 <5.0', '^4.2 || ^5.0'], $project->findUnmetRequirement(['acme/both' => '>=3.3 <5.0']));
	Assert::same(['acme/any', '<5.0', '5.1'], $project->findUnmetRequirement(['acme/any' => '<5.0']));
	Assert::same(['acme/missing', '*', null], $project->findUnmetRequirement(['acme/missing' => '*']));
	Assert::null($project->withTargets(['acme/both' => '4.3'])->findUnmetRequirement(['acme/both' => '>=3.3 <5.0']));
});
