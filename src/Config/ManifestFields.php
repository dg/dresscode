<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Analyses, Plugin};
use DressCode\Engine\Helpers;
use PhpSyntax\Nodes\FileNode;
use function is_int, is_string;


/**
 * The fields a configuration and the manifest of a plugin share, checked by the same code.
 * @internal
 */
final class ManifestFields
{
	/**
	 * @param  array<mixed>  $plugins
	 * @param  string  $advice  what to write instead of what is not a plugin
	 * @throws \InvalidArgumentException
	 */
	public static function checkPlugins(array $plugins, string $advice): void
	{
		foreach ($plugins as $plugin) {
			if (!$plugin instanceof Plugin && !(is_string($plugin) && is_subclass_of($plugin, Plugin::class))) {
				throw new \InvalidArgumentException('Plugin `' . (is_string($plugin) ? $plugin : get_debug_type($plugin)) . "` is not a plugin; $advice.");
			}
		}
	}


	/**
	 * @param  list<string>  $patterns
	 * @throws \InvalidArgumentException
	 */
	public static function checkGlobs(array $patterns, string $key): void
	{
		foreach ($patterns as $pattern) {
			if (!Helpers::isGlobValid($pattern)) {
				throw new \InvalidArgumentException("Pattern `$pattern` of `$key` is not a valid glob: a character class `[...]` is not closed or its range runs backwards.");
			}
		}
	}


	/** @throws \InvalidArgumentException */
	public static function checkRuleUrl(?string $ruleUrl): void
	{
		if ($ruleUrl !== null && !preg_match('~^[a-z][a-z0-9+.-]*://[^\x00-\x20\x7F]+$~Di', $ruleUrl)) {
			throw new \InvalidArgumentException("Invalid `ruleUrl` `$ruleUrl`, an address such as `https://acme.dev/rules/{slug}` is expected.");
		}
	}


	/**
	 * @param  array<string|int, string|callable(FileNode, string): object>  $analyses
	 * @return array<class-string, ?\Closure(FileNode, string): object>
	 * @throws \InvalidArgumentException
	 */
	public static function normalizeAnalyses(array $analyses): array
	{
		$normalized = [];
		foreach ($analyses as $key => $value) {
			[$class, $factory] = is_int($key) ? [$value, null] : [$key, $value];
			if (!is_string($class) || !class_exists($class)) {
				throw new \InvalidArgumentException('Analysis class `' . (is_string($class) ? $class : get_debug_type($class)) . '` does not exist.');
			} elseif ($factory === null && !Analyses\Registry::isConstructible($class)) {
				throw new \InvalidArgumentException("Analysis `$class` must take the `FileNode` or nothing in its constructor, or come with a factory.");
			} elseif ($factory !== null && !is_callable($factory)) {
				throw new \InvalidArgumentException("The factory of analysis `$class` must be callable, `" . get_debug_type($factory) . '` given.');
			}

			$normalized[$class] = $factory === null ? null : $factory(...);
		}

		return $normalized;
	}
}
