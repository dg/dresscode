<?php declare(strict_types=1);

namespace DressCode\Config;


/**
 * Where the PHP version the rules target was taken from.
 * @internal
 */
enum PhpVersionSource
{
	case Configuration;
	case Composer;
	case Default;
}
