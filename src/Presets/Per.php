<?php declare(strict_types=1);

namespace DressCode\Presets;

use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;
use DressCode\Rules;


/**
 * PER Coding Style 2.0, the default preset.
 */
#[PresetInfo('dresscode/per', 'PER Coding Style 2.0')]
final class Per implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [

			// 6. Operators
			Rules\Expressions\ConcatSpacingRule::class => true,
		];
	}


	public function getParents(): array
	{
		return [];
	}
}
