<?php declare(strict_types=1);

use DressCode\Rules\Upgrading\MemberPattern;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('a key is read the way the upgrading data of PHP write a method', function () {
	$read = function (string $key): array {
		$pattern = MemberPattern::fromKey($key);
		return array_replace(get_object_vars($pattern), ['arguments' => $pattern->arguments === null ? null : count($pattern->arguments->items)]);
	};

	Assert::same(['class' => 'Acme\Shop\Order', 'name' => 'size', 'arguments' => 0], $read('\Acme\Shop\Order::size()'));
	Assert::same(['class' => 'Acme\Shop\Order', 'name' => 'size', 'arguments' => 1], $read('Acme\Shop\Order::size(...$args)'));
	Assert::same(['class' => 'dibi', 'name' => 'addUpload', 'arguments' => 3], $read('dibi::addUpload( $name, $label, true )'));

	foreach (['STATUS_PAID', 'Order::', 'Order::a-b', 'Order::name(', 'Order::name() ?? 1', 'Order.name', 'A\\\\B::name', 'Order::$$name'] as $key) {
		Assert::exception(fn() => MemberPattern::fromKey($key), InvalidArgumentException::class, "The member `$key` is not written as %a%");
	}

	Assert::exception(fn() => MemberPattern::fromKey('Order::add($name, run())'), InvalidArgumentException::class, "The member `Order::add(\$name, run())` cannot be read: `run()` is no placeholder, %a%");
});
