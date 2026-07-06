<?php declare(strict_types=1);

use DressCode\Engine\Suppression;
use PhpSyntax\Parser\Parser;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$aliases = ['Generic.Files.LineLength' => 'dresscode/line-length'];
$resolve = fn(string $name) => $aliases[$name] ?? (str_starts_with($name, 'dresscode/') ? $name : null);


/** @param Closure(string): ?string $resolve */
function suppression(string $code, Closure $resolve): Suppression
{
	return Suppression::fromFile((new Parser)->parse($code), $resolve);
}


test('ignore on the same line and on its own line', function () use ($resolve) {
	$s = suppression(<<<'XX'
		<?php
		$a; // dresscode:ignore dresscode/a
		$b; // dresscode:ignore
		// dresscode:ignore dresscode/a, dresscode/b
		foo(
			1,
		);
		$c;
		XX, $resolve);
	Assert::true($s->isSuppressed('dresscode/a', 2));
	Assert::false($s->isSuppressed('dresscode/b', 2));
	Assert::true($s->isSuppressed('dresscode/anything', 3));
	Assert::true($s->isSuppressed('dresscode/a', 5));
	Assert::true($s->isSuppressed('dresscode/b', 7));
	Assert::false($s->isSuppressed('dresscode/c', 6));
	Assert::false($s->isSuppressed('dresscode/a', 8));
	Assert::false($s->isSuppressed('dresscode/a', 4));
});


test('disable and enable, also without a matching enable', function () use ($resolve) {
	$s = suppression(<<<'XX'
		<?php
		$a;
		// dresscode:disable dresscode/a
		$b;
		/* dresscode:enable dresscode/a */
		$c;
		# dresscode:disable
		$d;
		XX, $resolve);
	Assert::false($s->isSuppressed('dresscode/a', 2));
	Assert::true($s->isSuppressed('dresscode/a', 4));
	Assert::false($s->isSuppressed('dresscode/a', 6));
	Assert::true($s->isSuppressed('dresscode/a', 8));
	Assert::true($s->isSuppressed('dresscode/other', 8));
	Assert::false($s->isSuppressed('dresscode/b', 4));
});


test('ignore-file and phpcs forms with alias translation', function () use ($resolve) {
	$s = suppression("<?php\n// dresscode:ignore-file\n\$a;", $resolve);
	Assert::true($s->isSuppressed('dresscode/x', 3));

	$s = suppression(<<<'XX'
		<?php
		$a; // phpcs:ignore Generic.Files.LineLength
		// phpcs:disable Generic.Files.LineLength
		$b;
		// phpcs:enable
		/**
		 * @phpcsSuppress Generic.Files.LineLength
		 */
		function f() {
			$c;
		}
		$d;
		XX, $resolve);
	Assert::true($s->isSuppressed('dresscode/line-length', 2));
	Assert::true($s->isSuppressed('dresscode/line-length', 4));
	Assert::false($s->isSuppressed('dresscode/line-length', 5));
	Assert::true($s->isSuppressed('dresscode/line-length', 10));
	Assert::false($s->isSuppressed('dresscode/line-length', 12));
});
