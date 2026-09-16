<?php declare(strict_types=1);

use DressCode\Rules\Upgrading\AttributeForAnnotationRule;
use Nette\Neon\Neon;
use Nette\Schema\{Processor, ValidationException};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('an attribute is a class with its arguments, which NEON may read as an entity', function () {
	$options = Neon::decode(<<<'XX'
		cached: Acme\Attributes\Cached
		secured: Acme\Attributes\Access(public: false, methods: GET)
		old: keep
		Acme\Attributes\Secured: Acme\Attributes\Access(public: false)
		XX);
	Assert::same(
		[
			'cached' => 'Acme\Attributes\Cached',
			'secured' => "Acme\\Attributes\\Access(public: false, methods: 'GET')",
			'old' => 'keep',
			'Acme\Attributes\Secured' => 'Acme\Attributes\Access(public: false)',
		],
		(new Processor)->process(AttributeForAnnotationRule::getOptionsSchema(), $options),
	);

	$e = Assert::exception(
		fn() => (new Processor)->process(AttributeForAnnotationRule::getOptionsSchema(), ['cached' => '#[Cached]']),
		ValidationException::class,
		"The attribute '#[Cached]' written instead of @cached is not a class with its arguments, %a%",
	);
	Assert::type(ValidationException::class, $e);
	Assert::same(['dresscode.attributeCode'], array_column($e->getMessageObjects(), 'code'));

	Assert::exception(
		fn() => (new Processor)->process(AttributeForAnnotationRule::getOptionsSchema(), ['App\Secured' => '#[Access]']),
		ValidationException::class,
		"The attribute '#[Access]' written instead of #[App\\Secured] is not a class with its arguments, %a%",
	);
});


test('a namespace of annotations is written instead as a namespace', function () {
	$options = ['Acme\Validation\*' => 'Acme\Validation\*', 'Acme\Http\Annotation\*' => 'Acme\Http\Attribute\*'];
	Assert::same($options, (new Processor)->process(AttributeForAnnotationRule::getOptionsSchema(), $options));

	Assert::exception(
		fn() => (new Processor)->process(AttributeForAnnotationRule::getOptionsSchema(), ['Acme\Validation\*' => 'Acme\Validation\Size']),
		ValidationException::class,
		"The namespace Acme\\Validation\\* is written instead as 'Acme\\Validation\\Size', which is not a namespace ending with \\*.",
	);
});
