<?php declare(strict_types=1);

namespace DressCode;

use Nette\Schema\Schema;


interface ConfigurableRule
{
	public static function getOptionsSchema(): Schema;

	/** @param array<string, mixed> $options  validated against the schema */
	public function configure(array $options): void;
}
