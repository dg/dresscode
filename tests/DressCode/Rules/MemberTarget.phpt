<?php declare(strict_types=1);

use DressCode\Analyses\MemberKind;
use DressCode\Rules\Upgrading\{MemberPattern, MemberTarget, ReplacedMembersRule};
use Nette\Schema\{Processor, ValidationException};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('a replacement is written the way its key is', function () {
	$read = fn(string $key, string $code) => get_object_vars(MemberTarget::fromCode($code, MemberPattern::fromKey($key)));

	Assert::same(['class' => null, 'name' => 'StatusPaid', 'isFunction' => false], $read('A\Order::STATUS_PAID', 'StatusPaid'));
	Assert::same(['class' => null, 'name' => 'redraw', 'isFunction' => false], $read('A\Order::invalidate()', 'redraw()'));
	Assert::same(['class' => null, 'name' => 'items', 'isFunction' => false], $read('A\Order::$legacy', '$items'));
	Assert::same(['class' => 'A\Helpers', 'name' => 'create', 'isFunction' => false], $read('A\Order::make', '\A\Helpers::create'));
	Assert::same(['class' => 'A\Helpers', 'name' => 'count', 'isFunction' => false], $read('A\Order::$counter', 'A\Helpers::$count'));
	Assert::same(['class' => null, 'name' => 'StatusPaid', 'isFunction' => false], $read('A\Order::STATUS_PAID', 'a\order::StatusPaid')); // the class of the key
	Assert::same(['class' => null, 'name' => 'str_contains', 'isFunction' => true], $read('A\Search::contains', '\str_contains'));
	Assert::same(['class' => null, 'name' => 'A\Utils\contains', 'isFunction' => true], $read('A\Search::contains()', '\A\Utils\contains()'));
});


test('a replacement of another kind, of a constructor or of a call of some arguments is an error saying so', function () {
	$errors = [
		['A\Order::$legacy', 'items', "The replacement 'items' of A\\Order::legacy is not of its kind: %a%"],
		['A\Order::STATUS_PAID', '$paid', "The replacement '\$paid' of A\\Order::STATUS_PAID is not of its kind: %a%"],
		['A\Order::$legacy', '$items()', "The replacement '\$items()' of A\\Order::legacy is not of its kind: %a%"],
		['A\Order::$legacy', '\strlen', 'The property A\Order::$legacy cannot be replaced by the function strlen().'],
		[
			'A\Order::STATUS_PAID',
			'Order->StatusPaid',
			"The replacement 'Order->StatusPaid' of A\\Order::STATUS_PAID is not written as %a%",
		],
		['A\Order::__construct', 'create', 'The member A\Order::__construct cannot be replaced by a bare name, %a%'],
		['A\Order::add($name, true)', 'addMulti', 'The member A\Order::add cannot be replaced by a bare name, %a%'],
	];
	foreach ($errors as [$key, $code, $message]) {
		Assert::exception(fn() => MemberTarget::fromCode($code, MemberPattern::fromKey($key)), InvalidArgumentException::class, $message);
	}

	// which the options of the rule say as an error of the configuration
	$e = Assert::exception(
		fn() => (new Processor)->process(ReplacedMembersRule::getOptionsSchema(), ['A\Order::$legacy' => 'items', 'A\Order::OLD' => 'keep']),
		ValidationException::class,
		"The replacement 'items' of A\\Order::legacy is not of its kind: %a%",
	);
	Assert::type(ValidationException::class, $e);
	Assert::same(['dresscode.memberMap'], array_column($e->getMessageObjects(), 'code'));
});


test('a replacement is named in a message by the kind of the member it stands for', function () {
	$key = MemberPattern::fromKey('Acme\Shop\Order::old');
	Assert::same('Order::Renewed', new MemberTarget(null, 'Renewed')->describe(MemberKind::Constant, $key));
	Assert::same('Order::renewed()', new MemberTarget(null, 'renewed')->describe(MemberKind::StaticMethod, $key));
	Assert::same('Order::$renewed', new MemberTarget(null, 'renewed')->describe(MemberKind::Property, $key));
	Assert::same('Acme\Shop\Helpers::renewed()', new MemberTarget('Acme\Shop\Helpers', 'renewed')->describe(MemberKind::Method, $key));
	Assert::same('str_contains()', new MemberTarget(null, 'str_contains', isFunction: true)->describe(MemberKind::Method, $key));
});
