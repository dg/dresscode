<?php

namespace Acme\Store;

class Record
{
	protected $table;

	protected $connection;

	protected string $key = 'id';


	public function save($options, string $mode)
	{
	}


	public function many(...$items)
	{
	}


	public function put(mixed $value, ?string $label = null)
	{
	}
}


trait HasName
{
	protected $name;
}
