<?php declare(strict_types=1);

namespace DressCode\Presets;

use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;


/**
 * What a member of a class states about itself, and the way it refers to the class it stands in; the
 * order of the members and the casing of their names are decisions of the standard.
 */
#[PresetInfo('dresscode/classes', 'Members that say what they are', fragment: true)]
final class Classes implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [
			'no-this-in-static-context' => true,
			'self-for-current-class' => true,
			'visibility-required' => true,
		];
	}


	public function getParents(): array
	{
		return [];
	}
}
