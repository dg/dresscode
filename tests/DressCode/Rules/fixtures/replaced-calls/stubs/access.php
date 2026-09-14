<?php

namespace Acme;

class Message
{
	public string $subject = '';

	public bool $sent = false;


	public function getSubject(): string
	{
		return $this->subject;
	}


	public function setSubject(string $subject): static
	{
		return $this;
	}


	public function isSent(): bool
	{
		return $this->sent;
	}
}


/** @implements \ArrayAccess<string, mixed> */
class Section implements \ArrayAccess
{
	public string $declared = '';


	public function __get(string $name): mixed
	{
		return null;
	}


	public function __set(string $name, mixed $value): void
	{
	}


	public function __isset(string $name): bool
	{
		return true;
	}


	public function __unset(string $name): void
	{
	}


	public function get(string $name): mixed
	{
		return null;
	}


	public function set(string $name, mixed $value): void
	{
	}


	public function has(string $name): bool
	{
		return true;
	}


	public function remove(string $name): void
	{
	}


	public function add(mixed $value): void
	{
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


/** @implements \ArrayAccess<string, mixed> */
class Registry implements \ArrayAccess
{
	public function store(string $key, mixed $value): void
	{
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
