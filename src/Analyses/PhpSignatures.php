<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;


/**
 * The parameters of the functions PHP itself declares, gathered over every version from 8.0 on, the newest
 * spelling of a signature winning; a catalog apart from PhpSymbols, so that only a rule asking about parameters
 * loads it. The running interpreter is never asked.
 */
final class PhpSignatures
{
	use PhpSignaturesData;

	/** @var array<string, list<Parameter>> */
	private array $parsed = [];


	/**
	 * The parameters of the builtin function in their order; an empty list for a function without any, and null
	 * for a name PHP does not declare or whose declaration the catalog does not hold, which is the case of the
	 * extensions its source cannot see.
	 * @return ?list<Parameter>
	 */
	public function findParameters(string $function): ?array
	{
		$name = strtolower($function);
		$signature = self::Signatures[$name] ?? null;
		if ($signature === null) {
			return null;
		} elseif ($signature === '') {
			return [];
		}

		return $this->parsed[$name] ??= array_map(
			function (string $parameter): Parameter {
				$space = strpos(explode('=', $parameter, 2)[0], ' '); // a default may hold a space
				$type = $space === false ? null : substr($parameter, 0, $space);
				$written = $space === false ? $parameter : substr($parameter, $space + 1);
				$equals = strpos($written, '=');
				$name = ltrim($equals === false ? $written : substr($written, 0, $equals), '.');
				$default = $equals === false ? null : substr($written, $equals + 1);
				return new Parameter(
					ltrim($name, '&'),
					$type,
					optional: $default !== null,
					variadic: str_starts_with($written, '...'),
					byReference: str_starts_with($name, '&'),
					default: $default === '' ? null : $default,
				);
			},
			explode(',', $signature),
		);
	}
}
