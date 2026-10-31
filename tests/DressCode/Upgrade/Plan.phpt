<?php declare(strict_types=1);

use DressCode\Config\ProjectPackages;
use DressCode\Tristate;
use DressCode\Upgrade\{BlindSpots, Blocker, Blockers, Estimate, Phase, Plan, PlanBuilder, PlanFile, Step, StepKind, Target, Topic};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


function createPlanEstimate(): Estimate
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
		[new Blockers(new Target('acme/mail', '3.0'), [new Blocker('acme/shop', 'v1.0.0', '^2.0')])],
		[],
		[],
		true,
		false,
		[],
	);
}


test('the steps go from what a human makes way for, through the bridge and the raise, to the jump and what is left', function () {
	$project = new ProjectPackages(required: ['php' => '>=8.1', 'acme/mail' => '^2.4']);
	$plan = new PlanBuilder($project)->build(createPlanEstimate(), 'vendor/bin/tester tests -o junit:{junit}');

	Assert::equal([
		new Step(StepKind::Manual, 'make way for acme/mail 3.0: acme/shop', suggest: 'composer why-not acme/mail 3.0'),
		new Step(StepKind::Fix, 'works on the versions allowed today', ['php']),
		// each constraint raised in the shape the project writes it
		new Step(StepKind::Composer, 'raise php to >=8.4, acme/mail to ^3.0', require: ['php' => '>=8.4', 'acme/mail' => '^3.0']),
		new Step(StepKind::Fix, 'needs the targets', ['php', 'acme/mail']),
		new Step(StepKind::Fix, 'code for the versions given up', ['hygiene.uselessVersionCheck']),
		// what a newer PHP offers waits until the user takes it in
		new Step(StepKind::Fix, 'what the targets offer', ['upgrading.syntax.octalPrefix'], skip: true),
	], $plan->steps);
	Assert::same(
		[1 => 'Manual', 2 => 'Fix', 3 => 'Composer', 4 => 'Fix', 5 => 'Fix'],
		array_map(fn(Step $step) => $step->kind->name, $plan->findSteps()),
	);
	Assert::same('vendor/bin/tester tests -o junit:{junit}', $plan->testCommand);
});


test('a target the lowest version allowed today already reaches raises nothing', function () {
	$project = new ProjectPackages(required: ['php' => '^8.4', 'acme/mail' => '^3.1']);
	$plan = new PlanBuilder($project)->build(createPlanEstimate());
	Assert::false(in_array(StepKind::Composer, array_map(fn(Step $step) => $step->kind, $plan->steps), strict: true));
});


test('the file of the plan gives back the plan it was written from', function () {
	$root = createTempDir('plan-file');
	$plan = new PlanBuilder(new ProjectPackages(required: ['php' => '^8.1']))
		->build(createPlanEstimate(), 'composer test -- {junit}', 'composer quick -- {junit}');
	PlanFile::write($plan, $root);
	Assert::equal($plan, PlanFile::read($root));
});


test('the file of the plan is read as the team writes it, and one that is no plan is refused', function () {
	$root = createTempDir('plan-edited');
	file_put_contents("$root/" . PlanFile::Name, <<<'XX'
		version: 1
		targets: {php: '8.4'}
		tests: {command: 'vendor/bin/phpunit --log-junit {junit}'}
		steps:
			- {kind: fix, only: [php]}
			- {kind: composer, title: raise, require: {php: ^8.4}, skip: true}
		XX);
	Assert::equal(
		new Plan(
			[Target::php('8.4')],
			[new Step(StepKind::Fix, 'fix', ['php']), new Step(StepKind::Composer, 'raise', require: ['php' => '^8.4'], skip: true)],
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
		"version: 1\ntargets: {php: '8.4'}\nsteps:\n\t- {title: who knows}\n" => '`steps.1` must give its `kind`, `fix`, `composer` or `manual`',
		"version: 1\ntargets: {php: '8.4'}\nsteps:\n\t- {kind: fix, onyl: [php]}\n" => '`steps.1.onyl` is no key of a step of the kind `fix`',
		"version: 1\ntargets: {php: '8.4'}\nsteps:\n\t- {kind: fix, only: [php], skip: falsee}\n" => '`steps.1.skip` must be `true` or `false`',
		"version: 1\ntargets: {php: '8.4'}\nsteps:\n\t- {kind: composer}\n" => '`steps.1.require` must give the constraints the step writes',
	] as $text => $message) {
		file_put_contents("$root/" . PlanFile::Name, $text);
		Assert::exception(fn() => PlanFile::read($root), DressCode\ConfigurationException::class, "The plan `%a%`: $message.");
	}
});


test('a library keeps the versions it allows: the bridge and the constraints widened to the targets', function () {
	$project = new ProjectPackages(required: ['php' => '>=8.1', 'acme/mail' => '^2.4']);
	$plan = new PlanBuilder($project, library: true)->build(createPlanEstimate());

	Assert::equal([
		new Step(StepKind::Manual, 'make way for acme/mail 3.0: acme/shop', suggest: 'composer why-not acme/mail 3.0'),
		new Step(StepKind::Fix, 'works on the versions allowed today', ['php']),
		new Step(StepKind::Composer, 'widen acme/mail to ^2.4 || ^3.0', require: ['acme/mail' => '^2.4 || ^3.0'], update: false),
	], $plan->steps);
});


test('a library says so by its type and has no lock of its own, one with a lock maybe being one', function () {
	$root = createTempDir('plan-library');
	file_put_contents("$root/composer.json", '{"type": "library"}');
	Assert::same(Tristate::Yes, PlanBuilder::isLibrary($root));
	file_put_contents("$root/composer.lock", '{}');
	Assert::same(Tristate::Maybe, PlanBuilder::isLibrary($root));
	file_put_contents("$root/composer.json", '{}');
	Assert::same(Tristate::No, PlanBuilder::isLibrary($root));
	unlink("$root/composer.lock");
	Assert::same(Tristate::No, PlanBuilder::isLibrary($root));
});
