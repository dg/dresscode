<?php declare(strict_types=1);

use DressCode\ConfigurationException;
use DressCode\Engine\Baseline;
use DressCode\FileResult;
use DressCode\Severity;
use DressCode\Violation;
use Tester\Assert;
use Tester\Helpers;


require __DIR__ . '/../../bootstrap.php';


function violation(string $rule, string $message, string $line, int $occurrence = 1): Violation
{
	$content = Violation::normalizeLineContent($line);
	return new Violation($rule, $message, 1, null, Severity::Error, fixable: false, fingerprint: Violation::createFingerprint($rule, $message, $content, $occurrence));
}


$dir = __DIR__ . '/../../temp/baseline';
@mkdir($dir, recursive: true); // @ - may exist
Helpers::purge($dir);
$file = "$dir/baseline.neon";

$a = violation('test/a', 'Message A', '  $x = 1;  ');
$b = violation('test/b', 'Message B', '$y;');
$c = violation('test/a', 'Message A', '$x = 1;', occurrence: 2);


test('generated from results, saved and loaded back', function () use ($file, $a, $b, $c) {
	$baseline = Baseline::fromResults([
		new FileResult('src/b.php', '', '', [$b]),
		new FileResult('src/a.php', '', '', [$a, $c]),
	]);
	Assert::same(3, $baseline->count());
	$baseline->save($file);
	Assert::match(<<<'XX'
		files:
			src/a.php:
				-
					rule: test/a
					message: Message A
					fingerprint: %a%
		%A%
			src/b.php:
				-
					rule: test/b
					message: Message B
					fingerprint: %a%

		XX, (string) file_get_contents($file));
	Assert::same(3, Baseline::load($file)?->count());
});


test('a fingerprint of nothing but digits survives the round trip', function () use ($dir) {
	// PHP turns such a key into an int, NEON writes an unquoted number and the loader refuses the file
	$digits = new Violation('test/a', 'M', 1, null, Severity::Error, fixable: false, fingerprint: '9231335105126121');
	$file = "$dir/digits.neon";
	Baseline::fromResults([new FileResult('src/a.php', '', '', [$digits])])->save($file);
	Assert::contains("'9231335105126121'", (string) file_get_contents($file));
	$baseline = Baseline::load($file);
	assert($baseline !== null);
	Assert::true($baseline->knows('src/a.php', $digits->fingerprint));
	$baseline->markUsed('src/a.php', [$digits->fingerprint]);
	Assert::same([1, 0], [$baseline->countMatched(), $baseline->countUnused()]);
});


test('the PHP format says the same and reads back the same', function () use ($dir, $file) {
	$php = "$dir/baseline.php";
	Baseline::load($file)?->save($php);
	Assert::match(<<<'XX'
		<?php declare(strict_types=1);

		return [
			'files' => [
				'src/a.php' => [
					[
						'rule' => 'test/a',
						'message' => 'Message A',
						'fingerprint' => '%h%',
					],
		%A%
			],
		];

		XX, (string) file_get_contents($php));
	Assert::same(3, Baseline::load($php)?->count());
});


test('knows the violations of a file and counts the matched and the unused entries', function () use ($file, $a, $b, $c) {
	$baseline = Baseline::load($file);
	assert($baseline !== null);
	Assert::true($baseline->knows('src/a.php', $a->fingerprint));
	Assert::false($baseline->knows('src/a.php', violation('test/c', 'New', '$z;')->fingerprint));
	Assert::false($baseline->knows('src/c.php', $b->fingerprint)); // the same fingerprint in another file is not known

	$baseline->markUsed('src/a.php', [$a->fingerprint]);
	Assert::same(1, $baseline->countMatched());
	Assert::same(2, $baseline->countUnused());

	$baseline->markUsed('src/a.php', [$a->fingerprint]); // a repeated mark counts once
	$baseline->markUsed('src/c.php', [$b->fingerprint]); // an entry of another file is none
	Assert::same(1, $baseline->countMatched());
	Assert::same(2, $baseline->countUnused());

	$baseline->markUsed('src/a.php', [$c->fingerprint]);
	Assert::same(2, $baseline->countMatched());
	Assert::same(1, $baseline->countUnused());
});


test('invalid files', function () use ($dir) {
	Assert::null(Baseline::load("$dir/none.neon")); // before the first generation
	file_put_contents("$dir/broken.neon", "files:\n\t- a\n  - b\n");
	Assert::exception(fn() => Baseline::load("$dir/broken.neon"), ConfigurationException::class, 'The baseline file %a% is not valid NEON: %a%');
	file_put_contents("$dir/shape.neon", "files:\n\ta.php:\n\t\t- {rule: 1}\n");
	Assert::exception(fn() => Baseline::load("$dir/shape.neon"), ConfigurationException::class, 'The baseline file %a% has an unexpected shape.');
	file_put_contents("$dir/empty.neon", '');
	Assert::same(0, Baseline::load("$dir/empty.neon")?->count());

	// the name says the format, so it is judged whether the file exists or not
	file_put_contents("$dir/wrong.json", '{}');
	$message = 'The baseline file %a%wrong.json must be a .neon or a .php file.';
	Assert::exception(fn() => Baseline::load("$dir/wrong.json"), ConfigurationException::class, $message);
	Assert::exception(fn() => Baseline::load("$dir/missing.json"), ConfigurationException::class, 'The baseline file %a%missing.json must be a .neon or a .php file.');
	Assert::exception(fn() => (new Baseline)->save("$dir/wrong.json"), ConfigurationException::class, $message);
});
