<?php declare(strict_types=1);

namespace DressCode;

use PhpSyntax\PhpVersion;


final readonly class PresetContext
{
	public function __construct(
		public PhpVersion $phpVersion,
	) {
	}


	public function getPhpVersion(): PhpVersion
	{
		return $this->phpVersion;
	}
}
