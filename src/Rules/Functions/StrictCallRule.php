<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\Types;
use DressCode\{NodeRule, Risk, RuleContext, RuleGroup, RuleInfo, Stage, Tristate};
use DressCode\Rules\{CodeWriter, GlobalCalls};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArgumentNode, NameNode};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\Scalar\BooleanNode;
use function array_slice, count, in_array;


/**
 * Functions with a `$strict` parameter are called with it set to `true`: a missing one is added, together with
 * the default values of the parameters before it; an explicit `false` is only reported. Such a fix changes what
 * the call answers for a value only the loose mode accepted, so it waits for the run to allow it, but for an
 * integer needle searched among integers, which it leaves as it was. Without the types, such a search is not told
 * from another.
 */
#[RuleInfo(
	'dresscode/strictCall',
	Stage::Structure,
	description: 'Calls `in_array()`, `array_search()`, `array_keys()`, `base64_decode()` and `mb_detect_encoding()` with `$strict = true`',
	group: RuleGroup::Correctness,
)]
final class StrictCallRule extends NodeRule
{
	/**
	 * function => arguments up to `$strict`; null for one the call must have already, being required or what
	 * `$strict` compares with
	 */
	private const StrictArguments = [
		'array_keys' => [null, null, 'true'],
		'array_search' => [null, null, 'true'],
		'base64_decode' => [null, 'true'],
		'in_array' => [null, null, 'true'],
		'mb_detect_encoding' => [null, 'mb_detect_order()', 'true'],
	];


	public function getVisitedTypes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof FunctionCallNode) {
			return;
		}

		$function = GlobalCalls::findFunction($node, self::StrictArguments, $context);
		if ($function === null) {
			return;
		}

		$params = self::StrictArguments[$function];

		$args = $node->arguments->items->getItems();
		foreach ($args as $arg) {
			if (!$arg instanceof ArgumentNode || $arg->name || $arg->ellipsis) {
				return;
			}
		}

		$given = count($args);
		if ($given > count($params)) {
			return;
		}

		$message = "The `$function()` call must pass `\$strict = true`";
		if ($given === count($params)) {
			$strict = $args[$given - 1]->value;
			if ($strict instanceof BooleanNode && !$strict->value) {
				$context->report($strict, $message, fixable: false);
			}

			return;
		}

		$missing = array_slice($params, $given);
		if (in_array(null, $missing, true)) {
			return;
		}

		// the needle and the haystack of a search, which compare alike either way where both are integers
		[$needle, $haystack] = match ($function) {
			'in_array', 'array_search' => [$args[0], $args[1]],
			'array_keys' => [$args[1], $args[0]],
			default => [null, null],
		};
		$types = $context->findAnalysis(Types::class);
		$risk = match (true) {
			!$needle instanceof ArgumentNode || !$haystack instanceof ArgumentNode => Risk::BehaviorChanges,
			$types?->isOfType($needle->value, 'int') === Tristate::Yes && $types->isOfType($haystack->value, 'array<int>') === Tristate::Yes => null,
			default => Risk::TypeUnknown,
		};
		if (!$context->report($node, $message, risk: $risk)) {
			return;
		}

		foreach ($missing as $value) {
			// a default made of a call of another global function is spelled the way the name of this call is
			$value = str_ends_with($value, '()') && $node->name instanceof NameNode
				? CodeWriter::spellFunction(substr($value, 0, -2), $node->name, $context) . '()'
				: $value;
			$call = (new Builder)->expression("f($value)");
			assert($call instanceof FunctionCallNode);
			$node->arguments->items->append(clone $call->arguments->items->getItems()[0]);
		}
	}
}
