<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\Types;
use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage, Tristate};
use DressCode\Rules\{CodeWriter, GlobalCalls};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArgumentNode, NameNode};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\Scalar\BooleanNode;
use function count;


/**
 * Functions with a `$strict` parameter are called with it set to `true`: a missing one is added, together with
 * the default values of the parameters before it; an explicit `false` is only reported. Such a fix changes what
 * the call answers for a value only the loose mode accepted, so it waits for the run to allow it, but for an
 * integer needle searched among integers, which it leaves as it was. Without the types, such a search is not told
 * from another.
 */
#[RuleInfo(Stage::Structure, analyses: [Types::class, NameResolver::class])]
final class StrictComparisonArgumentRequiredRule extends NodeRule
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


	public static function getDecisions(): array
	{
		return [new Decision('correctness.strictComparisonArgument', Domain::state('required'), '`in_array()`, `array_search()`, `array_keys()`, `base64_decode()`, `mb_detect_encoding()` pass `strict: true`; an explicit `false` is reported')];
	}


	public function getVisitedNodes(): array
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

		$message = "The `$function()` call must pass `\$strict = true`.";
		$args = $node->arguments->items->getItems();
		$named = array_find($args, fn(Node $arg) => $arg instanceof ArgumentNode && $arg->name?->text === 'strict');
		if ($named instanceof ArgumentNode) {
			self::reportFalse($named->value, $message, $context);
			return;
		}

		foreach ($args as $arg) {
			if (!$arg instanceof ArgumentNode || $arg->name !== null || $arg->ellipsis !== null) {
				return;
			}
		}

		$given = count($args);
		if ($given > count($params)) {
			return;
		} elseif ($given === count($params)) {
			self::reportFalse($args[$given - 1]->value, $message, $context);
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
		$integers = $needle instanceof ArgumentNode && $haystack instanceof ArgumentNode && $types !== null
			? [$types->isOfType($needle->value, 'int'), $types->isOfType($haystack->value, 'array<int>')]
			: [];
		$risk = match (true) {
			!$needle instanceof ArgumentNode || !$haystack instanceof ArgumentNode => Risk::BehaviorChanges,
			$integers === [Tristate::Yes, Tristate::Yes] => null,
			in_array(Tristate::No, $integers, true) => Risk::BehaviorChanges,
			default => Risk::TypeUnknown,
		};
		$uncertainty = GlobalCalls::findUncertainty($node, $context);
		if (!$context->report(
			$node,
			$message,
			risk: $risk ?? ($uncertainty === null ? null : Risk::NameUncertain),
			because: $risk === null ? $uncertainty : match ($function) {
				'base64_decode' => 'a strict decoding gives false for a character outside the alphabet',
				'mb_detect_encoding' => 'a strict detection gives only an encoding the whole string is valid in',
				default => 'a strict search no longer finds a value of another type',
			},
		)) {
			return;
		}

		foreach ($missing as $value) {
			// a default made of a call of another global function is spelled the way the name of this call is
			$value = str_ends_with($value, '()') && $node->name instanceof NameNode
				? CodeWriter::spellFunction(substr($value, 0, -2), $node->name, $context) . '()'
				: $value;
			$node->arguments->items->append((new Builder)->fragment(ArgumentNode::class, $value));
		}
	}


	/** Reports the argument of `$strict` where it is an explicit `false`, which the rule does not overrule. */
	private static function reportFalse(Node $strict, string $message, RuleContext $context): void
	{
		if ($strict instanceof BooleanNode && !$strict->toValue()) {
			$context->report($strict, $message, fixable: false);
		}
	}
}
