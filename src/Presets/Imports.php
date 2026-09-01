<?php declare(strict_types=1);

namespace DressCode\Presets;

use DressCode\Preset;
use DressCode\PresetInfo;
use DressCode\Profile;


/**
 * The hygiene of use statements and of the names that stand for them; their order and their shape are
 * decisions of the standard, so they are not here.
 */
#[PresetInfo('dresscode/imports', 'Imports that say what the file uses')]
final class Imports implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(rules: [
			'no-leading-backslash-in-global-namespace' => true,
			'no-leading-backslash-in-import' => true,
			'unused-imports' => true,
			'use-from-same-namespace' => true,
			'useless-alias' => true,
		]);
	}
}
