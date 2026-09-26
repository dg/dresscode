<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Config\ResolvedRule;
use DressCode\{ConfigurableRule, RuleInfo};
use Nette\CommandLine\{Ansi, Console};
use Nette\Schema\Elements\{AnyOf, Structure, Type};
use Nette\Schema\Processor;
use function count, is_bool, is_string, strval;


/**
 * Explains one rule: what it is for, the options it has under this configuration, and the examples someone
 * chose for it. The examples are fixtures of the rule, so the test suite runs them against the rule itself
 * and an example that stopped being true cannot survive.
 * @internal
 */
final class ExplainPrinter
{
	public function __construct(
		private readonly ResolvedRule $rule,
	) {
	}


	/**
	 * The examples of a rule: `examples/<slug>/*.code` in the package its class comes from, with the `.expected`
	 * beside it when it fixes, and the options its header sets, which is the configuration the example is true under.
	 * @return list<array{string, ?string, string}>
	 */
	public function findExamples(): array
	{
		$dir = self::findExamplesDir($this->rule->name, $this->rule->class);
		$examples = [];
		foreach ($dir === null ? [] : (glob("$dir/*.code") ?: []) as $file) {
			$code = (string) file_get_contents($file);
			$expected = (string) preg_replace('~\.code$~', '.expected', $file);
			$examples[] = [
				$code,
				is_file($expected) ? (string) file_get_contents($expected) : null,
				preg_match('~^//\s*(\{.*\})~m', $code, $m) ? $m[1] : '',
			];
		}

		return $examples;
	}


	/**
	 * The directory of the examples of a rule, `examples/<slug>` beside the composer.json of the package its class
	 * comes from, so that an extension ships the examples of its own rules; null when the class is in no package.
	 * @param  class-string  $class
	 */
	public static function findExamplesDir(string $name, string $class): ?string
	{
		$dir = dirname((string) new \ReflectionClass($class)->getFileName());
		while (!is_file("$dir/composer.json")) {
			if (dirname($dir) === $dir) {
				return null;
			}

			$dir = dirname($dir);
		}

		return "$dir/examples/" . substr($name, strpos($name, '/') + 1);
	}


	public function print(Console $console): string
	{
		$info = RuleInfo::of($this->rule->class);
		$out = $console->color('white', $this->rule->name) . "\n";
		$out .= ($info->description === '' ? '' : "$info->description.\n");
		$facts = ['stage ' . $info->stage->name];
		if ($info->getMinPhpVersion() !== null) {
			$facts[] = 'needs PHP ' . $info->getMinPhpVersion();
		}

		foreach ($info->getRequiredPackages() as $package => $version) {
			$facts[] = "needs $package" . ($version === null ? '' : " $version");
		}

		if ($info->modifiesComments) {
			$facts[] = 'modifies comments';
		}

		if ($info->risky) {
			$facts[] = 'every fix may change what the code does, so it is made once the rule is named in fixRisky or with --fix-risky';
		}

		$out .= $console->color('gray', implode(', ', $facts)) . "\n";
		$out .= "\n" . ($this->rule->isActive()
			? $console->color('gray', 'It runs in this project') . ($this->rule->getSource() === null ? '' : ', set by ' . $this->rule->getSource())
			: $console->color('gray', 'It does not run in this project: ') . $this->rule->inactive) . ".\n";

		$out .= $this->printOptions($console);
		$examples = $this->findExamples();
		foreach ($examples as $i => [$before, $after, $options]) {
			$out .= "\n" . $console->color('white', 'Example' . (count($examples) > 1 ? ' ' . ($i + 1) : ''))
				. ($options === '' ? '' : $console->color('gray', "  with $options")) . "\n";
			$out .= self::indent(self::strip($before));
			if ($after !== null && $after !== $before) {
				$out .= $console->color('gray', "  becomes\n") . self::indent(self::strip($after));
			}
		}

		return $out;
	}


	private function printOptions(Console $console): string
	{
		if (!is_subclass_of($this->rule->class, ConfigurableRule::class)) {
			return '';
		}

		$schema = $this->rule->class::getOptionsSchema();
		if (!$schema instanceof Structure) {
			return '';
		}

		$defaults = (array) (new Processor)->process($schema, []);
		$origins = $this->rule->getOrigins();
		$out = "\n" . $console->color('white', "Options\n");
		// the widest name decides the column, so a long one does not run into its value
		$width = max(26, ...array_map(Ansi::measure(...), array_map(strval(...), array_keys($schema->getShape()))));
		foreach ($schema->getShape() as $option => $element) {
			$value = $this->rule->options[$option] ?? $defaults[$option] ?? null;
			$layers = $origins[(string) $option] ?? [];
			$out .= '  ' . Ansi::pad((string) $option, $width + 1) . Ansi::pad(self::format($value), 24)
				. $console->color('gray', $layers === [] ? '(default)' : $layers[count($layers) - 1][0]) . "\n";
			$description = $element instanceof Type || $element instanceof AnyOf || $element instanceof Structure
				? $element->describe()['description'] ?? null
				: null;
			if (is_string($description) && $description !== '') {
				$out .= $console->color('gray', "      $description\n");
			}
		}

		return $out;
	}


	/** The header of a fixture is its options, which the example shows in the caption, not in the code. */
	private static function strip(string $code): string
	{
		return (string) preg_replace('~^(<\?php\r?\n)(?://[^\r\n]*\r?\n)+~', '$1', $code);
	}


	private static function indent(string $code): string
	{
		return '  ' . rtrim(str_replace("\n", "\n  ", rtrim($code, "\n"))) . "\n";
	}


	private static function format(mixed $value): string
	{
		return match (true) {
			is_bool($value) => $value ? 'true' : 'false',
			$value === null => 'null',
			is_string($value) => $value,
			default => (string) json_encode($value),
		};
	}
}
