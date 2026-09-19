<?php declare(strict_types=1);

use DressCode\Analyses\MemberKind;
use DressCode\Rules\Classes\MemberPattern;
use DressCode\Rules\Classes\MemberTarget;
use DressCode\Rules\Classes\ReplacedMembersRule;
use Nette\Schema\Processor;
use Nette\Schema\ValidationException;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('a replacement is written the way its key is', function () {
	$read = fn(string $key, string $code) => get_object_vars(MemberTarget::fromCode($code, MemberPattern::fromKey($key)));

	Assert::same(['class' => null, 'name' => 'Filled', 'isFunction' => false], $read('A\Form::FILLED', 'Filled'));
	Assert::same(['class' => null, 'name' => 'redraw', 'isFunction' => false], $read('A\Form::invalidate()', 'redraw()'));
	Assert::same(['class' => null, 'name' => 'items', 'isFunction' => false], $read('A\Form::$legacy', '$items'));
	Assert::same(['class' => 'A\Helpers', 'name' => 'create', 'isFunction' => false], $read('A\Form::make', '\A\Helpers::create'));
	Assert::same(['class' => 'A\Helpers', 'name' => 'count', 'isFunction' => false], $read('A\Form::$counter', 'A\Helpers::$count'));
	Assert::same(['class' => null, 'name' => 'Filled', 'isFunction' => false], $read('A\Form::FILLED', 'a\form::Filled')); // the class of the key
	Assert::same(['class' => null, 'name' => 'str_contains', 'isFunction' => true], $read('A\Strings::contains', '\str_contains'));
	Assert::same(['class' => null, 'name' => 'A\Utils\contains', 'isFunction' => true], $read('A\Strings::contains()', '\A\Utils\contains()'));
});


test('a replacement of another kind, of a constructor or of a call of some arguments is an error saying so', function () {
	$errors = [
		['A\Form::$legacy', 'items', "The replacement 'items' of A\\Form::legacy is not of its kind: %a%"],
		['A\Form::FILLED', '$filled', "The replacement '\$filled' of A\\Form::FILLED is not of its kind: %a%"],
		['A\Form::$legacy', '$items()', "The replacement '\$items()' of A\\Form::legacy is not of its kind: %a%"],
		['A\Form::$legacy', '\strlen', 'The property A\Form::$legacy cannot be replaced by the function strlen().'],
		['A\Form::FILLED', 'Form->Filled', "The replacement 'Form->Filled' of A\\Form::FILLED is not written as %a%"],
		['A\Form::__construct', 'create', 'The member A\Form::__construct is given a name instead, %a%'],
		['A\Form::add($name, true)', 'addMulti', 'The member A\Form::add is given a name instead, %a%'],
	];
	foreach ($errors as [$key, $code, $message]) {
		Assert::exception(fn() => MemberTarget::fromCode($code, MemberPattern::fromKey($key)), InvalidArgumentException::class, $message);
	}

	// which the options of the rule say as an error of the configuration
	$e = Assert::exception(
		fn() => (new Processor)->process(ReplacedMembersRule::getOptionsSchema(), ['A\Form::$legacy' => 'items', 'A\Form::OLD' => 'keep']),
		ValidationException::class,
		"The replacement 'items' of A\\Form::legacy is not of its kind: %a%",
	);
	Assert::type(ValidationException::class, $e);
	Assert::same(['dresscode.memberMap'], array_column($e->getMessageObjects(), 'code'));
});


test('a replacement is named in a message by the kind of the member it stands for', function () {
	$key = MemberPattern::fromKey('Nette\Forms\Form::old');
	Assert::same('Form::Renewed', new MemberTarget(null, 'Renewed')->describe(MemberKind::Constant, $key));
	Assert::same('Form::renewed()', new MemberTarget(null, 'renewed')->describe(MemberKind::StaticMethod, $key));
	Assert::same('Form::$renewed', new MemberTarget(null, 'renewed')->describe(MemberKind::Property, $key));
	Assert::same('Nette\Forms\Helpers::renewed()', new MemberTarget('Nette\Forms\Helpers', 'renewed')->describe(MemberKind::Method, $key));
	Assert::same('str_contains()', new MemberTarget(null, 'str_contains', isFunction: true)->describe(MemberKind::Method, $key));
});
