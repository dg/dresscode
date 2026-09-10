<?php declare(strict_types=1);

namespace DressCode\Presets;

use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;


/**
 * The tidy-up of doc comments: an empty block, an annotation of something that is not there, a tag the
 * declaration says better. What a doc comment must contain is a policy of the project, not this fragment.
 */
#[PresetInfo('dresscode/phpdoc', 'Doc comments without what the types already say', fragment: true)]
final class PhpDoc implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [
			'annotation-name' => true,
			'no-duplicate-return-annotation' => true,
			'no-empty-phpdoc' => true,
			'no-empty-var-annotation' => true,
			'no-unknown-param-annotation' => true,
			'phpdoc-null-last' => true,
			'phpdoc-trim' => true,
			'property-var-annotation' => true,
			'useless-constant-var-annotation' => true,
			'useless-function-phpdoc' => true,
			'useless-inheritdoc' => true,
		];
	}


	public function getParents(): array
	{
		return [];
	}
}
