<?php declare(strict_types=1);

use DressCode\Config\ProjectPackages;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the version of a package the code must work with', function () {
	$root = str_replace('\\', '/', (string) realpath(__DIR__ . '/../..')) . '/temp/project-packages';
	FileSystem::delete($root);
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


test('a project without packages has none', function () {
	$project = new ProjectPackages;
	Assert::null($project->rootPath);
	Assert::false($project->has('acme/lib'));
	Assert::null($project->findVersion('acme/lib'));
});


test('the lowest version a constraint allows', function () {
	Assert::same('3.1', ProjectPackages::findLowestVersion('^3.1 || ^4.0'));
	Assert::same('8.2', ProjectPackages::findLowestVersion('8.2.*'));
	Assert::same('8.0.2', ProjectPackages::findLowestVersion('>=8.0.2, <8.0.15'));
	Assert::same('1.4.9999999.9999999', ProjectPackages::findLowestVersion('1.4.x-dev'));
	Assert::null(ProjectPackages::findLowestVersion('<8.4'));
	Assert::null(ProjectPackages::findLowestVersion('*'));
	Assert::null(ProjectPackages::findLowestVersion('dev-master'));
	Assert::null(ProjectPackages::findLowestVersion('not a constraint'));
});
