<?php declare(strict_types=1);

use DressCode\Rules\Classes\NamedArgumentsForFlagsRule;
use Nette\Neon\Neon;
use Nette\Schema\Processor;
use Nette\Schema\ValidationException;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('a key names the placeholder of the flags last, and a flag the named arguments it becomes', function () {
	$options = Neon::decode(<<<'XX'
		'Nette\Utils\Json::encode($value, $flags)':
			Nette\Utils\Json::PRETTY: {pretty: true}
		'Nette\Utils\Strings::split($subject, $pattern, $flags, ...)':
			\PREG_SPLIT_NO_EMPTY: {skipEmpty: true}
			PREG_SPLIT_DELIM_CAPTURE: {}
		XX);
	Assert::same($options, (new Processor)->process(NamedArgumentsForFlagsRule::getOptionsSchema(), $options));

	$errors = [
		[
			'A\Json::encode($value, 0)',
			['A\Json::PRETTY' => ['pretty' => true]],
			'The call A\Json::encode has to be given with the placeholder of its flags last, %a%',
		],
		[
			'A\Json::encode($value, ...$flags)',
			['A\Json::PRETTY' => ['pretty' => true]],
			'The call A\Json::encode has to be given with the placeholder of its flags last, %a%',
		],
		[
			'A\Json::encode($value, $flags)',
			['A\Json::PRETTY | 1' => ['pretty' => true]],
			"The flag 'A\\Json::PRETTY | 1' of A\\Json::encode is not written as Class::NAME or NAME.",
		],
	];
	foreach ($errors as [$key, $flags, $message]) {
		$e = Assert::exception(
			fn() => (new Processor)->process(NamedArgumentsForFlagsRule::getOptionsSchema(), [$key => $flags]),
			ValidationException::class,
			$message,
		);
		Assert::type(ValidationException::class, $e);
		Assert::same(['dresscode.memberMap'], array_column($e->getMessageObjects(), 'code'));
	}
});
