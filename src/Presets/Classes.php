<?php declare(strict_types=1);

namespace DressCode\Presets;

use DressCode\Preset;
use DressCode\PresetInfo;
use DressCode\Profile;


/**
 * What a member of a class states about itself, and the way it refers to the class it stands in; the
 * order of the members and the casing of their names are decisions of the standard.
 */
#[PresetInfo('dresscode/classes', 'Members that say what they are')]
final class Classes implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(rules: [
			'no-this-in-static-context' => true,
			'self-for-current-class' => true,
			'visibility-required' => true,
		]);
	}
}
