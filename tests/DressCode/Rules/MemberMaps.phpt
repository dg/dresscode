<?php declare(strict_types=1);

use DressCode\Analyses\MemberKind;
use DressCode\Rules\Upgrading\{MemberMaps, MemberPattern};
use Nette\Neon\Neon;
use Nette\Schema\{Expect, Processor, ValidationException};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


function process(string ...$layers): mixed
{
	$schema = MemberMaps::map(MemberMaps::code(), 'The member → the code written instead');
	return (new Processor)->processMultiple($schema, array_map(Neon::decode(...), array_values($layers)));
}


test('a value is code, whether NEON reads it as a string or as an entity', function () {
	Assert::same(
		[
			'Acme\Shop\Order::STATUS_PAID' => 'StatusPaid',
			'Acme\Text\Search::contains' => '\str_contains',
			'Acme\Shop\Order::$paid' => 'isPaid()',
			'A::get($name)' => 'get($name, 1, 1.5, null, true)',
			'A::load(miss: $f, ...)' => 'load(factory: $f, ...)',
			'A::invoke($callable, ...$args)' => '$callable(...$args)',
			'A::setStrict($state)' => 'setFlag(\Acme\Flag::Strict, $state)',
			'A::count()' => '\count(getEntries())',
			'A::isDebug()' => "hasMode('debug')",
		],
		process(<<<'NEON'
			Acme\Shop\Order::STATUS_PAID: StatusPaid
			Acme\Text\Search::contains: \str_contains
			Acme\Shop\Order::$paid: isPaid()
			'A::get($name)': get($name, 1, 1.5, null, true)
			'A::load(miss: $f, ...)': load(factory: $f, ...)
			'A::invoke($callable, ...$args)': $callable(...$args)
			'A::setStrict($state)': setFlag(\Acme\Flag::Strict, $state)
			'A::count()': \count(getEntries())
			'A::isDebug()': 'hasMode(''debug'')'
			NEON),
	);
});


test('an argument of an entity is read the way NEON gives it: a string is a string, quoted or not, Class::NAME a constant', function () {
	$codes = [
		"hasMode('debug')" => "hasMode('debug')",
		'hasMode(debug)' => "hasMode('debug')",
		'get(inner("it\'s"), \'a\b\')' => "get(inner('it\\'s'), 'a\\\\b')",
		"add('Class::name', \$name, ...)" => 'add(Class::name, $name, ...)',
		'setMode(no, mode: strict, 1.5, null)' => "setMode(false, mode: 'strict', 1.5, null)",
		'new \A\Formatter(PHP_EOL)' => "new \\A\\Formatter('PHP_EOL')",
	];
	foreach ($codes as $written => $code) {
		Assert::same(['A::isDebug' => $code], process("A::isDebug: $written"));
	}

	// what is no code at all, and a chain, which is no single call
	foreach ([
		'at(2020-01-01)' => '%a% holds an argument that is no code, DateTimeImmutable; %a%',
		'first()second()' => '%a% a chain of them %a%',
	] as $written => $message) {
		$e = Assert::exception(fn() => process("A::isDebug: $written"), ValidationException::class, $message);
		Assert::type(ValidationException::class, $e);
		Assert::same(['dresscode.codeEntity'], array_column($e->getMessageObjects(), 'code'));
	}
});


test('a key that does not read as a member is an error that names it', function () {
	$e = Assert::exception(
		fn() => process("Order.STATUS_PAID: StatusPaid\n'Order::\$paid()': isPaid()"),
		ValidationException::class,
		"The member 'Order.STATUS_PAID' is not written as %a%",
	);
	Assert::type(ValidationException::class, $e);
	Assert::same(['dresscode.memberMap', 'dresscode.memberMap'], array_column($e->getMessageObjects(), 'code'));
	Assert::exception(fn() => process('A::b: [1]'), ValidationException::class);
});


test('a later layer withdraws an entry with keep, and the rule never sees it', function () {
	$options = process(
		"A::OLD: New\n'A::old()': renamed\nA::\$old: \$new",
		"A::OLD: keep\nB::Old: other",
	);
	Assert::same(['A::OLD' => 'keep', 'A::old()' => 'renamed', 'A::$old' => '$new', 'B::Old' => 'other'], $options);

	$entries = MemberMaps::read($options, fn(string $value, MemberPattern $pattern) => "$pattern->class: $value");
	Assert::same(['old'], array_keys($entries));
	Assert::same(
		[[MemberKind::Method, 'A: renamed'], [MemberKind::Property, 'A: $new'], [null, 'B: other']],
		array_map(fn(array $entry) => [$entry[0]->kind, $entry[1]], $entries['old']),
	);

	$schema = MemberMaps::map(Expect::structure(['get' => MemberMaps::code()])->castTo('array'), '');
	Assert::same(
		['A::$a' => ['get' => 'isA()'], 'A::$b' => 'keep'],
		(new Processor)->process($schema, Neon::decode("A::\$a: {get: isA()}\nA::\$b: keep")),
	);
});
