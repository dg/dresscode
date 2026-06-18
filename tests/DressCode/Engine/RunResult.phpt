<?php declare(strict_types=1);

use DressCode\Engine\{FileSummary, RunResult};
use DressCode\{FileResult, Risk, Severity, Violation};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


function makeViolation(
	string $rule,
	Severity $severity = Severity::Error,
	?Risk $risk = null,
	bool $refused = false,
	?string $derivedFrom = null,
): Violation
{
	return new Violation($rule, 'M', 1, null, $severity, fingerprint: $rule, risk: $risk, refused: $refused, derivedFrom: $derivedFrom);
}


// a.php: three violations, a fix leaves two of them (one a refused risky fix); b.php: one violation, a fix leaves none
$error = makeViolation('test/error');
$warning = makeViolation('test/warning', Severity::Warning);
$refusedType = makeViolation('test/type', Severity::Warning, Risk::TypeUnknown, refused: true);
$refusedName = makeViolation('test/name', Severity::Warning, Risk::NameUncertain, refused: true);
$derived = makeViolation('test/derived', derivedFrom: 'x');
$files = [
	FileSummary::of(new FileResult('a.php', "<?php\n", "<?php\n\n", [$error, $warning, $refusedType], remaining: [$warning, $refusedType, $refusedName, $derived])),
	FileSummary::of(new FileResult('b.php', "<?php\n", "<?php\n\n", [$error], remaining: [])),
];


test('a check reports the violations of the code, a fix leaves what the fixed text violates', function () use ($files) {
	$check = new RunResult($files, fix: false);
	Assert::same(4, $check->countViolations());
	Assert::same(4, $check->countReported());
	Assert::same(2, $check->countReported(Severity::Error));
	Assert::same(4, $check->countRemaining());
	Assert::same(1, $check->countRemaining(Severity::Error));

	$fix = new RunResult($files, fix: true);
	Assert::same(4, $fix->countViolations());
	Assert::same(4, $fix->countReported());
	Assert::same(1, $fix->countReported(Severity::Error));
	Assert::same($fix->countRemaining(), $fix->countReported());
});


test('what is reported differs from what a fix leaves when the check has violations the fix removes', function () {
	$files = [FileSummary::of(new FileResult('a.php', "<?php\n", "<?php\n\n", [makeViolation('test/a'), makeViolation('test/b')], remaining: [makeViolation('test/b')]))];

	$check = new RunResult($files, fix: false);
	Assert::same(2, $check->countReported());
	Assert::same(1, $check->countRemaining());
	Assert::same(1, $check->getExitCode());

	$fix = new RunResult($files, fix: true);
	Assert::same(1, $fix->countReported());
	Assert::same(1, $fix->countRemaining());

	$fixed = new RunResult([FileSummary::of(new FileResult('a.php', "<?php\n", "<?php\n\n", [makeViolation('test/a')]))], fix: true);
	Assert::same(1, $fixed->countViolations());
	Assert::same(0, $fixed->countReported());
	Assert::same(0, $fixed->countRemaining());
	Assert::same(0, $fixed->getExitCode());
});


test('refused fixes are those of the remaining violations marked so, in a check as in a fix', function () use ($files) {
	foreach ([false, true] as $fix) {
		Assert::same(2, new RunResult($files, $fix)->countRefused());
	}
});


test('a refused violation of the code that the fix removed is not a refused fix of the run', function () {
	$refused = makeViolation('test/type', Severity::Warning, Risk::TypeUnknown, refused: true);
	$run = new RunResult([FileSummary::of(new FileResult('a.php', "<?php\n", "<?php\n", [$refused], remaining: []))], fix: true);
	Assert::same(0, $run->countRefused());
});


test('the derived violations are counted among those the run reports', function () use ($files) {
	Assert::same(0, new RunResult($files, fix: false)->countDerived());
	Assert::same(1, new RunResult($files, fix: true)->countDerived());
	Assert::same(1, new RunResult($files, fix: true)->countDerived(Severity::Error));
	Assert::same(0, new RunResult($files, fix: true)->countDerived(Severity::Warning));
});
