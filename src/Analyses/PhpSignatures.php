<?php declare(strict_types=1);

namespace DressCode\Analyses;


/**
 * The parameters of the functions PHP itself declares, gathered over every version from 8.0 on, the newest
 * spelling of a signature winning. It is a catalog of its own and not a part of PhpSymbols, because it is two
 * thirds the size of that one and the command line compiles what it loads on every run; only a rule that asks
 * about parameters pays for it. The running interpreter is never asked, so the answer does not depend on the
 * PHP of the machine that runs the check.
 */
final class PhpSignatures
{
	use PhpSignaturesData;

	/** @var array<string, list<Parameter>> */
	private array $parsed = [];


	/**
	 * The parameters of the internal function in their order; an empty list for a function without any, and null
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
				[$type, $name] = explode(' ', $parameter);
				$variadic = str_starts_with($name, '...');
				$optional = str_ends_with($name, '=');
				$name = ltrim($name, '.');
				return new Parameter(trim($name, '&='), $type, $variadic, str_starts_with($name, '&'), $optional);
			},
			explode(',', $signature),
		);
	}
}
