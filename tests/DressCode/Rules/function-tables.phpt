<?php declare(strict_types=1);

/**
 * The names of PHP functions a rule keeps in a table of its own are functions PHP declares. A table goes stale in
 * silence when PHP drops a function, and a name PHP no longer has is not an alias, a cast or a strict call of
 * anything; the catalog of PHP says so.
 */

use DressCode\Analyses\PhpSymbols;
use DressCode\Rules;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/**
 * A table of a rule, which the rule keeps private.
 * @param  class-string  $class
 * @return array<mixed>
 */
function tableOf(string $class, string $name): array
{
	$table = new ReflectionClassConstant($class, $name)->getValue();
	return is_array($table) ? $table : throw new LogicException("$class::$name is not a table.");
}


/**
 * The keys of a table of a rule, which are names of functions; PHP turns a numeric one into an integer.
 * @param  class-string  $class
 * @return list<string>
 */
function keysOf(string $class, string $name): array
{
	return array_map(strval(...), array_keys(tableOf($class, $name)));
}


$symbols = new PhpSymbols;


test('an alias and its canonical name are functions of PHP', function () use ($symbols) {
	foreach (tableOf(Rules\Functions\NoAliasFunctionsRule::class, 'Sets') as $set => $aliases) {
		foreach (is_array($aliases) ? $aliases : [] as $alias => $canonical) {
			Assert::true($symbols->isInternalFunction((string) $alias), "$alias in $set");
			Assert::true(is_string($canonical) && $symbols->isInternalFunction($canonical), "$alias in $set");
		}
	}
});


test('the calls the rules rewrite are functions of PHP', function () use ($symbols) {
	$functions = [
		...keysOf(Rules\Functions\StrictCallRule::class, 'StrictArguments'),
		'mb_detect_order',
		...keysOf(Rules\Functions\NoConversionFunctionsRule::class, 'Casts'),
		...keysOf(Rules\Comments\CommentedOutFunctionRule::class, 'ReturnParameters'),
	];
	foreach ($functions as $function) {
		Assert::true($symbols->isInternalFunction($function), $function);
	}
});
