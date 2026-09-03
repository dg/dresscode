<?php declare(strict_types=1);

use DressCode\ConfigurationException;
use DressCode\Engine\Baseline;
use DressCode\Engine\FileResult;
use DressCode\Severity;
use DressCode\Violation;
use Tester\Assert;
use Tester\Helpers;

require __DIR__ . '/../../bootstrap.php';


function violation(string $rule, string $message, string $line, int $occurrence = 1): Violation
{
	$content = Violation::normalizeLineContent($line);
	return new Violation($rule, $message, 1, null, Severity::Error, fixable: false, followUp: false, fingerprint: Violation::createFingerprint($rule, $message, $content, $occurrence));
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
	Assert::same(3, Baseline::load($file)->count());
});


test('the PHP format says the same and reads back the same', function () use ($dir, $file) {
	$php = "$dir/baseline.php";
	Baseline::load($file)->save($php);
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
	Assert::same(3, Baseline::load($php)->count());
});


test('filters the known violations of a file and counts the matched and the unused entries', function () use ($file, $a, $b, $c) {
	$baseline = Baseline::load($file);
	$fresh = violation('test/c', 'New', '$z;');
	$result = $baseline->filter(new FileResult('src/a.php', '', '', [$a, $fresh]));
	Assert::same([$fresh], $result->violations);
	Assert::same(1, $baseline->countMatched());
	Assert::same(2, $baseline->countUnused());

	$other = new FileResult('src/c.php', '', '', [$b]); // the same fingerprint in another file is not known
	Assert::same($other, $baseline->filter($other));
	Assert::same(1, $baseline->countMatched());

	$untouched = new FileResult('src/b.php', '', '', []);
	Assert::same($untouched, $baseline->filter($untouched));
	Assert::same(2, $baseline->countUnused());
	Assert::same([], $baseline->filter(new FileResult('src/a.php', '', '', [$c]))->violations);
	Assert::same(2, $baseline->countMatched());
	Assert::same(1, $baseline->countUnused());
});


test('invalid files', function () use ($dir) {
	Assert::exception(fn() => Baseline::load("$dir/none.neon"), ConfigurationException::class, 'Cannot read the baseline file %a%');
	file_put_contents("$dir/broken.neon", "files:\n\t- a\n  - b\n");
	Assert::exception(fn() => Baseline::load("$dir/broken.neon"), ConfigurationException::class, 'The baseline file %a% is not valid NEON: %a%');
	file_put_contents("$dir/shape.neon", "files:\n\ta.php:\n\t\t- {rule: 1}\n");
	Assert::exception(fn() => Baseline::load("$dir/shape.neon"), ConfigurationException::class, 'The baseline file %a% has an unexpected shape.');
	file_put_contents("$dir/empty.neon", '');
	Assert::same(0, Baseline::load("$dir/empty.neon")->count());

	file_put_contents("$dir/wrong.json", '{}');
	$message = 'The baseline file %a%wrong.json must be a .neon or a .php file.';
	Assert::exception(fn() => Baseline::load("$dir/wrong.json"), ConfigurationException::class, $message);
	Assert::exception(fn() => (new Baseline)->save("$dir/wrong.json"), ConfigurationException::class, $message);
});
