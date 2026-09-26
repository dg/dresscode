<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token, TokenKind};
use PhpSyntax\Nodes\Member\MethodNode;


/**
 * `__set_state()` is called on the class, never on an object, so it is static; PHP 8.0 checks the signatures
 * of the magic methods and refuses one that is not. The modifier goes last and dresscode/visibility-required
 * puts the modifiers in their order.
 */
#[RuleInfo(
	'dresscode/static-set-state-required',
	Stage::Structure,
	description: 'Declares __set_state() static, which is how PHP calls it',
	group: Group::Correctness,
)]
final class StaticSetStateRequiredRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [MethodNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof MethodNode
			|| strcasecmp($node->name->text, '__set_state') !== 0
			|| $node->modifiers->isStatic()
			|| !$context->report($node->name, 'The __set_state() method must be static')
		) {
			return;
		}

		$node->modifiers->append(new Token(TokenKind::Static, 'static'));
	}
}
