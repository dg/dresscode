<?php declare(strict_types=1);

use DressCode\Rules\Classes\ReplacedCallsRule;
use Nette\Schema\Processor;
use Nette\Schema\ValidationException;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('an expression that cannot stand for a call of its key is an error of the configuration', function () {
	$errors = [
		[
			'A\Form::add($name)',
			'addText($name',
			"The code 'addText(\$name' written instead of A\\Form::add does not read as an expression: %a%",
		],
		['A\Form::add($name)', 'addText($label)', "The code 'addText(\$label)' uses \$label, which the key A\\Form::add does not name."],
		[
			'A\Form::add($name)',
			'addText($this, $name)',
			"The code 'addText(\$this, \$name)' uses \$this, which the key A\\Form::add does not name.",
		],
		[
			'A\Form::add($name)',
			'addText($name, ...)',
			"The code 'addText(\$name, ...)' writes ..., which the key A\\Form::add does not take.",
		],
		[
			'A\Form::add($name, ...$rest)',
			'addText($name, $rest)',
			"The code 'addText(\$name, \$rest)' has to write \$rest as the arguments it stands for, ...\$rest.",
		],
		['A\Form::FILLED', 'Filled', 'The member A\Form::FILLED is given an expression instead, which takes a key that is a call, %a%'],
		['A\Form::$filled', 'isFilled()', 'The property A\Form::$filled is given {get: ..., set: ...}, %a%'],
		['A\Form::$filled', [], 'The property A\Form::$filled is given {get: ..., set: ...}, %a%'],
		[
			'A\Form::$filled',
			['set' => 'setFilled($state)'],
			"The code 'setFilled(\$state)' uses \$state, which the key A\\Form::filled does not name.",
		],
		['A\Form::add($name)', ['get' => 'addText()'], 'The member A\Form::add is given an expression instead, %a%'],
	];
	foreach ($errors as [$key, $code, $message]) {
		$e = Assert::exception(
			fn() => (new Processor)->process(ReplacedCallsRule::getOptionsSchema(), [$key => $code]),
			ValidationException::class,
			$message,
		);
		Assert::type(ValidationException::class, $e);
		Assert::same(['dresscode.memberMap'], array_column($e->getMessageObjects(), 'code'));
	}

	// a key with any arguments passes them on, a property takes one side or both, and an entry may be withdrawn
	$options = [
		'A\Form::add(...$args)' => 'addText(...$args)',
		'A\Form::$filled' => ['get' => 'isFilled()'],
		'A\Form::$value' => ['get' => 'getValue()', 'set' => 'setValue($value)'],
		'A\Form::old()' => 'keep',
	];
	Assert::same(
		array_replace($options, ['A\Form::$filled' => ['get' => 'isFilled()', 'set' => null]]),
		(new Processor)->process(ReplacedCallsRule::getOptionsSchema(), $options),
	);
});
