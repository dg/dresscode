<?php declare(strict_types=1);

use DressCode\{Risk, Severity, Violation};
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('formatCode writes a code span of Markdown', function () {
	Assert::same('`$a`', Violation::formatCode('$a'));
	Assert::same('``$a = `ls`;``', Violation::formatCode('$a = `ls`;'));
	Assert::same('`` `ls` ``', Violation::formatCode('`ls`'));
	Assert::same("`''`", Violation::formatCode('')); // Markdown cannot mark empty code
});


test('only a risky violation is refused or says why', function () {
	Assert::exception(
		fn() => new Violation('acme/rule', 'Message', 1, 1, Severity::Error, 'f', refused: true),
		InvalidArgumentException::class,
		'Violation of `acme/rule`: `refused` and `because` belong to a risky violation, but no risk is given.',
	);
	Assert::exception(
		fn() => new Violation('acme/rule', 'Message', 1, 1, Severity::Error, 'f', because: 'it may'),
		InvalidArgumentException::class,
	);
	$violation = new Violation('acme/rule', 'Message', 1, 1, Severity::Warning, 'f', Risk::TypeUnknown, refused: true, because: 'it may');
	Assert::true($violation->refused);
});
