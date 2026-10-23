<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Interop;

use DressCode\Config\ComposerJson;
use DressCode\ConfigurationException;
use function array_key_exists, is_string;


/**
 * What a `pint.json` of Laravel Pint means in DressCode: its preset is a standard, and its rules are those of
 * PHP CS Fixer.
 * @internal
 */
final class Pint
{
	/** the presets of Laravel Pint and the standard each is */
	private const Presets = [
		'laravel' => 'laravel',
		'psr12' => 'psr12',
		'per' => 'perCs',
		'symfony' => 'symfony',
		'empty' => null,
	];

	/** what a configuration of Laravel Pint says that `migrate config` carries over, the rest of it said in the warnings */
	private const Keys = ['preset', 'rules', 'exclude', 'cache-file'];


	/**
	 * The rules of a `pint.json`, its preset as the standard.
	 * @throws ConfigurationException  for a file that is no configuration, or names a preset Laravel Pint does not have
	 */
	public static function readConfig(string $file): ForeignRules
	{
		$config = ComposerJson::read($file) ?? throw new ConfigurationException("File `$file` is no configuration of Laravel Pint.");
		$preset = $config['preset'] ?? 'laravel';
		if (!is_string($preset) || !array_key_exists($preset, self::Presets)) {
			throw new ConfigurationException(
				"File `$file` names the preset `" . (is_string($preset) ? $preset : json_encode($preset)) . '`, which Laravel Pint does not have; it has '
				. implode(', ', array_map(fn(string $name) => "`$name`", array_keys(self::Presets))) . '.',
			);
		}

		$standard = self::Presets[$preset];
		return new ForeignRules(
			(array) ($config['rules'] ?? []),
			$standard === null ? [] : ["dresscode/$standard"],
			array_map(
				fn(string $key) => "`$key` of `"
					. basename($file)
					. '` is not carried over; what the configuration checks may differ from what Laravel Pint checked.',
				array_values(array_diff(array_keys($config), self::Keys)),
			),
		);
	}
}
