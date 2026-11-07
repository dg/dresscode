<?php declare(strict_types=1);

use DressCode\Config\ProjectPackages;
use DressCode\Engine\ProcessRunner;
use DressCode\Tristate;
use DressCode\Upgrade\{
	BlindSpots, Blocker, Blockers, ComposerStep, Constraints, Estimate, FixStep, ManualStep, Phase, Plan, PlanBuilder, PlanFile, Step, Target, Topic,
	UpdateStep,
};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** @param  ?list<Blockers>  $blockers */
function createPlanEstimate(bool $library = false, ?array $blockers = null): Estimate
{
	$topic = fn(string $name, string $set, Phase $phase) => new Topic($name, $set, $phase, 1, ['automatic' => 1]);
	return new Estimate(
		[Target::php('8.4'), new Target('acme/mail', '3.0')],
		[
			$topic('php', 'deprecations', Phase::Jump),
			$topic('acme/mail', 'deprecations', Phase::Jump),
			$topic('upgrading.syntax.octalPrefix', 'modernizations', Phase::Jump),
			$topic('php', 'deprecations', Phase::Bridge),
			$topic('hygiene.uselessVersionCheck', 'cleanup', Phase::Jump),
		],
		1,
		new BlindSpots,
		$blockers ?? [new Blockers(new Target('acme/mail', '3.0'), [new Blocker('acme/shop', 'v1.0.0', '^2.0')])],
		[],
		[],
		true,
		false,
		[],
		library: $library,
	);
}


test('the steps go from what a human makes way for, through the bridge and the raise, to the jump and what is left', function () {
	$project = new ProjectPackages(required: ['php' => '>=8.1', 'acme/mail' => '^2.4']);
	$plan = new PlanBuilder($project)->build(createPlanEstimate(), 'vendor/bin/tester tests -o junit:{junit}');

	Assert::equal([
		new ManualStep('make way for acme/mail 3.0: acme/shop', new Target('acme/mail', '3.0')),
		new FixStep('works on the versions allowed today', ['php']),
		// each constraint raised in the shape the project writes it
		new ComposerStep('raise php to >=8.4, acme/mail to ^3.0', ['php' => '>=8.4', 'acme/mail' => '^3.0']),
		new FixStep('needs the targets', ['php', 'acme/mail']),
		new FixStep('code for the versions given up', ['hygiene.uselessVersionCheck']),
		// the raise installs the lowest versions, which the application then updates, tested as every step
		new UpdateStep('update to the newest versions'),
		// what a newer PHP offers waits until the user takes it in
		new FixStep('what the targets offer', ['upgrading.syntax.octalPrefix'], skip: true),
	], $plan->steps);
	Assert::same(
		[
			1 => ManualStep::class,
			2 => FixStep::class,
			3 => ComposerStep::class,
			4 => FixStep::class,
			5 => FixStep::class,
			6 => UpdateStep::class,
		],
		array_map(fn(Step $step) => $step::class, $plan->findSteps()),
	);
	Assert::same('vendor/bin/tester tests -o junit:{junit}', $plan->testCommand);
});


test('a tilde keeps its precision, and an upper bound of its own the target passes is said', function () {
	$project = new ProjectPackages(required: ['php' => '~8.1.0', 'acme/mail' => '>=2.4 <3.0']);
	$plan = new PlanBuilder($project)->build(createPlanEstimate());
	Assert::equal(
		new ComposerStep('raise php to ~8.4.0, acme/mail to ^3.0 past its upper bound <3.0', ['php' => '~8.4.0', 'acme/mail' => '^3.0']),
		$plan->steps[2],
	);
});


test('an estimate is complete once it processed every file, had the upgrading data and Composer told what refuses', function () {
	Assert::true(createPlanEstimate()->isComplete());
	Assert::false(createPlanEstimate(blockers: [new Blockers(new Target('acme/mail', '3.0'), unknown: 'Composer failed')])->isComplete());
});


test('a target Composer could not tell what refuses is a step for a human all the same', function () {
	$project = new ProjectPackages(required: ['php' => '>=8.1', 'acme/mail' => '^2.4']);
	$plan = new PlanBuilder($project)->build(createPlanEstimate(
		blockers: [new Blockers(new Target('acme/mail', '3.0'), unknown: 'Composer failed')],
	));
	Assert::equal(
		new ManualStep('make way for acme/mail 3.0, which Composer could not tell', new Target('acme/mail', '3.0')),
		$plan->steps[0],
	);
});


test('a constraint is raised by its lower bound, its upper bound kept and a part the target is past dropped', function () {
	foreach ([
		['>=8.1', '>=8.4'],
		['^8.1', '^8.4'],
		['8.1', '^8.4'],
		['>=8.1 <8.5', '>=8.4 <8.5'],
		['>=8.1, <8.5', '>=8.4, <8.5'],
		['^8.1 <8.5', '^8.4 <8.5'],
		['^7.4 || ^8.0', '^8.4'],
		['^8.0 || ^9.0', '^8.4 || ^9.0'],
		['~8.1.0', '~8.4.0'],
		['>=8.1 <8.4', '^8.4'],
		['', '^8.4'],
		// an operator apart from its version, and a range of a hyphen
		['>= 8.1 < 8.5', '>=8.4 <8.5'],
		['8.1 - 8.5', '8.4 - 8.5'],
		['^7.4 || 8.1 - 8.5', '8.4 - 8.5'],
		['8.1 - 8.3', '^8.4'],
	] as [$constraint, $raised]) {
		Assert::same($raised, Constraints::raiseConstraint($constraint, '8.4'), $constraint);
		Assert::true(DressCode\Config\Versions::isConstraint($raised), $raised);
	}

	Assert::same('8.3', Constraints::findPassedUpperBound('8.1 - 8.3', '8.4'));
});


test('a platform of PHP below the target is raised with the constraint, which the file of the plan keeps', function () {
	$root = createTempDir('plan-platform');
	file_put_contents("$root/composer.json", '{"require": {"php": ">=8.1", "acme/mail": "^2.4"}, "config": {"platform": {"php": "8.1"}}}');
	$plan = new PlanBuilder(ProjectPackages::read($root))->build(createPlanEstimate());
	Assert::equal(
		new ComposerStep(
			'raise php to >=8.4, acme/mail to ^3.0, config.platform.php to 8.4',
			['php' => '>=8.4', 'acme/mail' => '^3.0'],
			platform: '8.4',
		),
		$plan->steps[2],
	);

	PlanFile::write($plan, $root);
	Assert::equal($plan->steps, PlanFile::read($root)->steps);

	// a platform below the target is raised where composer.json requires the target already, the step done once it is
	file_put_contents("$root/composer.json", '{"require": {"php": "^8.4", "acme/mail": "^3.0"}, "config": {"platform": {"php": "8.0"}}}');
	$plan = new PlanBuilder(ProjectPackages::read($root))->build(createPlanEstimate());
	$step = new ComposerStep('raise config.platform.php to 8.4', [], platform: '8.4');
	Assert::equal($step, $plan->steps[2]);
	Assert::same(['composer config platform.php 8.4', 'composer update --lock'], $step->formatCommands());
	Assert::false($step->isDoneIn(ProjectPackages::read($root)));
	file_put_contents("$root/composer.json", '{"require": {"php": "^8.4", "acme/mail": "^3.0"}, "config": {"platform": {"php": "8.4"}}}');
	Assert::true($step->isDoneIn(ProjectPackages::read($root)));

	PlanFile::write($plan, $root);
	Assert::equal($plan->steps, PlanFile::read($root)->steps);
});


test('a target the lowest version allowed today already reaches raises nothing', function () {
	$project = new ProjectPackages(required: ['php' => '^8.4', 'acme/mail' => '^3.1']);
	$plan = new PlanBuilder($project)->build(createPlanEstimate());
	Assert::false(array_any($plan->steps, fn(Step $step) => $step instanceof ComposerStep));
});


test('the first raise of a lower bound the project has not made is due, a widening or the platform alone never', function () {
	$today = new ProjectPackages(required: ['php' => '>=8.1', 'acme/mail' => '^2.4']);
	Assert::same(3, new PlanBuilder($today)->build(createPlanEstimate())->findDueRaise($today));
	Assert::null(new PlanBuilder($today)->build(createPlanEstimate(library: true))->findDueRaise($today));

	$plan = new Plan([Target::php('8.4')], [
		new ComposerStep('raise config.platform.php to 8.4', [], platform: '8.4'),
		new FixStep('needs the targets', ['php']),
		new ComposerStep('raise php to ^8.4', ['php' => '^8.4']),
	], '1.0');
	Assert::same(3, $plan->findDueRaise($today));
	Assert::null($plan->findDueRaise(new ProjectPackages(required: ['php' => '^8.4'])));
	$skipped = new Plan([Target::php('8.4')], [new ComposerStep('raise php to ^8.4', ['php' => '^8.4'], skip: true)], '1.0');
	Assert::null($skipped->findDueRaise($today));
});


test('the file of the plan gives back the plan it was written from', function () {
	$root = createTempDir('plan-file');
	$plan = new PlanBuilder(new ProjectPackages(required: ['php' => '^8.1']))
		->build(createPlanEstimate(), 'composer test -- {junit}', 'composer quick -- {junit}');
	PlanFile::write($plan, $root);
	Assert::equal($plan, PlanFile::read($root));

	// what the estimate could not see goes with the plan, so that a run of it says so
	$plan = new Plan(
		[Target::php('8.4')],
		[new FixStep('fix', ['php'])],
		'1.0',
		unestimated: ['src/Broken.php'],
		missingData: ['dresscode/upgrade-acme' => ['acme/mail']],
	);
	PlanFile::write($plan, $root);
	Assert::equal($plan, PlanFile::read($root));
});


test('the file of the plan is read as the team writes it, and one that is no plan is refused', function () {
	$root = createTempDir('plan-edited');
	file_put_contents("$root/" . PlanFile::Name, <<<'XX'
		version: 1
		targets: {php: '8.4', acme/mail: '3.0'}
		tests: {command: 'vendor/bin/phpunit --log-junit {junit}'}
		steps:
			- {kind: manual, title: make way, target: acme/mail}
			- {kind: fix, topics: [php]}
			- {kind: composer, title: raise, require: {php: ^8.4}, skip: true}
		XX);
	Assert::equal(
		new Plan(
			[Target::php('8.4'), new Target('acme/mail', '3.0')],
			[
				new ManualStep('make way', new Target('acme/mail', '3.0')),
				new FixStep('fix', ['php']),
				new ComposerStep('raise', ['php' => '^8.4'], skip: true),
			],
			'',
			testCommand: 'vendor/bin/phpunit --log-junit {junit}',
		),
		PlanFile::read($root),
	);

	foreach ([
		"version: 1\nsteps: []\n" => '`targets` and `steps` are expected',
		"targets: {php: '8.4'}\n" => '`version: 1` is expected, the plan of another version of DressCode is made anew',
		"version: 1\ntarget: {php: '8.4'}\n" => '`target` is no key of a plan',
		"version: 1\ntargets: {php: 8.4}\nsteps: []\n" => "`targets` must be a map of strings, a version quoted as `'8.4'`",
		"version: 1\ntargets: {php: '8.4'}\nsteps:\n\t- {title: who knows}\n" => '`steps.1` must give its `kind`, `fix`, `composer`, `update` or `manual`',
		"version: 1\ntargets: {php: '8.4'}\nsteps:\n\t- {kind: fix, topic: [php]}\n" => '`steps.1.topic` is no key of a step of the kind `fix`',
		"version: 1\ntargets: {php: '8.4'}\nsteps:\n\t- {kind: fix, topics: [php], skip: falsee}\n" => '`steps.1.skip` must be `true` or `false`',
		"version: 1\ntargets: {php: '8.4'}\nsteps:\n\t- {kind: fix}\n" => '`steps.1.topics` must name the topics the step fixes',
		"version: 1\ntargets: {php: '8.4'}\nsteps:\n\t- {kind: composer}\n" => '`steps.1.require` must give the constraints the step writes, or the step its `platform`',
		"version: 1\ntargets: {nonsense: '8.4'}\nsteps: []\n" => '`targets.nonsense` must be `php` or a package as `vendor/name`',
		"version: 1\ntargets: {php: '8.4'}\nsteps:\n\t- {kind: composer, require: {garbage: ^1.0}}\n"
			=> '`steps.1.require.garbage` must be `php` or a package as `vendor/name`',
		"version: 1\ntargets: {php: '8.4'}\nsteps:\n\t- {kind: composer, require: {php: no-constraint}}\n"
			=> '`steps.1.require.php` must be a constraint Composer reads, as `^8.4`',
		"version: 1\ntargets: {php: '8.4'}\nsteps:\n\t- {kind: manual, target: acme/mail}\n" => '`steps.1.target` must name a package of `targets`',
		"version: 1\ntargets: {php: '8.4'}\ntests: {quick: vendor/bin/phpunit}\nsteps:\n\t- {kind: fix, topics: [php]}\n"
			=> '`tests.quick` must be a command writing its JUnit report where `{junit}` stands',
		"version: 1\ntargets: {php: '8.4'}\nestimate: {files: []}\nsteps:\n\t- {kind: fix, topics: [php]}\n"
			=> '`estimate.files` is no key of `estimate`, which takes `unprocessed` and `missingData`',
		"version: 1\ntargets: {php: '8.4'}\nestimate: {missingData: {acme/x: acme/y}}\nsteps:\n\t- {kind: fix, topics: [php]}\n"
			=> '`estimate.missingData.acme/x` must be a list of strings',
		"version: 1\ntargets: {php: '8.4'}\nestimate: {missingData: [acme/y]}\nsteps:\n\t- {kind: fix, topics: [php]}\n"
			=> '`estimate.missingData.0` must be a list of strings',
	] as $text => $message) {
		file_put_contents("$root/" . PlanFile::Name, $text);
		Assert::exception(fn() => PlanFile::read($root), DressCode\ConfigurationException::class, "The plan `%a%`: $message.");
	}
});


test('a library keeps the versions it allows: the bridge and the constraints widened to the targets', function () {
	$project = new ProjectPackages(required: ['php' => '>=8.1', 'acme/mail' => '^2.4']);
	$plan = new PlanBuilder($project)->build(createPlanEstimate(library: true));

	Assert::equal([
		new ManualStep('make way for acme/mail 3.0: acme/shop', new Target('acme/mail', '3.0')),
		new FixStep('works on the versions allowed today', ['php']),
		new ComposerStep('widen acme/mail to ^2.4 || ^3.0', ['acme/mail' => '^2.4 || ^3.0'], update: false),
	], $plan->steps);
});


test(
	'a library says so by its type, none being one as Composer reads it, and has no lock of its own, one with a lock its git does not ignore maybe being one',
	function () {
		$root = createTempDir('plan-library');
		file_put_contents("$root/composer.json", '{"type": "library"}');
		Assert::same(Tristate::Yes, ProjectPackages::read($root)->isLibrary());
		file_put_contents("$root/composer.lock", '{}');
		Assert::same(Tristate::Maybe, ProjectPackages::read($root)->isLibrary());
		file_put_contents("$root/composer.json", '{}');
		Assert::same(Tristate::Maybe, ProjectPackages::read($root)->isLibrary());
		unlink("$root/composer.lock");
		$untyped = ProjectPackages::read($root);
		Assert::same([Tristate::Yes, 'library'], [$untyped->isLibrary(), $untyped->findType()]);
		file_put_contents("$root/composer.json", '{"type": "project"}');
		Assert::same(Tristate::No, ProjectPackages::read($root)->isLibrary());

		// the lock an install of a library leaves, which its git ignores, unless git ignores the whole project
		file_put_contents("$root/composer.json", '{}');
		file_put_contents("$root/composer.lock", '{}');
		ProcessRunner::run(['git', 'init', '-q'], $root, 60);
		Assert::same(Tristate::Maybe, ProjectPackages::read($root)->isLibrary());
		file_put_contents("$root/.gitignore", "composer.lock\n");
		Assert::same(Tristate::Yes, ProjectPackages::read($root)->isLibrary());
		file_put_contents("$root/.gitignore", "*\n");
		Assert::same(Tristate::Maybe, ProjectPackages::read($root)->isLibrary());
	},
);
