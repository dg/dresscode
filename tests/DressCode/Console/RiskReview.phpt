<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Config\RunnerFactory;
use DressCode\Console\RiskReview;
use DressCode\Reporters\NullReporter;
use Nette\CommandLine\{ColorDepth, Console};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** What a person does in an editor while the review shows the first question. */
final class MeanwhileFilter extends php_user_filter
{
	/** @var ?Closure(): mixed */
	public static ?Closure $meanwhile = null;


	public function filter($in, $out, &$consumed, bool $closing): int
	{
		if (self::$meanwhile !== null) {
			(self::$meanwhile)();
			self::$meanwhile = null;
		}

		while ($bucket = stream_bucket_make_writeable($in)) {
			$consumed += $bucket->datalen;
			stream_bucket_append($out, $bucket);
		}

		return PSFS_PASS_ON;
	}
}


stream_filter_register('meanwhile', MeanwhileFilter::class);


/**
 * Fixes the files, then reviews what they leave with the answers given, one per line.
 * @param  array<string, string>  $files  path => content
 * @param  ?Closure(string): mixed  $meanwhile  done with the root once the review shows the first question
 * @return array{int, string, array<string, string>}  the fixes made, what the review said, the files after it
 */
function review(array $files, string $answers, ?Closure $meanwhile = null): array
{
	$root = createTempDir('review');
	foreach ($files as $path => $content) {
		@mkdir(dirname("$root/$path"), recursive: true); // @ directory may already exist
		file_put_contents("$root/$path", $content);
	}

	$factory = new RunnerFactory;
	$runner = $factory->createRunner($factory->resolve(new Config(paths: ['src'], decisions: ['correctness' => ['strictComparisonArgument' => 'required']]), $root), cache: false);
	$result = $runner->run($runner->findFiles(['src']), true, new NullReporter);
	$output = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	MeanwhileFilter::$meanwhile = $meanwhile === null ? null : fn() => $meanwhile($root);
	stream_filter_append($output, 'meanwhile', STREAM_FILTER_WRITE);
	$input = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	fwrite($input, $answers);
	rewind($input);
	$made = new RiskReview($runner, new Console($output, colorDepth: ColorDepth::None), $input, $root, $factory->registry)->review($result);
	rewind($output);
	return [
		$made,
		(string) stream_get_contents($output),
		array_map(fn(string $path) => (string) file_get_contents("$root/$path"), array_combine(array_keys($files), array_keys($files))),
	];
}


$two = "<?php\n\$x = in_array(\$a, \$b);\n\$y = in_array(\$c, \$d);\n";


test('every risky fix is shown with its diff and made only where the answer is yes', function () use ($two) {
	[$made, $said, $files] = review(['src/a.php' => $two], "y\nn\n");
	Assert::same(1, $made);
	Assert::same("<?php\n\$x = in_array(\$a, \$b, true);\n\$y = in_array(\$c, \$d);\n", $files['src/a.php']);
	Assert::match(<<<'XX'

		src/a.php:2  The `in_array()` call must pass `$strict = true`.  correctness.strictComparisonArgument
		Risky because a strict search no longer finds a value of another type.
		--- src/a.php
		+++ src/a.php
		@@ -1,3 +1,3 @@
		 <?php
		-$x = in_array($a, $b);
		+$x = in_array($a, $b, true);
		 $y = in_array($c, $d);
		Apply this risky fix? [y,n,a,q] %A%
		XX, $said);
	Assert::same(2, substr_count($said, 'Apply this risky fix?'));
});


test('an unknown answer is asked again, a makes every fix of the rule, and the next file too', function () use ($two) {
	[$made, $said, $files] = review(['src/a.php' => $two, 'src/b.php' => $two], "maybe\na\n");
	Assert::same(4, $made);
	Assert::same(2, substr_count($said, 'Apply this risky fix?'));
	Assert::same(str_replace(')', ', true)', $two), $files['src/a.php']);
	Assert::same(str_replace(')', ', true)', $two), $files['src/b.php']);
});


test('q or the end of the input leave the rest as it is', function () use ($two) {
	[$made, $said, $files] = review(['src/a.php' => $two, 'src/b.php' => $two], "q\n");
	Assert::same(0, $made);
	Assert::same(1, substr_count($said, 'Apply this risky fix?'));
	Assert::same(['src/a.php' => $two, 'src/b.php' => $two], $files);

	[$made, , $files] = review(['src/a.php' => $two], "y\n");
	Assert::same(1, $made);
	Assert::same("<?php\n\$x = in_array(\$a, \$b, true);\n\$y = in_array(\$c, \$d);\n", $files['src/a.php']);
});


test('a file saved meanwhile keeps the text saved, and its fixes are not made', function () use ($two) {
	$saved = "<?php\n\$saved = in_array(\$a, \$b);\n";
	[$made, $said, $files] = review(['src/a.php' => $two], "y\ny\n", fn(string $root) => file_put_contents("$root/src/a.php", $saved));
	Assert::same(0, $made);
	Assert::same($saved, $files['src/a.php']);
	Assert::contains("`src/a.php` changed while it was being reviewed, so it was not written; run `fix --ask-risky` again.\n", $said);
});
