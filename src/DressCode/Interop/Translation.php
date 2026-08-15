<?php declare(strict_types=1);

namespace DressCode\Interop;

use function is_array, is_bool;


/**
 * The DressCode configuration a foreign one translates to, together with what could not be carried over.
 * Two foreign rules covered by one DressCode rule are merged: a list option becomes the union, a boolean
 * is true when either of them says true, anything else is the value written last.
 */
final class Translation
{
	/** @var array<string, true|array<string, mixed>>  rule name => options */
	public array $rules = [];

	/** @var list<string> */
	public array $presets = [];

	/** @var list<string> */
	public array $warnings = [];


	public function preset(string $name): static
	{
		if (!in_array($name, $this->presets, true)) {
			$this->presets[] = $name;
		}

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
				is_array($old) && is_array($value) => array_values(array_unique([...$old, ...$value], SORT_REGULAR)),
				is_bool($old) && is_bool($value) => $old || $value,
				default => $value,
			};
		}

		$this->rules[$rule] = $current === [] ? true : $current;
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
		$calls = [];
		foreach ($this->presets as $preset) {
			$calls[] = "->preset('$preset')";
		}

		$rules = $this->rules;
		ksort($rules, SORT_STRING);
		foreach ($rules as $rule => $options) {
			$calls[] = "->enable('$rule'" . ($options === true ? '' : ', ' . self::export($options)) . ')';
		}

		return "<?php declare(strict_types=1);\n\nuse DressCode\\Config;\n\nreturn Config::create()\n\t"
			. implode("\n\t", $calls) . ";\n";
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
