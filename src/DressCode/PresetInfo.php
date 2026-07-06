<?php declare(strict_types=1);

namespace DressCode;


#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class PresetInfo
{
	public function __construct(
		public string $name,
		public string $description = '',
	) {
	}
}
