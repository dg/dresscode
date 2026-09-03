<?php declare(strict_types=1);

namespace Acme\DressCode\Presets;

use Acme\DressCode\Rules\NoVarDumpRule;
use DressCode\Preset;
use DressCode\PresetInfo;
use DressCode\Presets\Per;
use DressCode\Profile;


#[PresetInfo('acme/default', 'The Acme house style')]
final class Acme implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(presets: [Per::class], rules: [
			NoVarDumpRule::class => ['functions' => ['var_dump', 'print_r']],
			'dresscode/no-trailing-whitespace' => true,
			'dresscode/ordered-imports' => ['order' => 'alphabetical'],
		]);
	}
}
