<?php declare(strict_types=1);

use DressCode\Measuring\Budget;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the budget is a hundredth of the files, two at least and twenty at most, or the one given', function () {
	Assert::same(2, new Budget(50)->files);
	Assert::same(5, new Budget(450)->files);
	Assert::same(20, new Budget(9000)->files);
	Assert::same(7, new Budget(9000, 7)->files);
});


test(
	'decisions are left unchecked until the rest fits, the one sparing the most files first, those the others stand on never',
	function () {
		$files = fn(string ...$names) => array_fill_keys($names, true);
		$fixable = [
			'blankLines.afterOpeningTag' => $files('a', 'b', 'c', 'd'),
			'spacing.cast' => $files('a', 'e'),
			'literals.quotes' => $files('f'),
			'indentation.unit' => $files('g', 'h', 'i'),
			'file.lineEnding' => $files('j', 'k', 'l'),
		];
		Assert::same(['blankLines.afterOpeningTag'], new Budget(100, 3)->findDropped($fixable, ['file.lineEnding']));
		Assert::same(['blankLines.afterOpeningTag', 'spacing.cast'], new Budget(100, 2)->findDropped($fixable, ['file.lineEnding']));
		Assert::same([], new Budget(100, 6)->findDropped($fixable, ['file.lineEnding']));
		Assert::true(Budget::isFoundation('indentation.chain'));

		// the decision whose files the others change least is left first, which leaves fewer than the costliest would
		$overlapping = [
			'blankLines.afterOpeningTag' => $files('a', 'b', 'c'),
			'spacing.cast' => $files('a', 'b'),
			'literals.quotes' => $files('d', 'e'),
		];
		Assert::same(['literals.quotes'], new Budget(100, 3)->findDropped($overlapping));
	},
);
