<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression\ConstantFetchNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use function count;


/**
 * Functions with a `$strict` parameter are called with it set to `true`: a missing one is added, together with
 * the default values of the parameters before it; an explicit `false` is only reported.
 */
#[RuleInfo(
	'dresscode/strict-call',
	Stage::Structure,
	description: 'Calls in_array(), array_search(), array_keys(), base64_decode() and mb_detect_encoding() with $strict = true',
)]
final class StrictCallRule extends Rule
{
	/** function → arguments up to $strict; null for a required one that cannot be made up */
	private const Params = [
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
		foreach (self::Params as $function => $candidate) {
			if ($resolver->isGlobalFunctionCall($node, $function)) {
				$params = $candidate;
				break;
			}
		}

		if ($params === null) {
			return;
		}

		$args = $node->args->args->getItems();
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
			$strict = $args[$given - 1]->expr;
			if ($strict instanceof ConstantFetchNode && strcasecmp($strict->name->getName(), 'false') === 0) {
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
			$node->args->args->append(clone $call->args->args->getItems()[0]);
		}
	}
}
