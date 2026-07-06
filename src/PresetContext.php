<?php declare(strict_types=1);

namespace DressCode;


final readonly class PresetContext
{
	public function __construct(
		public string $phpVersion,
	) {
	}


	public function getPhpVersion(): string
	{
		return $this->phpVersion;
	}
}
