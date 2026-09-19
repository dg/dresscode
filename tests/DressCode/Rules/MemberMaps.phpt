<?php declare(strict_types=1);

use DressCode\Analyses\MemberKind;
use DressCode\Rules\Classes\MemberMaps;
use DressCode\Rules\Classes\MemberPattern;
use Nette\Neon\Neon;
use Nette\Schema\Expect;
use Nette\Schema\Processor;
use Nette\Schema\ValidationException;
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
			'Nette\Forms\Form::FILLED' => 'Filled',
			'Nette\Utils\Strings::contains' => '\str_contains',
			'Nette\Forms\Form::$filled' => 'isFilled()',
			'A::get($name)' => 'get($name, 1, 1.5, null, true)',
			'A::load(fallback: $f, ...)' => 'load(generator: $f, ...)',
			'A::invoke($callable, ...$args)' => '$callable(...$args)',
			'A::setStrict($state)' => 'setFeature(\Latte\Feature::StrictParsing, $state)',
			'A::count()' => '\count(getRouters())',
			'A::isPost()' => "isMethod('POST')",
		],
		process(<<<'NEON'
			Nette\Forms\Form::FILLED: Filled
			Nette\Utils\Strings::contains: \str_contains
			Nette\Forms\Form::$filled: isFilled()
			'A::get($name)': get($name, 1, 1.5, null, true)
			'A::load(fallback: $f, ...)': load(generator: $f, ...)
			'A::invoke($callable, ...$args)': $callable(...$args)
			'A::setStrict($state)': setFeature(\Latte\Feature::StrictParsing, $state)
			'A::count()': \count(getRouters())
			'A::isPost()': 'isMethod(''POST'')'
			NEON),
	);
});


test('an argument of an entity is read the way NEON gives it: a string is a string, quoted or not, Class::NAME a constant', function () {
	$codes = [
		"isMethod('POST')" => "isMethod('POST')",
		'isMethod(POST)' => "isMethod('POST')",
		'get(inner("it\'s"), \'a\b\')' => "get(inner('it\\'s'), 'a\\\\b')",
		"add('Class::name', \$name, ...)" => 'add(Class::name, $name, ...)',
		'setMode(no, mode: strict, 1.5, null)' => "setMode(false, mode: 'strict', 1.5, null)",
		'new \A\Dumper(PHP_EOL)' => "new \\A\\Dumper('PHP_EOL')",
	];
	foreach ($codes as $written => $code) {
		Assert::same(['A::isPost' => $code], process("A::isPost: $written"));
	}

	// what is no code at all, and a chain, which is no single call
	foreach ([
		'at(2020-01-01)' => '%a% holds an argument that is no code, DateTimeImmutable; %a%',
		'first()second()' => '%a% a chain of them %a%',
	] as $written => $message) {
		$e = Assert::exception(fn() => process("A::isPost: $written"), ValidationException::class, $message);
		Assert::type(ValidationException::class, $e);
		Assert::same(['dresscode.codeEntity'], array_column($e->getMessageObjects(), 'code'));
	}
});


test('a key that does not read as a member is an error that names it', function () {
	$e = Assert::exception(
		fn() => process("Form.FILLED: Filled\n'Form::\$filled()': isFilled()"),
		ValidationException::class,
		"The member 'Form.FILLED' is not written as %a%",
	);
	Assert::type(ValidationException::class, $e);
	Assert::same(['dresscode.memberKey', 'dresscode.memberKey'], array_column($e->getMessageObjects(), 'code'));
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
