<?php declare(strict_types=1);

namespace DressCode\Presets;

use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;


/**
 * The hygiene of use statements and of the names that stand for them; their order and their shape are
 * decisions of the standard, so they are not here.
 */
#[PresetInfo('dresscode/imports', 'Imports that say what the file uses', fragment: true)]
final class Imports implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [
			'no-leading-backslash-in-global-namespace' => true,
			'no-leading-backslash-in-import' => true,
			'reference-used-names-only' => true,
			'unused-imports' => true,
			'use-from-same-namespace' => true,
			'useless-alias' => true,
		];
	}


	public function getParents(): array
	{
		return [];
	}
}
