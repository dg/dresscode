<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Analyses\{NameResolver, Scope};
use PhpSyntax\{Node, Parser, Token};
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression\{FunctionCallNode, VariableNode};
use PhpSyntax\Nodes\Scalar\StringNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use function array_key_exists, count, in_array;


/**
 * An assignment with a cast instead of a `settype()` statement: `$a = (int) $a;`, not `settype($a, 'int');`.
 * The cast reads the variable, which warns where it is undefined and `settype()` does not, so the call stays in
 * a scope that reaches variables by names it does not spell out, where a variable may exist only at run time.
 * It stays on `$this` and `$GLOBALS` too, which cannot be assigned.
 */
#[RuleInfo(
	'dresscode/no-settype',
	Stage::Structure,
	description: 'Assigns a cast instead of calling settype()',
	group: Group::OptimizedCalls,
)]
final class NoSettypeRule extends NodeRule
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
		if (!$node instanceof ExpressionStatementNode || !($call = $node->expression) instanceof FunctionCallNode) {
			return;
		}

		$args = $call->arguments->items->getItems();
		[$var, $type] = [$args[0] ?? null, $args[1] ?? null];
		if (
			count($args) !== 2
			|| !$var instanceof ArgumentNode
			|| !$type instanceof ArgumentNode
			|| $var->name || $var->ampersand || $var->ellipsis
			|| $type->name || $type->ampersand || $type->ellipsis
			|| !$var->value instanceof VariableNode
			|| !$var->value->name instanceof Token
			|| $var->value->dollar || $var->value->openBrace
			|| in_array($var->value->name->text, ['$this', '$GLOBALS'], true)
			|| !$type->value instanceof StringNode
			|| !preg_match('~^(["\'])([a-z]+)\1$~i', $type->value->token->text, $m)
			|| !array_key_exists($cast = strtolower($m[2]), self::Casts)
			|| !$context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($call, 'settype')
			|| $call->hasComment()
			|| self::hasDynamicVariables($call, $context)
			|| !$context->report(
				$call,
				'The settype() call must be written as an assignment of a cast' . ($uncertainty = NodeHelpers::findUncertainty($call, $context)),
				risky: $uncertainty !== null,
			)
		) {
			return;
		}

		$name = $var->value->name->text;
		$cast = self::Casts[$cast];
		$call->replaceWith((new Parser)->parseExpression($cast === null ? "$name = null" : "$name = ($cast) $name"));
	}


	/** Whether the function or the file the node stands in reaches a variable by a name it does not spell out. */
	private static function hasDynamicVariables(Node $node, RuleContext $context): bool
	{
		$scopes = $context->getAnalysis(Scope::class);
		$function = $scopes->getFunction($node);
		return array_any(
			NodeHelpers::findDynamicVariableAccesses($function ?? $context->getFile(), $context),
			fn(Node $access) => $scopes->getFunction($access) === $function,
		);
	}
}
