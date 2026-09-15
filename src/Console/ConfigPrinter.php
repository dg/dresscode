<?php declare(strict_types=1);

namespace DressCode\Console;

use DressCode\Config\ResolvedConfig;
use DressCode\Config\ResolvedRule;
use DressCode\RuleInfo;
use Nette\CommandLine\Ansi;
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
	) {
	}


	public function print(Console $console): string
	{
		$out = $console->color('gray', 'Presets    ') . (implode(', ', $this->config->presets) ?: '(none)') . "\n";
		$out .= $console->color('gray', 'Style      ') . self::describeStyle($this->config) . "\n";
		$out .= $console->color('gray', 'Names      ') . self::describeNamespaces($this->config) . "\n";
		foreach ([[$this->config->namespacedFunctions, '()'], [$this->config->namespacedConstants, '']] as [$names, $suffix]) {
			foreach ($names as $name => $source) {
				$out .= '      ' . self::pad($name . $suffix, 56) . $console->color('gray', $source) . "\n";
			}
		}

		$active = $inactive = [];
		foreach ($this->config->rules as $rule) {
			$rule->isActive() ? $active[] = $rule : $inactive[] = $rule;
		}

		$out .= $console->color('gray', 'Rules      ')
			. sprintf('%d of %d run', count($active), count($this->config->rules)) . "\n";

		$out .= "\n";
		foreach ($active as $rule) {
			$out .= '  ' . $console->color('white', self::pad($rule->name, 44))
				. $console->color('gray', $rule->getSource() . self::describeRisk($rule)) . "\n";
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
			foreach ($inactive as $rule) {
				// a rule the configuration names in fixRisky is mentioned by it, so it is not counted among the others
				if (str_starts_with((string) $rule->inactive, 'no preset') && !$rule->fixRisky) {
					$unmentioned++;
				} else {
					$out .= '  ' . Ansi::pad($rule->name, 40)
						. $console->color('gray', $rule->inactive . ($rule->fixRisky ? ', risky fixes accepted' : '')) . "\n";
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

			$rules[$rule->name] = [
				'class' => $rule->class,
				'active' => $rule->isActive(),
				'inactive' => $rule->inactive,
				'fixRisky' => $rule->fixRisky,
				'warning' => $rule->warning,
				'options' => $rule->options === [] ? new \stdClass : $rule->options,
				'origins' => $origins === [] ? new \stdClass : $origins,
			];
		}

		return Json::encode([
			'presets' => $this->config->presets,
			'php' => $this->config->phpVersion,
			'indent' => $this->config->indent,
			'eol' => $this->config->eol,
			'lineLength' => $this->config->lineLength,
			'namespaces' => [
				'resolution' => $this->config->nameResolution,
				'functions' => $this->config->namespacedFunctions ?: new \stdClass,
				'constants' => $this->config->namespacedConstants ?: new \stdClass,
			],
			'rules' => $rules,
		], pretty: true) . "\n";
	}


	/**
	 * What becomes of the fixes that may change what the code does, those of a rule named in fixRisky are made, and of
	 * the violations of a rule named in warnings.
	 */
	private static function describeRisk(ResolvedRule $rule): string
	{
		return match (true) {
			$rule->fixRisky => ', risky fixes accepted',
			RuleInfo::of($rule->class)->risky => ', risky fixes only reported',
			default => '',
		} . ($rule->warning ? ', only warns' : '');
	}


	private static function describeStyle(ResolvedConfig $config): string
	{
		$indent = $config->indent === "\t" ? 'a tab' : strlen($config->indent) . ' spaces';
		return $indent . ', ' . match ($config->eol) {
			"\n" => 'LF',
			"\r\n" => 'CRLF',
			default => 'the line ending each file mostly has',
		} . ', ' . ($config->lineLength === null ? 'no line length' : "lines of up to $config->lineLength characters");
	}


	/** What an unqualified function or constant in a namespace is taken for, and what the namespaces are said to declare. */
	private static function describeNamespaces(ResolvedConfig $config): string
	{
		$count = count($config->namespacedFunctions) + count($config->namespacedConstants);
		return match (true) {
			$config->nameResolution === 'certain' && $count === 0 => 'certain, the namespaces declare no function and no constant',
			$config->nameResolution === 'certain' => "certain, the namespaces declare these $count and nothing else",
			default => 'uncertain, so an unqualified function or constant in a namespace is taken as global and a fix resting on it is risky'
				. ($count === 0 ? '' : "; $count declared"),
		};
	}


	/** To the width of the column, and always with a space, so that a long value does not swallow the next one. */
	/** Unlike Ansi::pad(), a column always ends with a space, so a name too long does not run into the next one. */
	private static function pad(string $text, int $width): string
	{
		return $text . str_repeat(' ', max(1, $width - Ansi::measure($text)));
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
