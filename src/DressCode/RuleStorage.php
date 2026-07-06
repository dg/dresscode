<?php declare(strict_types=1);

namespace DressCode;


/**
 * Key-value storage of a rule for the file being processed; rules are stateless, this is where per-file state goes.
 */
final class RuleStorage
{
	/** @var array<string, mixed> */
	private array $values = [];


	public function get(string $key, mixed $default = null): mixed
	{
		return $this->values[$key] ?? $default;
	}


	public function set(string $key, mixed $value): void
	{
		$this->values[$key] = $value;
	}


	public function has(string $key): bool
	{
		return array_key_exists($key, $this->values);
	}


	public function remove(string $key): void
	{
		unset($this->values[$key]);
	}
}
