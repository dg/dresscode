<?php declare(strict_types=1);

namespace DressCode\Presets;

use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;
use DressCode\Rules;


/**
 * PER Coding Style in its current version, the default preset: PSR-12 plus what the PER added (trailing
 * commas, short closures, empty bodies as `{}`, attributes, enumerations, heredoc, property hooks); the
 * specification defines the style and this preset adds nothing of its own.
 */
#[PresetInfo('dresscode/per', 'PER Coding Style 3.1', indent: 4, eol: 'majority')]
final class Per implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [
			// 2.6 Trailing commas
			Rules\Arrays\TrailingCommaRule::class => ['multiLine' => ['arrays', 'arguments', 'parameters', 'match', 'closureUses']],

			// 4. Classes, properties and methods: empty bodies, anonymous classes, named arguments
			Rules\Classes\ClassDefinitionSpacingRule::class => ['spaceBeforeParenthesis' => false],
			Rules\Expressions\NewArgumentParenthesesRule::class => ['namedClasses' => 'required', 'anonymousClasses' => 'forbidden'],
			Rules\Functions\NamedArgumentSpacingRule::class => true,

			// 5.6 The types of a multi-catch hug their bar, as every compound type does
			Rules\Types\TypeHintSpacingRule::class => true,

			// 6. Operators
			Rules\Expressions\ConcatSpacingRule::class => true,

			// 7.1 Short closures
			Rules\Whitespace\SemicolonSpacingRule::class => ['after' => null],

			// 10. Heredoc and nowdoc
			Rules\Literals\NowdocWithoutInterpolationRule::class => true,
			Rules\Literals\HeredocIndentationRule::class => true,

			// 12. Attributes
			Rules\Whitespace\AttributeSpacingRule::class => true,
			Rules\Expressions\UselessAttributeParenthesesRule::class => true,
			Rules\Whitespace\AttributePositionRule::class => true,
			Rules\PhpDoc\AttributeAfterPhpDocRule::class => true,
		];
	}


	public function getParents(): array
	{
		return [Psr12::class];
	}
}
