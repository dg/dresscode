<?php declare(strict_types=1);

namespace DressCode\Presets;

use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;


/**
 * PER Coding Style 2.0, the default preset.
 */
#[PresetInfo('dresscode/per', 'PER Coding Style 2.0')]
final class Per implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [
		];
	}


	public function getParents(): array
	{
		return [];
	}
}
