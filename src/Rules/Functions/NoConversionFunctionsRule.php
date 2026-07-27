<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Rules\NodeHelpers;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Parser;
use PhpSyntax\Token;
use function count;


/**
 * A cast instead of the conversion functions: `(int) $x`, not `intval($x)`; a call with a base or
 * other extra arguments stays.
 */
#[RuleInfo(
	'dresscode/no-conversion-functions',
	Stage::Structure,
	description: 'Uses a cast instead of intval() and friends',
)]
final class NoConversionFunctionsRule extends NodeRule
{
	private const Casts = [
		'intval' => 'int',
		'floatval' => 'float',
		'doubleval' => 'float',
		'strval' => 'string',
		'boolval' => 'bool',
	];


	public function getVisitedTypes(): array
	{
		return [Expression\FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Expression\FunctionCallNode) {
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$cast = null;
		foreach (self::Casts as $function => $type) {
			if ($resolver->isGlobalFunctionCall($node, $function)) {
				$cast = $type;
				break;
			}
		}

		$args = $node->arguments->items->getItems();
		$arg = $args[0] ?? null;
		if (
			$cast === null
			|| count($args) !== 1
			|| !$arg instanceof ArgumentNode
			|| $arg->name !== null
			|| $arg->ampersand !== null
			|| $arg->ellipsis !== null
			|| !$context->report($node, "The conversion function must be written as the '($cast)' cast")
		) {
			return;
		}

		$operand = clone $arg->value;
		$operand->setEdgeTrivia(leading: []);
		$operand->getLastToken()?->removeTrailingWhitespace();
		if (!NodeHelpers::bindsTighterThanPrefix($arg->value)) {
			$template = (new Parser)->parseExpression("($cast) (0)");
			assert($template instanceof Expression\CastNode && $template->expression instanceof Expression\ParenthesizedNode);
			$template->expression->expression = $operand;
		} else {
			$template = (new Parser)->parseExpression("($cast) 0");
			assert($template instanceof Expression\CastNode);
			$template->expression = $operand;
		}

		$node->replaceWith($template);
	}
}
