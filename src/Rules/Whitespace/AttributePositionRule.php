<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, Gap, GapRule, Line, RuleInfo, Stage};
use PhpSyntax\Nodes\{Member, Statement};


/**
 * The attributes of a declaration (a class, a function, a method, a property, a constant, a case of an enum)
 * stand on lines of their own right above it: every group on its own line, no blank line between the groups,
 * and the declaration on the line after the last one. Attributes on a parameter, a closure or an anonymous
 * class may share the line.
 */
#[RuleInfo(
	'dresscode/attribute-position',
	Stage::Formatting,
	description: 'Puts the attributes of a declaration on lines of their own above it',
)]
final class AttributePositionRule extends GapRule
{
	private const Declarations = [
		Statement\ClassNode::class, Statement\InterfaceNode::class, Statement\TraitNode::class, Statement\EnumNode::class,
		Statement\FunctionNode::class, Member\MethodNode::class, Member\PropertyNode::class, Member\ClassConstNode::class,
		Member\EnumCaseNode::class,
	];


	public function getClaims(): array
	{
		$own = new Claim(line: Line::Next, blank: 0);
		$claims = [
			'attributes:item' => [fn(Gap $gap) => $gap->index > 0 ? $own : null, null],
			'attributes' => [null, Claim::nextLine()],
		];
		return array_fill_keys(self::Declarations, $claims);
	}
}
