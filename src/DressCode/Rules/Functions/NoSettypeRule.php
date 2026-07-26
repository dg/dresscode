<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\Scalar\StringNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use function count;


/**
 * An assignment with a cast instead of a `settype()` statement: `$a = (int) $a;`, not `settype($a, 'int');`.
 */
#[RuleInfo(
	'dresscode/no-settype',
	Stage::Structure,
	description: 'Assigns a cast instead of calling settype()',
)]
final class NoSettypeRule extends Rule
{
	private const Casts = [
		'int' => 'int',
		'integer' => 'int',
		'bool' => 'bool',
		'boolean' => 'bool',
		'float' => 'float',
		'double' => 'float',
		'string' => 'string',
		'array' => 'array',
		'object' => 'object',
		'null' => null,
	];


	public function getVisitedTypes(): array
	{
		return [ExpressionStatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ExpressionStatementNode || !($call = $node->expr) instanceof FunctionCallNode) {
			return;
		}

		$args = $call->args->args->getItems();
		[$var, $type] = [$args[0] ?? null, $args[1] ?? null];
		if (
			count($args) !== 2
			|| !$var instanceof ArgumentNode
			|| !$type instanceof ArgumentNode
			|| $var->name || $var->byRef || $var->ellipsis
			|| $type->name || $type->byRef || $type->ellipsis
			|| !$var->expr instanceof VariableNode
			|| !$var->expr->name instanceof Token
			|| $var->expr->dollar || $var->expr->openBrace
			|| !$type->expr instanceof StringNode
			|| !preg_match('~^(["\'])([a-z]+)\1$~i', $type->expr->token->text, $m)
			|| !array_key_exists($cast = strtolower($m[2]), self::Casts)
			|| !$context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($call, 'settype')
			|| $call->hasComment()
			|| !$context->report($call, 'The settype() call must be written as an assignment of a cast')
		) {
			return;
		}

		$name = $var->expr->name->text;
		$cast = self::Casts[$cast];
		$call->replaceWith((new Parser)->parseExpression($cast === null ? "$name = null" : "$name = ($cast) $name"));
	}
}
