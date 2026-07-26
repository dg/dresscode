<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\Scalar\BooleanNode;
use PhpSyntax\Parser;
use PhpSyntax\Token;
use function array_slice, count, in_array;


/**
 * Functions with a `$strict` parameter are called with it set to `true`: a missing one is added, together with
 * the default values of the parameters before it; an explicit `false` is only reported.
 */
#[RuleInfo(
	'dresscode/strict-call',
	Stage::Structure,
	description: 'Calls in_array(), array_search(), array_keys(), base64_decode() and mb_detect_encoding() with $strict = true',
)]
final class StrictCallRule extends NodeRule
{
	/** function → arguments up to $strict; null for a required one that cannot be made up */
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

		$resolver = $context->getAnalysis(NameResolver::class);
		$params = null;
		foreach (self::StrictArguments as $function => $candidate) {
			if ($resolver->isGlobalFunctionCall($node, $function)) {
				$params = $candidate;
				break;
			}
		}

		if ($params === null) {
			return;
		}

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

		$message = "The $function() call must pass \$strict = true";
		if ($given === count($params)) {
			$strict = $args[$given - 1]->value;
			if ($strict instanceof BooleanNode && !$strict->value) {
				$context->report($strict, $message);
			}

			return;
		}

		$missing = array_slice($params, $given);
		if (in_array(null, $missing, strict: true) || !$context->report($node, $message)) {
			return;
		}

		foreach ($missing as $value) {
			$call = (new Parser)->parseExpression("f($value)");
			assert($call instanceof FunctionCallNode);
			$node->arguments->items->append(clone $call->arguments->items->getItems()[0]);
		}
	}
}
