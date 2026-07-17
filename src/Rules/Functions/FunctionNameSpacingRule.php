<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Claim;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Nodes\Expression\EmptyNode;
use PhpSyntax\Nodes\Expression\EvalNode;
use PhpSyntax\Nodes\Expression\ExitNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\Expression\IssetNode;
use PhpSyntax\Nodes\Expression\ListNode;
use PhpSyntax\Nodes\Expression\MethodCallNode;
use PhpSyntax\Nodes\Expression\NewNode;
use PhpSyntax\Nodes\Expression\StaticMethodCallNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Member\PropertyHookNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Nodes\Statement\HaltCompilerNode;
use PhpSyntax\Nodes\Statement\UnsetNode;


/**
 * No whitespace between the name of a function and its parentheses, in calls and declarations alike;
 * `isset`, `unset`, `empty`, `list`, `eval`, `exit` and `__halt_compiler` count as names too, and so does
 * the name of a property hook with parameters. What may stand without parentheses (`new Foo`, `exit`, a hook
 * without parameters) is left alone where they are missing: the gap after it is then somebody else's.
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
		$name = ['name' => [null, Claim::none()]];
		$hugs = fn(string $slot) => [$slot => [null, Claim::none()]];
		// what may stand without parentheses claims nothing where they are missing: `new Foo`, `exit`
		$optional = fn(string $slot) => [$slot => [null, fn(Gap $gap) => ($gap->token->getNext()?->is('(') ?? false) ? Claim::none() : null]];
		return [
			FunctionCallNode::class => $name,
			MethodCallNode::class => $name,
			StaticMethodCallNode::class => $name,
			FunctionNode::class => $name,
			MethodNode::class => $name,
			PropertyHookNode::class => $optional('name'),
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
