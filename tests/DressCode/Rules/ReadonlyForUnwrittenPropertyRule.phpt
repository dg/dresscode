<?php declare(strict_types=1);

/**
 * The code the rule fixed runs as the original did: a readonly property PHP refuses to write is a runtime error,
 * which neither the tree nor the expected text shows.
 */

use DressCode\Rules\Classes\ReadonlyForUnwrittenPropertyRule;
use DressCode\Testing\RuleTester;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** What the code prints when PHP runs it, with the exit code. */
function runCode(string $file): string
{
	exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);
	return implode("\n", $output) . "\nexit $exitCode";
}


$cases = [
	'twice' => [false, '
		final class A {
			private int $value;
			public function __construct() { $this->value = 1; $this->value = 2; }
			public function get(): int { return $this->value; }
		}
		echo (new A)->get();
	'],
	'promoted assigned' => [false, '
		final class A {
			public function __construct(private int $value = 1) { $this->value = 2; }
			public function get(): int { return $this->value; }
		}
		echo (new A)->get();
	'],
	'loop' => [false, '
		final class A {
			private int $value;
			public function __construct() { foreach ([1, 2] as $i) { $this->value = $i; } }
			public function get(): int { return $this->value; }
		}
		echo (new A)->get();
	'],
	'combined' => [false, '
		final class A {
			private string $value;
			public function __construct() { $this->value = "a"; $this->value .= "b"; }
			public function get(): string { return $this->value; }
		}
		echo (new A)->get();
	'],
	'element' => [false, '
		final class A {
			private array $value;
			public function __construct() { $this->value = []; $this->value[] = 1; }
			public function get(): int { return count($this->value); }
		}
		echo (new A)->get();
	'],
	'builtin function by reference' => [false, '
		final class A {
			private array $values;
			private array $pushed;
			private array $matches;
			public function __construct() { $this->values = [2, 1]; $this->pushed = []; $this->matches = []; }
			public function run(): string {
				sort($this->values);
				array_push($this->pushed, 1);
				preg_match("#a#", "a", $this->matches);
				return implode(",", $this->values) . count($this->pushed) . count($this->matches);
			}
		}
		echo (new A)->run();
	'],
	'own method by reference' => [false, '
		final class A {
			private array $values;
			public function __construct() { $this->values = []; }
			public function run(): int { $this->fill($this->values); return count($this->values); }
			private function fill(array &$items): void { $items[] = 1; }
		}
		echo (new A)->run();
	'],
	'foreach by reference' => [false, '
		final class A {
			private array $values;
			public function __construct() { $this->values = [1]; }
			public function run(): int { foreach ($this->values as &$v) { $v++; } return $this->values[0]; }
		}
		echo (new A)->run();
	'],
	'reference assignment' => [false, '
		final class A {
			public function __construct(private array $values = [1]) {}
			public function run(): int { $r = &$this->values; $r[] = 2; return count($this->values); }
		}
		echo (new A)->run();
	'],
	'dynamic name' => [false, '
		final class A {
			private int $value;
			public function __construct() { $this->value = 1; }
			public function set(string $name): int { $this->$name = 5; return $this->value; }
		}
		echo (new A)->set("value");
	'],
	'single assignment' => [true, '
		final class A {
			private array $values;
			public function __construct(bool $flag) { if ($flag) { $this->values = [2, 1]; } }
			public function run(): string { $copy = $this->values; sort($copy); return implode(",", $copy) . count($this->values); }
		}
		echo (new A(true))->run();
	'],
	'destructuring' => [true, '
		final class A {
			private int $first;
			private int $second;
			public function __construct(array $pair) { [$this->first, $this->second] = $pair; }
			public function sum(): int { return $this->first + $this->second; }
		}
		echo (new A([1, 2]))->sum();
	'],
];

$dir = createTempDir('readonly');
copy(__DIR__ . '/fixtures/readonlyForUnwrittenProperty/values.neon', "$dir/values.neon");
foreach ($cases as $name => [$fixed, $code]) {
	$original = "$dir/" . strtr($name, ' ', '-') . '.php';
	file_put_contents($original, "<?php\n$code\n");
	$output = RuleTester::collectOutput(ReadonlyForUnwrittenPropertyRule::class, $original);
	Assert::same($fixed, str_contains($output, 'readonly'), $name);

	file_put_contents($fixedFile = "$original.fixed.php", $output);
	Assert::same(runCode($original), runCode($fixedFile), $name);
}
