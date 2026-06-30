<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Claim, Gap, GapRule, Line, RuleInfo, Space, Stage};
use PhpSyntax\Nodes\AttributeNode;
use PhpSyntax\Nodes\Expression\{EmptyNode, EvalNode, ExitNode, FunctionCallNode, IssetNode, ListNode, MethodCallNode, NewNode, StaticMethodCallNode};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyHookNode};
use PhpSyntax\Nodes\Statement\{FunctionNode, HaltCompilerNode, UnsetNode};


/**
 * No whitespace between the name of a function and its parentheses, which stay on its line, in calls and
 * declarations alike; `isset`, `unset`, `empty`, `list`, `eval`, `exit` and `__halt_compiler` count as names
 * too, and so do the name of a property hook with parameters and that of an attribute with arguments. What may
 * stand without parentheses (`new Foo`, `exit`, a hook without parameters, `#[Foo]`) is left alone where they are
 * missing: the gap after it is then somebody else's.
 */
#[RuleInfo(
	'dresscode/function-name-spacing',
	Stage::Formatting,
	description: 'Removes whitespace between a function name and its parentheses',
)]
final class FunctionNameSpacingRule extends GapRule
{
	public function getClaims(): array
	{
		$hug = new Claim(Space::None, line: Line::Same);
		$name = ['name' => [null, $hug]];
		$hugs = fn(string $slot) => [$slot => [null, $hug]];
		$optional = fn(string $slot) => [$slot => [null, fn(Gap $gap) => ($gap->token->getNext()?->is('(') ?? false) ? $hug : null]];
		return [
			FunctionCallNode::class => $name,
			MethodCallNode::class => $name + $hugs('closeBrace'),
			StaticMethodCallNode::class => $name + $hugs('closeBrace'),
			FunctionNode::class => $name,
			MethodNode::class => $name,
			PropertyHookNode::class => $optional('name'),
			AttributeNode::class => $optional('name'),
			NewNode::class => $optional('class'),
			ExitNode::class => $optional('exitKeyword'),
			IssetNode::class => $hugs('issetKeyword'),
			UnsetNode::class => $hugs('unsetKeyword'),
			EmptyNode::class => $hugs('emptyKeyword'),
			ListNode::class => $hugs('listKeyword'),
			EvalNode::class => $hugs('evalKeyword'),
			HaltCompilerNode::class => $hugs('haltKeyword'),
		];
	}
}
