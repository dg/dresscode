<?php declare(strict_types=1);

use DressCode\{ConfigurationException, FileResult, Risk, Severity, Violation};
use DressCode\Engine\{Baseline, Fingerprints};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


function violation(string $rule, string $message, string $line, int $occurrence = 1): Violation
{
	$content = Fingerprints::normalizeLineContent($line);
	return new Violation($rule, $message, 1, null, Severity::Error, fingerprint: Fingerprints::createFingerprint($rule, $content, $occurrence));
}


$dir = createTempDir('baseline');
$file = "$dir/baseline.neon";

$a = violation('test/a', 'Message A.', '  $x = 1;  ');
$b = violation('test/b', 'Message B.', '$y;');
$c = violation('test/a', 'Message A.', '$x = 1;', occurrence: 2);


test('generated from results, saved and loaded back', function () use ($file, $a, $b, $c) {
	$baseline = Baseline::fromResults([
		new FileResult('src/b.php', '', '', [$b]),
		new FileResult('src/a.php', '', '', [$a, $c]),
	]);
	Assert::same(3, $baseline->count());
	$baseline->save($file);
	Assert::match(<<<'XX'
		version: 2
		files:
			src/a.php:
				-
					decision: test/a
					message: Message A.
					fingerprint: %a%
		%A%
			src/b.php:
				-
					decision: test/b
					message: Message B.
					fingerprint: %a%

		XX, (string) file_get_contents($file));
	Assert::same(3, Baseline::load($file)?->count());
});


test('of the refused risky fixes it holds only those changing what the code does, the others being warnings', function () {
	$refused = fn(string $rule, Risk $risk) => new Violation($rule, 'M.', 1, null, Severity::Warning, fingerprint: $rule, risk: $risk, refused: true);
	$baseline = Baseline::fromResults([new FileResult('src/a.php', '', '', [
		$refused('test/type', Risk::TypeUnknown),
		$refused('test/name', Risk::NameUncertain),
		$refused('test/behavior', Risk::BehaviorChanges),
	])]);
	Assert::same(1, $baseline->count());
	Assert::true($baseline->has('src/a.php', 'test/behavior'));
});


test('a fingerprint of nothing but digits survives the round trip', function () use ($dir) {
	// PHP turns such a key into an int, NEON writes an unquoted number and the loader refuses the file
	$digits = new Violation('test/a', 'M.', 1, null, Severity::Error, fingerprint: '9231335105126121');
	$file = "$dir/digits.neon";
	Baseline::fromResults([new FileResult('src/a.php', '', '', [$digits])])->save($file);
	Assert::contains("'9231335105126121'", (string) file_get_contents($file));
	$baseline = Baseline::load($file);
	assert($baseline !== null);
	Assert::true($baseline->has('src/a.php', $digits->fingerprint));
	$baseline->markMatched('src/a.php', [$digits->fingerprint]);
	Assert::same([1, 0], [$baseline->countMatched(), $baseline->countUnmatched(['src/a.php' => null])]);
});


test('the PHP format says the same and reads back the same', function () use ($dir, $file) {
	$php = "$dir/baseline.php";
	Baseline::load($file)?->save($php);
	Assert::match(<<<'XX'
		<?php declare(strict_types=1);

		return [
			'version' => 2,
			'files' => [
				'src/a.php' => [
					[
						'decision' => 'test/a',
						'message' => 'Message A.',
						'fingerprint' => '%h%',
					],
		%A%
			],
		];

		XX, (string) file_get_contents($php));
	Assert::same(3, Baseline::load($php)?->count());
});


test('holds the violations of a file and counts the matched and the unmatched entries', function () use ($file, $a, $b, $c) {
	$baseline = Baseline::load($file);
	assert($baseline !== null);
	Assert::true($baseline->has('src/a.php', $a->fingerprint));
	Assert::false($baseline->has('src/a.php', violation('test/c', 'New.', '$z;')->fingerprint));
	Assert::false($baseline->has('src/c.php', $b->fingerprint)); // the same fingerprint in another file is not held

	$scope = ['src/a.php' => null, 'src/b.php' => null];
	$baseline->markMatched('src/a.php', [$a->fingerprint]);
	Assert::same(1, $baseline->countMatched());
	Assert::same(2, $baseline->countUnmatched($scope));

	$baseline->markMatched('src/a.php', [$a->fingerprint]); // a repeated mark counts once
	$baseline->markMatched('src/c.php', [$b->fingerprint]); // an entry of another file is none
	Assert::same(1, $baseline->countMatched());
	Assert::same(2, $baseline->countUnmatched($scope));

	$baseline->markMatched('src/a.php', [$c->fingerprint]);
	Assert::same(2, $baseline->countMatched());
	Assert::same(1, $baseline->countUnmatched($scope));
	Assert::same(0, $baseline->countUnmatched(['src/a.php' => null])); // a file the run did not process says nothing
	Assert::same(0, $baseline->countUnmatched(['src/b.php' => ['test/a']])); // nor does a rule that did not run
	Assert::same(1, $baseline->countUnmatched(['src/b.php' => ['test/b']]));
});


test('invalid files', function () use ($dir) {
	Assert::null(Baseline::load("$dir/none.neon")); // before the first generation
	file_put_contents("$dir/broken.neon", "files:\n\t- a\n  - b\n");
	Assert::exception(fn() => Baseline::load("$dir/broken.neon"), ConfigurationException::class, 'Baseline file `%a%` is not valid NEON: %a%');
	file_put_contents("$dir/shape.neon", "version: 2\nfiles:\n\ta.php:\n\t\t- {decision: 1}\n");
	Assert::exception(fn() => Baseline::load("$dir/shape.neon"), ConfigurationException::class, 'Baseline file `%a%` has an unexpected shape.');
	file_put_contents("$dir/empty.neon", '');
	Assert::same(0, Baseline::load("$dir/empty.neon")?->count());
	file_put_contents("$dir/old.neon", "files:\n\ta.php:\n\t\t- {decision: test/a, message: M, fingerprint: f}\n");
	Assert::exception(
		fn() => Baseline::load("$dir/old.neon"),
		ConfigurationException::class,
		'Baseline file `%a%old.neon` is not of the version this DressCode writes; generate it again with `dresscode baseline`.',
	);

	// the name says the format, so it is judged whether the file exists or not
	file_put_contents("$dir/wrong.json", '{}');
	$message = 'Baseline file `%a%wrong.json` must be a `.neon` or a `.php` file.';
	Assert::exception(fn() => Baseline::load("$dir/wrong.json"), ConfigurationException::class, $message);
	Assert::exception(fn() => Baseline::load("$dir/missing.json"), ConfigurationException::class, 'Baseline file `%a%missing.json` must be a `.neon` or a `.php` file.');
	Assert::exception(fn() => (new Baseline)->save("$dir/wrong.json"), ConfigurationException::class, $message);
});


test('a fingerprint is the rule, the line and the place of the violation among those of the rule there, not the message', function () {
	$lines = ['<?php', '$x = 1;'];
	$first = new Fingerprints($lines, 'a.php');
	$a = $first->create('test/a', 'Message A', 2);
	$b = $first->create('test/a', 'Message B', 2);
	Assert::notSame($a, $b);
	$first->beginPass();
	Assert::same($b, $first->create('test/a', 'Message B', 2)); // a pass repeating a report arrives at the same one

	$reworded = new Fingerprints($lines, 'a.php');
	Assert::same($a, $reworded->create('test/a', 'Message A, worded otherwise', 2));
	Assert::same($b, $reworded->create('test/a', 'Message B, worded otherwise', 2));

	$fixed = new Fingerprints($lines, 'a.php'); // the first one fixed, the second takes its place
	Assert::same($a, $fixed->create('test/a', 'Message B', 2));
});


test('violations of one rule with the same message on one line are told apart by their place', function () {
	$fingerprints = new Fingerprints(['<?php', '$x = 1;'], 'a.php');
	$first = $fingerprints->create('test/a', 'Message A', 2);
	$second = $fingerprints->create('test/a', 'Message A', 2);
	Assert::notSame($first, $second);
	Assert::same(Fingerprints::createFingerprint('test/a', '$x = 1;', 1), $first);
	Assert::same(Fingerprints::createFingerprint('test/a', '$x = 1;', 2), $second);
});


test('a place stays with its violation when a later pass reports them in the reverse order', function () {
	$fingerprints = new Fingerprints(['<?php', '$x = 1;'], 'a.php');
	$a = $fingerprints->create('test/a', 'Message A', 2);
	$b = $fingerprints->create('test/a', 'Message B', 2);
	Assert::notSame($a, $b);

	$fingerprints->beginPass();
	Assert::same($b, $fingerprints->create('test/a', 'Message B', 2));
	Assert::same($a, $fingerprints->create('test/a', 'Message A', 2));
});


test('a place stays with its message when a later pass reports the repeated ones in the reverse order', function () {
	$fingerprints = new Fingerprints(['<?php', '$x = 1;'], 'a.php');
	$a1 = $fingerprints->create('test/a', 'Message A', 2);
	$b = $fingerprints->create('test/a', 'Message B', 2);
	$a2 = $fingerprints->create('test/a', 'Message A', 2);
	Assert::count(3, array_unique([$a1, $b, $a2]));

	$fingerprints->beginPass();
	Assert::same($b, $fingerprints->create('test/a', 'Message B', 2));
	Assert::same($a1, $fingerprints->create('test/a', 'Message A', 2));
	Assert::same($a2, $fingerprints->create('test/a', 'Message A', 2));
});
