<?php declare(strict_types=1);

namespace DressCode\Console;

use DressCode\Config\ResolvedConfig;
use Nette\CommandLine\Console;
use Nette\Utils\Json;
use function array_slice, count, is_bool, is_string, sprintf, strlen;


/**
 * Writes a resolved configuration out: every rule that runs with the options a layer gave it and the layer
 * that gave it, every rule that does not with the reason, and the same as JSON for whoever reads by machine.
 * What it prints is the resolution the run itself uses, never a second reading of the file.
 * @internal
 */
final class ConfigPrinter
{
	public function __construct(
		private readonly ResolvedConfig $config,
		/** @var array<string, string>  rule name → why it does not run in this file, on top of the resolution */
		private readonly array $excluded = [],
	) {
	}


	public function print(Console $console): string
	{
		$out = $console->color('gray', 'Presets    ') . (implode(', ', $this->config->presets) ?: '(none)') . "\n";
		$out .= $console->color('gray', 'Style      ') . self::describeStyle($this->config) . "\n";

		$active = $inactive = [];
		foreach ($this->config->rules as $rule) {
			$reason = $this->excluded[$rule->name] ?? $rule->inactive;
			$reason === null ? $active[] = $rule : $inactive[$rule->name] = $reason;
		}

		$out .= $console->color('gray', 'Rules      ')
			. sprintf('%d of %d run', count($active), count($this->config->rules)) . "\n";

		$out .= "\n";
		foreach ($active as $rule) {
			$out .= '  ' . $console->color('white', self::pad($rule->name, 44))
				. $console->color('gray', (string) $rule->getSource()) . "\n";
			foreach ($rule->getOrigins() as $path => $layers) {
				[$source, $value] = $layers[count($layers) - 1];
				// a layer that said the same thing changed nothing and is not worth the reader's time
				$overridden = array_filter(array_slice($layers, 0, -1), fn(array $layer) => $layer[1] !== $value);
				$out .= '      ' . self::pad($path, 32) . self::pad(self::format($value), 24)
					. $console->color('gray', $source . ($overridden === []
						? ''
						: ' (over ' . implode(', ', array_map(
							fn(array $layer) => $layer[0] . ' ' . self::format($layer[1]),
							$overridden,
						)) . ')')) . "\n";
			}
		}

		if ($inactive) {
			$out .= "\n" . $console->color('white', "Not running\n");
			$unmentioned = 0;
			foreach ($inactive as $name => $reason) {
				if (str_starts_with($reason, 'no preset')) {
					$unmentioned++;
				} else {
					$out .= '  ' . str_pad($name, 40) . $console->color('gray', $reason) . "\n";
				}
			}

			if ($unmentioned) {
				$out .= $console->color('gray', "  $unmentioned more that no preset or rule of the configuration mentions\n");
			}
		}

		return $out;
	}


	public function printJson(): string
	{
		$rules = [];
		foreach ($this->config->rules as $rule) {
			$origins = [];
			foreach ($rule->getOrigins() as $path => $layers) {
				$origins[$path] = array_map(fn(array $layer) => ['source' => $layer[0], 'value' => $layer[1]], $layers);
			}

			$reason = $this->excluded[$rule->name] ?? $rule->inactive;
			$rules[$rule->name] = [
				'class' => $rule->class,
				'active' => $reason === null,
				'inactive' => $reason,
				'options' => $rule->options === [] ? new \stdClass : $rule->options,
				'origins' => $origins === [] ? new \stdClass : $origins,
			];
		}

		return Json::encode([
			'presets' => $this->config->presets,
			'php' => $this->config->phpVersion,
			'indent' => $this->config->indent,
			'eol' => $this->config->eol,
			'rules' => $rules,
		], pretty: true) . "\n";
	}


	private static function describeStyle(ResolvedConfig $config): string
	{
		$indent = $config->indent === "\t" ? 'a tab' : strlen($config->indent) . ' spaces';
		return $indent . ', ' . match ($config->eol) {
			"\n" => 'LF',
			"\r\n" => 'CRLF',
			default => 'the line ending each file mostly has',
		};
	}


	/** To the width of the column, and always with a space, so that a long value does not swallow the next one. */
	private static function pad(string $text, int $width): string
	{
		return $text . str_repeat(' ', max(1, $width - strlen($text)));
	}


	private static function format(mixed $value): string
	{
		return match (true) {
			is_bool($value) => $value ? 'true' : 'false',
			$value === null => 'null',
			is_string($value) => $value,
			default => (string) Json::encode($value),
		};
	}
}
