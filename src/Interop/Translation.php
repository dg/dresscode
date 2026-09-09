<?php declare(strict_types=1);

namespace DressCode\Interop;

use function in_array, is_array, is_bool, sprintf;


/**
 * The DressCode configuration a foreign one translates to, together with what could not be carried over.
 * Two foreign rules covered by one DressCode rule are merged: a list option becomes the union, a boolean
 * is true when either of them says true, anything else is the value written last.
 * @internal
 */
final class Translation
{
	/** @var array<string, bool|array<string, mixed>>  rule name => options, or false for a rule turned off */
	public array $rules = [];

	/** @var list<string> */
	public array $presets = [];

	/** @var list<string> */
	public array $warnings = [];

	public ?int $lineLength = null;


	public function preset(string $name): static
	{
		if (!in_array($name, $this->presets, true)) {
			$this->presets[] = $name;
		}

		return $this;
	}


	/** The widest line; of two foreign rules naming different ones the narrower is kept, and said so. */
	public function lineLength(int $length): static
	{
		if ($this->lineLength !== null && $this->lineLength !== $length) {
			$this->warn(sprintf('The foreign rules name the line lengths %d and %d, DressCode has one and keeps the narrower', min($this->lineLength, $length), max($this->lineLength, $length)));
		}

		$this->lineLength = min($this->lineLength ?? $length, $length);
		return $this;
	}


	/** @param array<string, mixed> $options */
	public function enable(string $rule, array $options = []): static
	{
		$current = $this->rules[$rule] ?? [];
		$current = is_array($current) ? $current : [];
		foreach ($options as $key => $value) {
			$old = $current[$key] ?? null;
			$current[$key] = match (true) {
				is_array($old) && is_array($value) && array_is_list($old) && array_is_list($value) => array_values(array_unique([...$old, ...$value], SORT_REGULAR)),
				is_array($old) && is_array($value) => array_merge($old, $value),
				is_bool($old) && is_bool($value) => $old || $value,
				default => $value,
			};
		}

		$this->rules[$rule] = $current === [] ? true : $current;
		return $this;
	}


	/** Turns off a rule a preset would run, unless a foreign rule enabled it, which then wins in either order. */
	public function disable(string $rule): static
	{
		$this->rules[$rule] ??= false;
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
	public function toConfig(): string
	{
		$arguments = '';
		if ($this->presets) {
			$arguments .= "\tpresets: " . self::export($this->presets) . ",\n";
		}

		$rules = $this->rules;
		ksort($rules, SORT_STRING);
		if ($rules) {
			$arguments .= "\trules: [\n";
			foreach ($rules as $rule => $options) {
				$arguments .= "\t\t'$rule' => " . self::export($options) . ",\n";
			}

			$arguments .= "\t],\n";
		}

		if ($this->lineLength !== null) {
			$arguments .= "\tlineLength: $this->lineLength,\n";
		}

		return "<?php declare(strict_types=1);\n\nuse DressCode\\Config;\n\n"
			. ($arguments === '' ? "return new Config;\n" : "return new Config(\n$arguments);\n");
	}


	private static function export(mixed $value): string
	{
		if (!is_array($value)) {
			return var_export($value, return: true);
		}

		$items = [];
		$list = array_is_list($value);
		foreach ($value as $key => $item) {
			$items[] = ($list ? '' : "'$key' => ") . self::export($item);
		}

		return '[' . implode(', ', $items) . ']';
	}
}
