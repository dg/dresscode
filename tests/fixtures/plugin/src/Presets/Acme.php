<?php declare(strict_types=1);

namespace Acme\DressCode\Presets;

use Acme\DressCode\Rules\NoVarDumpRule;
use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;
use DressCode\Presets\Per;


#[PresetInfo('acme/default', 'The Acme house style')]
final class Acme implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [
			NoVarDumpRule::class => ['functions' => ['var_dump', 'print_r']],
			'dresscode/no-trailing-whitespace' => true,
			'dresscode/ordered-imports' => $context->getPhpVersion()->isAtLeast('8.0'),
		];
	}


	public function getParents(): array
	{
		return [Per::class];
	}
}
