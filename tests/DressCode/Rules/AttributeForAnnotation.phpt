<?php declare(strict_types=1);

use DressCode\Rules\PhpDoc\AttributeForAnnotationRule;
use Nette\Neon\Neon;
use Nette\Schema\Processor;
use Nette\Schema\ValidationException;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('an attribute is a class with its arguments, which NEON may read as an entity', function () {
	$options = Neon::decode(<<<'XX'
		persistent: Nette\Application\Attributes\Persistent
		crossOrigin: Nette\Application\Attributes\Requires(sameOrigin: false, methods: GET)
		old: keep
		XX);
	Assert::same(
		[
			'persistent' => 'Nette\Application\Attributes\Persistent',
			'crossOrigin' => "Nette\\Application\\Attributes\\Requires(sameOrigin: false, methods: 'GET')",
			'old' => 'keep',
		],
		(new Processor)->process(AttributeForAnnotationRule::getOptionsSchema(), $options),
	);

	$e = Assert::exception(
		fn() => (new Processor)->process(AttributeForAnnotationRule::getOptionsSchema(), ['persistent' => '#[Persistent]']),
		ValidationException::class,
		"The attribute '#[Persistent]' written instead of @persistent is not a class with its arguments, %a%",
	);
	Assert::type(ValidationException::class, $e);
	Assert::same(['dresscode.attributeCode'], array_column($e->getMessageObjects(), 'code'));
});
