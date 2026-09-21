<?php declare(strict_types=1);

use DressCode\Rules\Upgrading\ReplacedCallsRule;
use Nette\Schema\{Processor, ValidationException};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('an expression that cannot stand for a call of its key is an error of the configuration', function () {
	$errors = [
		[
			'A\Order::add($name)',
			'addLine($name',
			"The code `addLine(\$name` written instead of `A\\Order::add` does not read as an expression: %a%",
		],
		[
			'A\Order::add($name)',
			'addLine($label)',
			"The code `addLine(\$label)` uses `\$label`, which the key `A\\Order::add` does not name.",
		],
		[
			'A\Order::add($this)',
			'addLine($this)',
			"The member `A\\Order::add(\$this)` cannot be read: `\$this` is no placeholder; in the expression written instead it stands for what the call is made on.",
		],
		[
			'A\Order::add($name)',
			'addLine($name, ...)',
			"The code `addLine(\$name, ...)` writes `...`, which the key `A\\Order::add` does not take.",
		],
		[
			'A\Order::add($name, ...$rest)',
			'addLine($name, $rest)',
			"The code `addLine(\$name, \$rest)` must write `\$rest` as the arguments it stands for, `...\$rest`.",
		],
		[
			'A\Order::STATUS_PAID',
			'StatusPaid',
			'The member `A\Order::STATUS_PAID` cannot be replaced by an expression: only a call can, %a%',
		],
		[
			'A\Order::$paid',
			'isPaid()',
			"The property `A\\Order::\$paid` is replaced through its hooks: `A\\Order::\$paid::get` for what a read of it becomes, %a%",
		],
		[
			'A\Order::$paid::set',
			'setPaid($state)',
			"The code `setPaid(\$state)` uses `\$state`, which the key `A\\Order::paid` does not name.",
		],
		['A\Order::add::get', 'addLine()', 'The member `A\\Order::add::get` names a hook, which only a property has, %a%'],
		[
			'A\Token::is(int ...)',
			'is([])',
			'The member `A\\Token::is(int ...)` cannot be read: the type `int` stands in front of `...`, which has no placeholder to take it, `int ...$args`.',
		],
	];
	foreach ($errors as [$key, $code, $message]) {
		$e = Assert::exception(
			fn() => (new Processor)->process(grammarOf(ReplacedCallsRule::class), [$key => $code]),
			ValidationException::class,
			$message,
		);
		Assert::type(ValidationException::class, $e);
		Assert::same(['dresscode.memberMap'], array_column($e->getMessageObjects(), 'code'));
	}

	// a key with any arguments passes them on, a property takes one hook or both, and an entry may be withdrawn
	$options = [
		'A\Order::add(...$args)' => 'addLine(...$args)',
		'A\Order::$paid::get' => 'isPaid()',
		'A\Order::$value::get' => 'getValue()',
		'A\Order::$value::set' => 'setValue($value)',
		'A\Order::old()' => 'keep',
	];
	Assert::same($options, (new Processor)->process(grammarOf(ReplacedCallsRule::class), $options));
});


test('a key whose code a key of its class takes again, itself or through another one, is an error of the configuration', function () {
	// every key of these is in the cycle
	$cycles = [
		['A\Token::is(...$kinds)' => 'is([...$kinds])'],
		['A\Token::has($kind)' => '$this->is($kind)', 'A\Token::is($kind)' => 'has($kind)'],
		// a type the code written instead may have, an array literal being an array
		['A\Token::is(array ...$kinds)' => 'is([...$kinds])'],
		['A\Token::is(mixed ...$kinds)' => 'is([...$kinds])'],
		['A\Token::is(?int $kind)' => 'is($kind ?? 0)'],
	];
	foreach ($cycles as $options) {
		$e = Assert::exception(
			fn() => (new Processor)->process(grammarOf(ReplacedCallsRule::class), $options),
			ValidationException::class,
		);
		Assert::type(ValidationException::class, $e);
		$expected = [];
		foreach ($options as $key => $code) {
			$expected[] = "The code `$code` written instead of `$key` is a call the keys take again, so the rewrite would never end.";
		}

		Assert::same($expected, array_column($e->getMessageObjects(), 'message'));
	}

	// a code the keys take once more, or a key of another class does, or one of a type the code is not of, ends
	$options = [
		'A\Token::has(int|string ...$kinds)' => 'has([...$kinds])',
		'A\Token::is($a, $b)' => 'is([$a, $b])',
		'A\Token::is($a)' => 'isKind($a)',
		'A\Token::isKind($a)' => '\B\Kinds::is($this, $a)',
		'A\Other::is($a)' => 'isKind($a)',
	];
	Assert::same($options, (new Processor)->process(grammarOf(ReplacedCallsRule::class), $options));
});
