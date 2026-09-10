<?php declare(strict_types=1);

namespace DressCode\Presets;

use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;


/**
 * What the code would not miss: an empty construct, a useless one, a repetition of what already
 * happened. A rule about a doc comment, an import or a member of a class belongs to the fragment of that
 * thing instead, so that the six of them stay apart.
 */
#[PresetInfo('dresscode/cleanup', 'Code that is there for nothing', fragment: true)]
final class Cleanup implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [
			'commented-out-function' => true,
			'no-duplicate-assignment' => true,
			'no-empty-comment' => true,
			'no-empty-statement' => true,
			'no-unreachable-catch' => true,
			'useless-attribute-parentheses' => true,
			'useless-braces' => true,
			'useless-catch-variable' => true,
			'useless-construct-parentheses' => true,
			'useless-else' => true,
			'useless-if-condition-with-return' => true,
			'useless-modifier' => true,
			'useless-null-property-initialization' => true,
			'useless-parameter-default' => true,
			'useless-parentheses-around-new' => true,
			'useless-return' => true,
			'useless-string-concat' => true,
			'useless-ternary-operator' => true,
		];
	}


	public function getParents(): array
	{
		return [];
	}
}
