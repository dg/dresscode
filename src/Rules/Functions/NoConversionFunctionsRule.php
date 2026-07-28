<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use DressCode\Rules\GlobalCalls;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArgumentNode, Expression};
use function count;


/**
 * A cast instead of the conversion functions: `(int) $x`, not `intval($x)`; a call with a base or
 * other extra arguments stays.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class NoConversionFunctionsRule extends NodeRule
{
	private const Casts = [
		'intval' => 'int',
		'floatval' => 'float',
		'doubleval' => 'float',
		'strval' => 'string',
		'boolval' => 'bool',
	];


	public static function getDecisions(): array
	{
		return [new Decision('cleanup.conversionFunctions', Domain::state('forbidden'), '`intval($x)` is `(int) $x`; `intval($x, 16)` is no cast')];
	}


	public function getVisitedNodes(): array
	{
		return [Expression\FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Expression\FunctionCallNode) {
			return;
		}

		$function = GlobalCalls::findFunction($node, self::Casts, $context);
		$cast = $function === null ? null : self::Casts[$function];

		$args = $node->arguments->items->getItems();
		$arg = $args[0] ?? null;
		if (
			$cast === null
			|| count($args) !== 1
			|| !$arg instanceof ArgumentNode
			|| $arg->name !== null
			|| $arg->ampersand !== null
			|| $arg->ellipsis !== null
			|| !$context->report(
				$node,
				"The `$function()` call must be written as the `($cast)` cast" . ($node->hasInnerComment() ? ', but a comment stands among its arguments.' : '.'),
				risk: ($uncertainty = GlobalCalls::findUncertainty($node, $context)) === null ? null : Risk::NameUncertain,
				because: $uncertainty,
				fixable: !$node->hasInnerComment(),
			)
		) {
			return;
		}

		$node->replaceWith((new Builder)->cast($cast, $arg->value));
	}
}
