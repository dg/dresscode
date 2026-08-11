<?php declare(strict_types=1);

use DressCode\Rules\NativeType;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\{ConstExprParser, TokenIterator, TypeParser};
use PHPStan\PhpDocParser\ParserConfig;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


function convert(string $annotation, string $place = NativeType::Return, string $phpVersion = '8.4'): ?string
{
	$config = new ParserConfig([]);
	$type = new TypeParser($config, new ConstExprParser($config))->parse(new TokenIterator(new Lexer($config)->tokenize($annotation)));
	return NativeType::fromAnnotation($type, $place, $phpVersion, ['traversable'], ['T'], fn(string $class) => $class);
}


test('an annotation PHP can express becomes its native type', function () {
	Assert::same('int', convert('int'));
	Assert::same('array', convert('list<Foo>'));
	Assert::same('?Foo', convert('Foo|null'));
	Assert::same('?\Countable', convert('\Countable|null'));
	Assert::same('Foo|Bar|null', convert('null|Foo|Bar'));
	Assert::same('string|int|float|bool', convert('scalar'));
	Assert::same('static', convert('$this'));
	Assert::same('Foo&Bar', convert('Foo&Bar'));
	Assert::same('mixed', convert('?mixed'));
	Assert::same('null', convert('null'));
	Assert::same('?false', convert('false|null'));
	Assert::same('callable', convert('callable(int): void', NativeType::Parameter));
});


test('an annotation PHP cannot express has none', function () {
	Assert::null(convert('T'));
	Assert::null(convert('T|object'));
	Assert::null(convert('\int'));
	Assert::null(convert('\self'));
	Assert::null(convert('\string|null'));
	Assert::null(convert('resource'));
	Assert::null(convert('static', NativeType::Parameter));
	Assert::null(convert('callable', NativeType::Property));
	Assert::null(convert('null', phpVersion: '8.1'));
	Assert::null(convert('false|null', phpVersion: '8.1'));
	Assert::null(convert('Foo&Bar', phpVersion: '8.0'));
	Assert::null(convert('pure-callable(): void'));
});


test('void and never stand alone', function () {
	Assert::same('void', convert('void'));
	Assert::null(convert('string|void'));
	Assert::null(convert('void|null'));
	Assert::null(convert('?void'));
	Assert::null(convert('never|int'));
});


test('an intersection holds class types alone', function () {
	Assert::null(convert('$this&\Countable'));
	Assert::null(convert('static&Foo'));
	Assert::null(convert('int&string'));
	Assert::null(convert('object&Foo'));
	Assert::null(convert('callable&Foo', NativeType::Parameter));
	Assert::same('self&Foo', convert('self&Foo'));
});


test('a member another one covers is left out, as PHP refuses it redundant', function () {
	Assert::same('bool', convert('bool|false'));
	Assert::same('bool', convert('true|false'));
	Assert::same('int|bool', convert('int|false|true'));
	Assert::same('bool', convert('true|bool', phpVersion: '8.1'));
	Assert::same('iterable', convert('iterable|array'));
	Assert::same('iterable', convert('iterable|Traversable'));
	Assert::same('Iterator|iterable', convert('Iterator|iterable'));
	Assert::same('object', convert('object|Foo|static'));
	Assert::same('Foo', convert('Foo|foo'));
});


test('a keyword is no class name', function () {
	Assert::null(convert('empty'));
	Assert::null(convert('class|Foo'));
	Assert::same('Acme\Empty', convert('Acme\Empty'));
});
