<?php

namespace App;

interface HasLimit
{
	const LIMIT = 10;
}

interface HasName
{
	public string $name { get; }
}

abstract class Shape
{
	abstract public int $size { get; }
}

abstract class Model
{
	public const TABLE = 'models';
	protected const KEY = 'id';
	private const SECRET = 's';
	public const A = 1;
	public const B = 2;
	public const MARKED = 3;

	public string $title = '';
	protected static int $count = 0;
	private int $hidden = 0;
	public int $id = 0;
	public int $x = 0;
	public int $y = 0;
}
