<?php declare(strict_types=1);

use DressCode\Analyses\Parameter;
use DressCode\Rules\Upgrading\{ArgumentPattern, ArgumentPatternItem};
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Parser;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/**
 * What the pattern makes of the arguments of the call: placeholder → the text of its argument, those of a variadic one
 * in a list, and `...` → the texts of the rest; null where the call is not of the shape.
 * @param  ?list<string>  $parameters  the names of the parameters of the method called
 * @return ?array<string, string|list<string>>
 */
function bind(string $pattern, string $call, ?array $parameters = null): ?array
{
	$node = (new Parser)->parseExpression($call);
	assert($node instanceof FunctionCallNode);
	$bindings = ArgumentPattern::parse($pattern)->bind(
		$node->arguments,
		$parameters === null ? null : array_map(fn(string $name) => new Parameter($name, 'mixed'), $parameters),
	);
	if ($bindings === null) {
		return null;
	}

	$result = [];
	foreach ($bindings->arguments + ($bindings->rest === [] ? [] : ['...' => $bindings->rest]) as $placeholder => $bound) {
		$result[$placeholder] = $bound instanceof ArgumentNode
			? $bound->text
			: array_map(fn(ArgumentNode $argument) => $argument->text, $bound);
	}

	return $result;
}


test('a pattern is read as the arguments of a call', function () {
	Assert::equal(
		[
			new ArgumentPatternItem('name'),
			new ArgumentPatternItem(literal: [true]),
			new ArgumentPatternItem(literal: [null]),
			new ArgumentPatternItem(literal: [-1.5]),
			new ArgumentPatternItem(literal: ['x']),
			new ArgumentPatternItem(literal: [[]]),
			new ArgumentPatternItem('f', parameterName: 'miss'),
			new ArgumentPatternItem(literal: [false], parameterName: 'strict'),
			new ArgumentPatternItem(variadic: true),
		],
		ArgumentPattern::parse('$name, TRUE, null, -1.5, "x", [], miss: $f, strict: false, ...')->items,
	);
	Assert::equal([new ArgumentPatternItem('callable'), new ArgumentPatternItem('args', variadic: true)], ArgumentPattern::parse('$callable, ...$args')->items);

	$errors = [
		'$a, run()' => "'run()' is no placeholder, no literal, and neither '...' nor '...\$name'.",
		'$a, PHP_EOL' => "'PHP_EOL' is no placeholder, %a%",
		'$a, ?' => "'?' is no placeholder, %a%",
		'&$a' => "'&\$a' is no placeholder, %a%",
		'$$a' => "'\$\$a' is no placeholder, %a%",
		'...[1]' => "'...[1]' is no placeholder, %a%",
		'$a, $b, $a' => 'the placeholder $a stands for two arguments.',
		'a: $a, $b' => "the positional '\$b' stands behind a named item.",
		'..., $a' => "'\$a' stands behind the item that takes the rest of the arguments.",
		'...$a, ...' => "'...' stands behind the item that takes the rest of the arguments.",
		'$a $b' => 'the arguments do not read as those of a call: %a%',
	];
	foreach ($errors as $pattern => $message) {
		Assert::exception(fn() => ArgumentPattern::parse($pattern), InvalidArgumentException::class, $message);
	}
});


test('positional items take the arguments by position, and the call has no argument nothing asked for', function () {
	Assert::same(['name' => "'a'", 'label' => '$l'], bind('$name, $label', "f('a', \$l)"));
	Assert::same([], bind('', 'f()'));
	Assert::null(bind('$name, $label', "f('a')"));
	Assert::null(bind('$name', "f('a', 'b')"));
	Assert::null(bind('', 'f(1)'));

	// a call that leaves arguments open is a closure, not a call
	Assert::null(bind('$name', 'f(...)'));
	Assert::null(bind('$name, $label', 'f($a, ?)'));

	// what an unpacked array holds nobody knows, so it is no argument of an item
	Assert::null(bind('$name, $label', 'f($a, ...$b)'));
});


test('a literal is the same value, however written', function () {
	Assert::same(['name' => '$n'], bind('$name, true', 'f($n, TRUE)'));
	Assert::same(['name' => '$n'], bind('$name, true', 'f($n, \true)'));
	Assert::null(bind('$name, true', 'f($n, false)'));
	Assert::null(bind('$name, true', 'f($n, 1)'));
	Assert::null(bind('$name, true', 'f($n, $flag)'));
	Assert::same([], bind("'POST', 16, null", 'f("POST", 0x10, NULL)'));
	Assert::null(bind("'POST'", "f('post')"));
});


test('an argument passed by name is the one of its parameter, which takes the parameters of the method', function () {
	$parameters = ['name', 'label', 'multiple'];
	Assert::same(['name' => "'a'", 'label' => "label: 'b'"], bind('$name, $label, true', "f('a', multiple: true, label: 'b')", $parameters));
	Assert::null(bind('$name, $label, true', "f('a', multiple: true)", $parameters));
	Assert::null(bind('$name, $label, true', "f('a', label: 'b', multiple: false)", $parameters));

	// without them a name cannot be placed, and the call is of no shape
	Assert::null(bind('$name, $label, true', "f('a', multiple: true, label: 'b')"));
	Assert::same(['name' => "'a'", 'label' => "'b'"], bind('$name, $label, true', "f('a', 'b', true)"));
});


test('a named item takes only the argument passed by that name, with the parameters or without them', function () {
	Assert::same(['f' => 'miss: $cb', '...' => ['$key']], bind('miss: $f, ...', 'f($key, miss: $cb)'));
	Assert::null(bind('miss: $f, ...', 'f($key, $cb)', ['key', 'miss']));
	Assert::null(bind('miss: $f, ...', 'f($key, factory: $cb)'));
	Assert::same([], bind('strict: true', 'f(strict: true)'));
	Assert::null(bind('strict: true', 'f(strict: false)'));
});


test('the rest of the arguments goes to ... as written, or under a variadic placeholder, which takes no name', function () {
	Assert::same(['key' => '$k'], bind('$key, ...', 'f($k)'));
	Assert::same(['key' => '$k', '...' => ['$a', '...$more', 'ttl: 10']], bind('$key, ...', 'f($k, $a, ...$more, ttl: 10)'));
	Assert::same(['callable' => '$cb', 'args' => []], bind('$callable, ...$args', 'f($cb)'));
	Assert::same(['callable' => '$cb', 'args' => ['1', '...$more']], bind('$callable, ...$args', 'f($cb, 1, ...$more)'));
	Assert::null(bind('$callable, ...$args', 'f($cb, 1, name: 2)'));
	Assert::null(bind('$key, ...', 'f()'));
});


test('of the patterns of one method the more specific is asked first', function () {
	$patterns = ['$a, ...', '$a, $b', '$a, true', '$a, $b, ...', '...', 'true, false', '$a'];
	usort($patterns, fn(string $a, string $b) => ArgumentPattern::parse($a)->compareSpecificity(ArgumentPattern::parse($b)));
	Assert::same(['true, false', '$a, true', '$a, $b', '$a', '$a, $b, ...', '$a, ...', '...'], $patterns);
});
