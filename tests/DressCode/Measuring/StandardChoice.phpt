<?php declare(strict_types=1);

use DressCode\Measuring\{ProbeResult, ProbeRunner, ProjectFacts, StandardChoice};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$choose = fn(array $residues, ?string $hint = null) => new ReflectionMethod(StandardChoice::class, 'choose')->invoke(
	null, $residues, $hint,
);


test('the fewest files changed win, a close call going to what the formatter names, then to perCs', function () use ($choose) {
	Assert::same('symfony', $choose(['symfony' => 9, 'psr12' => 21, 'perCs' => 36]));
	Assert::same('nette', $choose(['symfony' => 20, 'nette' => 21, 'perCs' => 40], 'nette'));
	Assert::same('perCs', $choose(['symfony' => 20, 'perCs' => 21, 'nette' => 40]));
	Assert::same('nette', $choose(['nette' => 20, 'symfony' => 21, 'perCs' => 40]));
});


test('laravel is checked only for a project that is or requires a package of Laravel, or that Laravel Pint formats', function () {
	$root = createTempDir('laravel');
	file_put_contents("$root/composer.json", '{"name": "acme/eshop", "require": {"nette/application": "^3.2"}}');
	$standards = array_merge(...StandardChoice::listCandidates(ProjectFacts::detect($root), null));
	Assert::notContains('laravel', $standards);
	Assert::contains('nette', $standards);

	file_put_contents("$root/composer.json", '{"name": "acme/shop", "require": {"laravel/framework": "^12.0"}}');
	Assert::contains('laravel', array_merge(...StandardChoice::listCandidates(ProjectFacts::detect($root), null)));

	file_put_contents("$root/composer.json", '{"name": "acme/eshop"}');
	file_put_contents("$root/pint.json", '{"preset": "empty"}');
	Assert::contains('laravel', array_merge(...StandardChoice::listCandidates(ProjectFacts::detect($root), null)));
});


test('a standard a rule failed under is chosen only where every one failed, its residue measured short', function () {
	$root = createTempDir('choice-failed');
	file_put_contents("$root/composer.json", '{"name": "acme/eshop"}');
	$runner = new class implements ProbeRunner {
		public function run(array $probes): array
		{
			// perCs reports the fewest, as a rule failed in a file before it could report more
			return array_map(fn(string $key) => new ProbeResult(
				array_fill_keys($key === 'perCs' ? ['a'] : ['a', 'b', 'c'], ['src/a.php' => 1]),
				[],
				[],
				1,
				0,
				[],
				$key === 'perCs' ? ['src/b.php'] : [],
			), array_combine(array_keys($probes), array_keys($probes)));
		}
	};
	Assert::notSame(['perCs'], StandardChoice::measure(ProjectFacts::detect($root), null, $runner, [])->presets);
});
