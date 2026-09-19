<?php

namespace Acme;

class Component
{
}


class Url
{
	public function getHost(): string
	{
		return '';
	}


	public static function text(string $url): static
	{
		return new static;
	}
}


/** @implements \ArrayAccess<string, mixed> */
class Registry implements \ArrayAccess
{
	public function __get(string $name): mixed
	{
		return null;
	}


	public function offsetGet(mixed $offset): mixed
	{
		return null;
	}


	public function offsetSet(mixed $offset, mixed $value): void
	{
	}


	public function offsetExists(mixed $offset): bool
	{
		return true;
	}


	public function offsetUnset(mixed $offset): void
	{
	}
}


class Passwords
{
	public function __construct(string $algorithm = 'default', array $options = [])
	{
	}


	public function hash(string $password): string
	{
		return '';
	}
}
