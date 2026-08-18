<?php declare(strict_types=1);

use DressCode\Rules\Upgrading\FunctionPattern;
use PhpSyntax\Builder;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


function callOf(string $code): FunctionCallNode
{
	$call = (new Builder)->expression($code);
	return $call instanceof FunctionCallNode ? $call : throw new LogicException;
}


test('a function is written by its name, with the shape of its arguments or without', function () {
	$pattern = FunctionPattern::fromKey('\Utf8_Encode($string)');
	Assert::same('utf8_encode', $pattern->name);
	Assert::notNull($pattern->bind(callOf('utf8_encode($a)')->arguments));
	Assert::null($pattern->bind(callOf('utf8_encode($a, $b)')->arguments));
	Assert::null($pattern->bind(callOf('utf8_encode()')->arguments));

	$any = FunctionPattern::fromKey('curl_close');
	Assert::null($any->arguments);
	Assert::notNull($any->bind(callOf('curl_close($a, $b)')->arguments));
	Assert::same('acme\text\normalize', FunctionPattern::fromKey('Acme\Text\normalize()')->name);
});


test('a key that is no function is refused with the shape a function is written in', function () {
	Assert::exception(fn() => FunctionPattern::fromKey('Foo::bar()'), InvalidArgumentException::class, 'The function `Foo::bar()` is not written as `name` or `name($argument, ...)`.');
	Assert::exception(fn() => FunctionPattern::fromKey('foo($a'), InvalidArgumentException::class);
	Assert::exception(fn() => FunctionPattern::fromKey('foo(=)'), InvalidArgumentException::class, 'The function `foo(=)` cannot be read: %a%');
});
