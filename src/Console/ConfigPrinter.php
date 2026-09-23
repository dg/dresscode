<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Config\{PackageProfile, ResolvedConfig, ResolvedRule, RuleRegistry};
use Nette\CommandLine\{Ansi, Console};
use Nette\Utils\Json;
use function array_slice, count, is_bool, is_string, sprintf, strlen;


/**
 * Writes a resolved configuration out: every rule that runs with the options a layer gave it and the layer
 * that gave it, every rule that does not with the reason, those no layer mentions as a count, and the same as
 * JSON for whoever reads by machine.
 * @internal
 */
final class ConfigPrinter
{
	public function __construct(
		private readonly ResolvedConfig $config,
		/** @var list<array{PackageProfile, ?string}>  the upgrading files of the packages, each with the version of its package the code must work with */
		private readonly array $packages = [],
	) {
	}


	public function print(Console $console): string
	{
		$out = $console->color('gray', 'Plugins    ') . (implode(', ', $this->config->plugins) ?: '(none)') . "\n";
		$out .= $console->color('gray', 'Presets    ') . (implode(', ', $this->config->presets) ?: '(none)') . "\n";
		$out .= $console->color('gray', 'Groups     ') . (implode(', ', $this->config->groups) ?: '(none)') . "\n";
		$out .= $console->color('gray', 'Targets    ') . self::describeTargets($this->config) . "\n";
		$out .= $console->color('gray', 'Style      ') . self::describeStyle($this->config) . "\n";
		$out .= $console->color('gray', 'Names      ') . self::describeNamespaces($this->config) . "\n";
		foreach ([[$this->config->namespacedFunctions, '()'], [$this->config->namespacedConstants, '']] as [$names, $suffix]) {
			foreach ($names as $name => $source) {
				$out .= '      ' . self::pad($name . $suffix, 56) . $console->color('gray', $source) . "\n";
			}
		}

		if ($this->packages !== []) {
			$out .= $console->color('gray', 'Packages   ') . count($this->packages) . ' upgrading ' . (count($this->packages) === 1 ? 'file' : 'files') . "\n";
			// what the version of the package has not reached yet is what an upgrade still offers
			foreach ($this->packages as [$profile, $version]) {
				$out .= '      ' . self::pad($profile->package . ($version === null ? '' : " $version"), 32)
					. $console->color('gray', $profile->source . ($profile->unreached === [] ? '' : ', upgrading further to ' . implode(', ', $profile->unreached))) . "\n";
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
				if ($rule->inactiveReason === 'notMentioned' && !$rule->fixRisky) {
					$unmentioned++;
				} else {
					$out .= '  ' . self::pad($rule->name, 40)
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

			$rules[RuleRegistry::abbreviate($rule->name)] = [
				'class' => $rule->class,
				'active' => $rule->isActive(),
				'inactive' => $rule->inactive === null ? null : ['reason' => $rule->inactiveReason, 'message' => $rule->inactive],
				'fixRisky' => $rule->fixRisky,
				'warnOnly' => $rule->warnOnly,
				'options' => $rule->options === [] ? new \stdClass : $rule->options,
				'origins' => $origins === [] ? new \stdClass : $origins,
			];
		}

		// the keys and the values as a configuration writes them
		return Json::encode([
			'version' => 1,
			'presets' => array_map(RuleRegistry::abbreviate(...), $this->config->presets),
			'groups' => $this->config->groups,
			'plugins' => $this->config->plugins,
			'targets' => ['php' => $this->config->phpVersion, ...$this->config->packageTargets],
			'types' => $this->config->types,
			'indent' => $this->config->indent === "\t" ? 'tab' : strlen($this->config->indent),
			'lineEnding' => match ($this->config->lineEnding) {
				"\n" => 'LF',
				"\r\n" => 'CRLF',
				default => 'majority',
			},
			'lineLength' => $this->config->lineLength,
			'nameResolution' => $this->config->nameResolution,
			'namespaces' => [
				'functions' => $this->config->namespacedFunctions ?: new \stdClass,
				'constants' => $this->config->namespacedConstants ?: new \stdClass,
			],
			'packageProfiles' => array_map(fn(array $package) => [
				'source' => $package[0]->source,
				'package' => $package[0]->package,
				'version' => $package[1],
				'unreached' => $package[0]->unreached,
			], $this->packages),
			'rules' => $rules,
		], pretty: true) . "\n";
	}


	/**
	 * Whether the project accepts the risky fixes of the rule, and whether its violations only warn.
	 */
	private static function describeRisk(ResolvedRule $rule): string
	{
		return ($rule->fixRisky ? ', risky fixes accepted' : '') . ($rule->warnOnly ? ', only warns' : '');
	}


	/** The version of PHP and of the packages the code is written for. */
	private static function describeTargets(ResolvedConfig $config): string
	{
		$targets = ['php' => $config->phpVersion, ...$config->packageTargets];
		return implode(', ', array_map(fn(string $package, string $version) => "$package $version", array_keys($targets), $targets));
	}


	private static function describeStyle(ResolvedConfig $config): string
	{
		$indent = $config->indent === "\t" ? 'a tab' : strlen($config->indent) . ' spaces';
		return $indent . ', ' . match ($config->lineEnding) {
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


	/** Unlike `Ansi::pad()`, a column always ends with a space, so a name too long does not run into the next one. */
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
