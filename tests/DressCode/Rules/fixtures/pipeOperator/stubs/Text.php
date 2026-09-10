<?php declare(strict_types=1);

class Text
{
	public static function lower(string $s): string
	{
		return strtolower($s);
	}


	public static function sortAll(array &$items): array
	{
		sort($items);
		return $items;
	}
}
