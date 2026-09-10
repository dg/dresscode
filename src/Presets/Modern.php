<?php declare(strict_types=1);

namespace DressCode\Presets;

use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;


/**
 * The form a construct takes in the PHP the project targets, never a stronger promise about what the
 * code does: a rule that leaves out for the older versions says so with minPhpVersion, so this fragment
 * needs no version of its own.
 */
#[PresetInfo('dresscode/modern', 'The syntax the target version of PHP has', fragment: true)]
final class Modern implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [
			'arrow-function' => true,
			'combined-assignment-operator' => true,
			'combined-issets' => true,
			'combined-unsets' => true,
			'modern-class-name-reference' => true,
			'null-coalescing-operator' => true,
			'octal-notation' => true,
			'short-array-syntax' => true,
			'short-ternary-operator' => true,
		];
	}


	public function getParents(): array
	{
		return [];
	}
}
