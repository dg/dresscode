<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Interop;

use DressCode\Config\PluginRegistry;
use DressCode\Engine\Helpers;
use function array_key_exists, count, is_array, is_bool, is_int, sprintf;


/**
 * The DressCode configuration a foreign one translates to, together with what could not be carried over. Two
 * foreign rules deciding one thing are merged in either order: a list becomes the union unless it is an order, or
 * what both allow where it names what is allowed, a map merges key by key, a boolean is true when either of them says
 * true, counts of blank lines widen to the range of both, and values that contradict each other leave the decision
 * out; a value only preferred, or `keep`, gives way to one set. How far a name is written out the foreign tools decide
 * on two axes, the form of a qualified name and whether a global one stands bare, which the translation puts together
 * into the decisions of `qualification`.
 * @internal
 */
final class Translation
{
	/** how firmly a foreign rule gives the value of a decision */
	private const Kept = 0;
	private const Preferred = 1;
	private const Set = 2;

	/** @var array<string, mixed>  path => the value of the decision */
	public private(set) array $decisions = [];

	/** @var array{shape: array<string, string>, fallback: array<string, string>, optimizedCalls: bool} */
	public private(set) array $qualification = ['shape' => [], 'fallback' => [], 'optimizedCalls' => false];

	/** @var list<string> */
	public private(set) array $presets = [];

	/** @var list<string> */
	public private(set) array $warnings = [];

	public ?int $lineLength = null;

	/** The indent of the configuration object of PHP CS Fixer, which its fixers of the indentation write. */
	public string $indent = '    ';

	/** The line ending of the configuration object of PHP CS Fixer, which its `line_ending` writes. */
	public string $lineEnding = "\n";

	/** @var array<string, int>  path => how firmly its value is given */
	private array $firmness = [];

	/** @var array<string, true>  paths the foreign rules give values that contradict each other */
	private array $contradicted = [];

	/** @var array<string, true>  paths whose lists are orders, never united */
	private array $ordered = [];

	/** @var array<string, true>  paths whose lists name what is allowed, narrowed to what every foreign rule allows */
	private array $allowances = [];


	public function addPreset(string $name): static
	{
		if (!in_array($name, $this->presets, true)) {
			$this->presets[] = $name;
		}

		return $this;
	}


	/** The widest line; of two foreign rules naming different ones the narrower is kept, and said so. */
	public function setLineLength(int $length): static
	{
		if ($this->lineLength !== null && $this->lineLength !== $length) {
			$this->warn(sprintf('The foreign rules name the line lengths `%d` and `%d`; DressCode has one and keeps the narrower.', min($this->lineLength, $length), max($this->lineLength, $length)));
		}

		$this->lineLength = min($this->lineLength ?? $length, $length);
		return $this;
	}


	/** The value of a decision, merged with what another foreign rule gave it. */
	public function set(string $path, mixed $value): static
	{
		$this->place($path, $value, self::Set);
		return $this;
	}


	/** The value of a decision unless another foreign rule sets it, which then wins in either order. */
	public function prefer(string $path, mixed $value): static
	{
		$this->place($path, $value, self::Preferred);
		return $this;
	}


	/**
	 * The values of several decisions.
	 * @param  array<string, mixed>  $values  path => value
	 */
	public function setAll(array $values): static
	{
		foreach ($values as $path => $value) {
			$this->set($path, $value);
		}

		return $this;
	}


	/**
	 * The blank lines of a place, a count or the range from `$min` to `$max`; a count another foreign rule gave the place
	 * widens it to the range of both, and the translation says so.
	 */
	public function setBlankLines(string $path, int $min, int $max): static
	{
		return $this->set($path, $min === $max ? $min : [$min, $max]);
	}


	/**
	 * An order whose sequence carries meaning; another foreign rule setting a different one contradicts it instead of
	 * uniting with it.
	 * @param  list<string>  $order
	 */
	public function setOrder(string $path, array $order): static
	{
		$this->ordered[$path] = true;
		return $this->set($path, $order);
	}


	/**
	 * A list of what is allowed; another foreign rule allowing a different one narrows it to what both allow instead of
	 * uniting with it.
	 * @param  list<string>  $allowed
	 */
	public function setAllowed(string $path, array $allowed): static
	{
		$this->allowances[$path] = true;
		return $this->set($path, $allowed);
	}


	/** The value of a decision as it is given, whatever the foreign rules gave it before. */
	public function replace(string $path, mixed $value): static
	{
		$this->decisions[$path] = $value;
		return $this;
	}


	/** The decision left out of the translation, whatever the foreign rules gave it. */
	public function remove(string $path): static
	{
		unset($this->decisions[$path]);
		return $this;
	}


	/**
	 * The decisions a foreign rule turned off stands for, or those a foreign rule governs while allowing either form, `keep`
	 * unless another foreign rule sets them, which then wins in either order.
	 */
	public function keep(string ...$paths): static
	{
		foreach ($paths as $path) {
			$this->place($path, 'keep', self::Kept);
		}

		return $this;
	}


	/** A value merges with one given as firmly, replaces one given less firmly and gives way to one given more. */
	private function place(string $path, mixed $value, int $firmness): void
	{
		$firmness = $value === 'keep' ? self::Kept : $firmness;
		$current = $this->firmness[$path] ?? -1;
		if ($firmness > $current) {
			$this->firmness[$path] = $firmness;
			unset($this->contradicted[$path]);
			$this->decisions[$path] = $value;

		} elseif ($firmness === $current && !isset($this->contradicted[$path])) {
			$merged = $this->merge($path, $this->decisions[$path] ?? null, $value);
			if (isset($this->contradicted[$path])) {
				unset($this->decisions[$path]);
			} else {
				$this->decisions[$path] = $merged;
			}
		}
	}


	/** The value two foreign rules give a decision together; values that contradict each other mark it and are said so. */
	private function merge(string $path, mixed $old, mixed $value): mixed
	{
		$isCount = fn(mixed $v) => is_int($v) || (is_array($v) && array_is_list($v) && count($v) === 2 && is_int($v[0]));
		if ($old === $value) {
			return $old;

		} elseif (str_starts_with($path, 'blankLines.') && $isCount($old) && $isCount($value)) {
			$this->warn("The foreign rules count the blank lines of `$path` differently; DressCode has one count there and takes the range of them all.");
			[$oldMin, $oldMax] = is_int($old) ? [$old, $old] : $old;
			[$min, $max] = is_int($value) ? [$value, $value] : $value;
			$range = [min($min, $oldMin), $max === null || $oldMax === null ? null : max($max, $oldMax)];
			return $range[0] === $range[1] ? $range[0] : $range;

		} elseif (is_array($old) && is_array($value) && array_is_list($old) && array_is_list($value) && isset($this->allowances[$path])) {
			return array_values(array_intersect($old, $value));

		} elseif (is_array($old) && is_array($value) && array_is_list($old) && array_is_list($value) && !isset($this->ordered[$path])) {
			return array_values(array_unique([...$old, ...$value], SORT_REGULAR));

		} elseif (is_array($old) && is_array($value)) {
			foreach ($value as $key => $item) {
				$old[$key] = array_key_exists($key, $old) ? $this->merge($path, $old[$key], $item) : $item;
			}

			return $old;

		} elseif (is_bool($old) && is_bool($value)) {
			return $old || $value;
		}

		$this->contradicted[$path] = true;
		$this->warn("The foreign rules give `$path` values that contradict each other; DressCode leaves it out, for the configuration to set by hand.");
		return $old;
	}


	/**
	 * The form a qualified name is written in, `imported`, `backslashed` or `keep`, by the kind of name: `class`,
	 * `function` and `constant` of every name, `globalClass`, `globalFunction` and `globalConstant` of a global one,
	 * `optimizedFunction` and `optimizedConstant` of one the compiler works with.
	 * @param  array<string, string>  $shapes
	 */
	public function setQualificationShape(array $shapes): static
	{
		$this->qualification['shape'] = $shapes + $this->qualification['shape'];
		return $this;
	}


	/**
	 * Whether a global function or constant stands `qualified`, `bare` or as written (`keep`): `function` and `constant`
	 * of every one, `optimizedFunction` of a call the compiler optimizes and `optimizedConstant` of a constant it
	 * computes with.
	 * @param  array<string, string>  $fallbacks
	 */
	public function setQualificationFallback(array $fallbacks): static
	{
		$this->qualification['fallback'] = $fallbacks + $this->qualification['fallback'];
		return $this;
	}


	/** A call the compiler optimizes is written in the form the optimization takes, its arguments included. */
	public function markCallsOptimized(): static
	{
		$this->qualification['optimizedCalls'] = true;
		return $this;
	}


	public function warn(string $message): static
	{
		if (!in_array($message, $this->warnings, true)) {
			$this->warnings[] = $message;
		}

		return $this;
	}


	/** The configuration as the source of a dresscode.php. */
	public function toPhp(): string
	{
		$arguments = '';
		if ($this->presets) {
			$arguments .= "\tuse: " . self::export(array_map(PluginRegistry::abbreviate(...), $this->presets)) . ",\n";
		}

		$decisions = $this->buildTree();
		if ($decisions) {
			$arguments .= "\tdecisions: " . self::export($decisions, 1) . ",\n";
		}

		return "<?php declare(strict_types=1);\n\nuse DressCode\\Config;\n\n"
			. ($arguments === '' ? "return new Config;\n" : "return new Config(\n$arguments);\n");
	}


	/**
	 * The paths of the decisions the translation sets, those of the qualification included.
	 * @return list<string>
	 */
	public function getPaths(): array
	{
		return array_keys($this->collectDecisions());
	}


	/**
	 * The tree of the decisions.
	 * @return array<string, mixed>
	 */
	private function buildTree(): array
	{
		$tree = [];
		foreach ($this->collectDecisions() as $path => $value) {
			$tree = Helpers::placeValue($tree, $path, $value);
		}

		return $tree;
	}


	/**
	 * The decisions, those the two axes of the qualification come to among them, what they leave as it is left to the presets.
	 * @return array<string, mixed>  path => value
	 */
	private function collectDecisions(): array
	{
		$decisions = $this->decisions;
		if ($this->qualification !== ['shape' => [], 'fallback' => [], 'optimizedCalls' => false]) {
			$defaults = self::translateQualification([], [], false);
			foreach (self::translateQualification(...array_values($this->qualification)) as $path => $value) {
				if ($defaults[$path] !== $value) {
					$decisions[$path] = $value;
				}
			}
		}

		if ($this->lineLength !== null) {
			$decisions['file.lineLength.max'] = $this->lineLength;
		}

		return $decisions;
	}


	/**
	 * The decisions of the global and the other names the two axes come to: the form of a qualified name, and whether
	 * a global function or constant stands qualified or bare, which the compiler may decide apart.
	 * @param  array<string, string>  $shape
	 * @param  array<string, string>  $fallback
	 * @return array<string, mixed>  path => value
	 */
	private static function translateQualification(array $shape, array $fallback, bool $optimizedCalls): array
	{
		$values = [
			'qualification.otherNamespace.class' => $shape['class'] ?? 'keep',
			'qualification.global.class' => $shape['globalClass'] ?? $shape['class'] ?? 'keep',
			'qualification.otherNamespace.function' => $shape['function'] ?? 'keep',
			'qualification.otherNamespace.constant' => $shape['constant'] ?? 'keep',
		];
		foreach (['Function', 'Constant'] as $kind) {
			$form = $shape['global' . $kind] ?? $shape[lcfirst($kind)] ?? null;
			$global = self::combine($fallback[lcfirst($kind)] ?? null, $form);
			$optimized = match ($fallback['optimized' . $kind] ?? null) {
				'bare' => 'bare',
				'qualified' => self::combine('qualified', $shape['optimized' . $kind] ?? $form),
				default => 'keep',
			};
			// the arguments of an optimized call written positionally are an intent of their own, the call then qualified
			if ($optimized === 'keep' && $global === 'keep' && $kind === 'Function' && $optimizedCalls) {
				$optimized = 'imported';
			}

			$values['qualification.global.' . lcfirst($kind)] = $global;
			$values['qualification.optimized.' . lcfirst($kind)] = $optimized === $global ? 'keep' : $optimized;
		}

		return $values;
	}


	/**
	 * The forms a global name stands in, from whether it stands qualified or bare and the form of a qualified one; a
	 * bare one takes the backslash away and leaves an import.
	 * @return string|list<string>
	 */
	private static function combine(?string $fallback, ?string $shape): string|array
	{
		$qualified = $shape === 'keep' ? null : $shape;
		return match ($fallback) {
			'bare' => $qualified === 'imported' ? ['bare', 'imported'] : 'bare',
			'qualified' => $qualified ?? ['imported', 'backslashed'],
			default => $qualified === null ? 'keep' : [$qualified, 'bare'],
		};
	}


	/** The value as PHP writes it: a list of plain values on one line, a map a key per line indented under the level. */
	private static function export(mixed $value, int $level = 0): string
	{
		if ($value === null) {
			return 'null';
		} elseif (!is_array($value)) {
			return var_export($value, return: true);
		} elseif (array_is_list($value) && !array_any($value, fn($item) => is_array($item))) {
			return '[' . implode(', ', array_map(fn($item) => self::export($item), $value)) . ']';
		}

		$indent = str_repeat("\t", $level + 1);
		$list = array_is_list($value);
		$items = '';
		foreach ($value as $key => $item) {
			$items .= $indent . ($list ? '' : var_export($key, return: true) . ' => ') . self::export($item, $level + 1) . ",\n";
		}

		return "[\n$items" . str_repeat("\t", $level) . ']';
	}
}
