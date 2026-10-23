<?php declare(strict_types=1);

use DressCode\Decision;
use DressCode\Domains\{Count, Names, Shapes, Words};
use DressCode\Measuring\{MeasurementPlan, MeasuringMethod};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the domain tells the values a decision is measured under and the one letting all of them pass', function () {
	$words = MeasurementPlan::for(new Decision('project.quotes', new Words(['single' => '', 'double' => '']), 'Quotes'));
	Assert::same(['single' => 'single', 'double' => 'double'], $words->candidates);
	Assert::null($words->any);
	Assert::same(MeasuringMethod::Values, $words->method);

	$tolerant = MeasurementPlan::for(new Decision('project.items', new Words(['perLine' => '', 'fill' => ''], tolerance: true), 'Items'));
	Assert::same(['perLine', 'fill'], $tolerant->any);

	$shapes = MeasurementPlan::for(new Decision(
		'project.call',
		new Shapes(['compact' => ['foo()', ''], 'spaced' => ['foo ()', '']]),
		'Call',
	));
	Assert::same(['compact' => 'foo()', 'spaced' => 'foo ()'], $shapes->candidates);

	$required = MeasurementPlan::for(new Decision('project.visibility', new Words(['required' => 'always there']), 'Visibility'));
	Assert::same(MeasuringMethod::OneSided, $required->method);

	// a count from 0 to 2 within its bounds, and what the standards give it
	$count = new Decision('project.blank', new Count(0, 5), 'Blank lines');
	Assert::same([0 => 0, 1 => 1, 2 => 2, 4 => 4], MeasurementPlan::for($count, [4, 'keep', 1])->candidates);
	Assert::same('0-4', MeasurementPlan::for($count, [4])->any);
	Assert::same([1 => 1], MeasurementPlan::for(new Decision('project.one', new Count(1, 1, range: false), 'One'))->candidates);

	$names = MeasurementPlan::for(new Decision('project.list', new Names, 'List'));
	Assert::same(MeasuringMethod::Policy, $names->method);
	Assert::same([], $names->candidates);
});


test('the width of a line is measured on a ladder of its parameter, the probes laying the requirement with each step', function () {
	$plan = MeasurementPlan::for(new Decision('file.longLines', new Words(['forbidden' => 'never there']), 'Long lines'));
	Assert::same(MeasuringMethod::Ladder, $plan->method);
	Assert::same(MeasurementPlan::LineLengths, array_values($plan->candidates));
	Assert::same(
		['file' => ['longLines' => 'forbidden', 'lineLength' => 120]],
		$plan->buildProbes([])['file.longLines=120'] ?? null,
	);
});
