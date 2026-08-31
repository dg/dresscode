<?php declare(strict_types=1);

namespace Acme\DressCode\Presets;

use Acme\DressCode\Rules\NoVarDumpRule;
use DressCode\Preset;
use DressCode\PresetInfo;
use DressCode\Presets\PerCs;
use DressCode\Profile;


#[PresetInfo('acme/default', 'The Acme house style')]
final class Acme implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(presets: [PerCs::class], rules: [
			NoVarDumpRule::class => ['functions' => ['var_dump', 'print_r']],
			'dresscode/noTrailingWhitespace' => true,
			'dresscode/orderedImports' => ['order' => 'byName'],
		]);
	}
}
