<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\Rule;
use function count, is_array;


/**
 * One rule of a resolved configuration: its canonical name, the options it ends up with, the layers that
 * set them and, when it does not run, why. The layers are what they were given, in the order they were
 * given; the options are the result of processing them through the schema of the rule, defaults and all.
 */
final readonly class ResolvedRule
{
	public function __construct(
		public string $name,
		/** @var class-string<Rule> */
		public string $class,
		/** @var array<string, mixed>  validated, with the defaults of the schema filled in */
		public array $options,
		/** @var list<array{string, mixed}>  where a value came from and what it was, in the order given */
		public array $layers,
		/** why the rule does not run; null when it does */
		public ?string $inactive = null,
		/** @var ?\Closure(): Rule  a rule the configuration builds itself */
		public ?\Closure $factory = null,
	) {
	}


	public function isActive(): bool
	{
		return $this->inactive === null;
	}


	/** The name of the last layer that had a say, or null for a rule nobody mentioned. */
	public function getSource(): ?string
	{
		$last = $this->layers[count($this->layers) - 1] ?? null;
		return $last === null ? null : $last[0];
	}


	/**
	 * Who said what about every option a layer named, by the path of the option (`naming.classes`), in the
	 * order the layers were given; the last of them is the value the rule ends up with and the ones before
	 * it are what it overrode. An option no layer named is a default and is not here.
	 * @return array<string, list<array{string, mixed}>>
	 */
	public function getOrigins(): array
	{
		$origins = [];
		foreach ($this->layers as [$source, $value]) {
			if ($value === false) {
				$origins = []; // turning the rule off drops what was said before it
			} elseif (is_array($value)) {
				self::collectOrigins($value, $source, '', $origins);
			}
		}

		return $origins;
	}


	/**
	 * @param  array<string, mixed>  $value
	 * @param  array<string, list<array{string, mixed}>>  $origins
	 */
	private static function collectOrigins(array $value, string $source, string $prefix, array &$origins): void
	{
		foreach ($value as $key => $item) {
			$path = $prefix === '' ? (string) $key : "$prefix.$key";
			if (is_array($item) && !array_is_list($item)) {
				self::collectOrigins($item, $source, $path, $origins);
			} else {
				$origins[$path][] = [$source, $item];
			}
		}
	}
}
