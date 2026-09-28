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
 * Explains one rule: what it is for and the options it has under this configuration.
 * @internal
 */
final class ExplainPrinter
{
	public function __construct(
		private readonly ResolvedRule $rule,
	) {
	}


	public function print(Console $console): string
	{
		$info = RuleInfo::of($this->rule->class);
		$out = $console->color('white', $this->rule->name) . "\n";
		$out .= ($info->description === '' ? '' : Markup::highlightCode($console, "$info->description.") . "\n");
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

		$out .= $console->color('gray', implode(', ', $facts)) . "\n";
		$out .= "\n" . ($this->rule->isActive()
			? $console->color('gray', 'It runs in this project') . ($this->rule->getSource() === null ? '' : ', set by ' . $this->rule->getSource())
			: $console->color('gray', 'It does not run in this project: ') . $this->rule->inactive) . ".\n";

		return $out . $this->printOptions($console);
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
				$out .= '      ' . Markup::highlightCode($console, $description, 'gray') . "\n";
			}
		}

		return $out;
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
