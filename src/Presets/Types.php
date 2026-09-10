<?php declare(strict_types=1);

namespace DressCode\Presets;

use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;


/**
 * What the engine can be told about a type: a declaration where one is missing, a nullable one where
 * the default says so, and a call that does not fall back to a loose comparison.
 */
#[PresetInfo('dresscode/types', 'Types written where PHP can read them', fragment: true)]
final class Types implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [
			'nullable-type-for-default-null' => true,
			'strict-call' => true,
			'type-hint-required' => true,
		];
	}


	public function getParents(): array
	{
		return [];
	}
}
