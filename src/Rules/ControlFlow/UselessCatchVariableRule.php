<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Analyses\Scope;
use PhpSyntax\Node;
use PhpSyntax\Nodes\CatchNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Token;


/**
 * A catch keeps no variable it never reads: `catch (Exception $e)` becomes `catch (Exception)`. The variable
 * outlives the block, so it counts as read anywhere in the enclosing function, in nested closures included;
 * a scope with variable variables, compact() or get_defined_vars() is left alone.
 */
#[RuleInfo(
	'dresscode/useless-catch-variable',
	Stage::Structure,
	description: 'Removes the variable of a catch clause that is never used',
)]
final class UselessCatchVariableRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [CatchNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof CatchNode
			|| $node->variable === null
			|| !$node->variable->name instanceof Token
		) {
			return;
		}

		$name = $node->variable->name->text;
		$scope = $context->getAnalysis(Scope::class)->getFunction($node) ?? $context->getFile();
		foreach ($scope->find(VariableNode::class) as $var) {
			if ($var === $node->variable) {
				continue;
			} elseif (
				$var->dollar !== null
				|| !$var->name instanceof Token
				|| ($var->name->text === $name && !self::declaresCatchVariable($var))
			) {
				return;
			}
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		foreach ($scope->find(FunctionCallNode::class) as $call) {
			foreach (['compact', 'get_defined_vars', 'extract'] as $function) {
				if ($resolver->isGlobalFunctionCall($call, $function)) {
					return;
				}
			}
		}

		if (!$context->report($node->variable, "Useless catch variable $name, it is never used")) {
			return;
		}

		$previous = $node->variable->getFirstToken()?->getPrevious();
		if ($previous?->getTrailingSpace() !== null) {
			$previous->setTrailingSpace('');
		}

		$node->variable = null;
	}


	private static function declaresCatchVariable(VariableNode $var): bool
	{
		return $var->parent instanceof CatchNode && $var->parent->variable === $var;
	}
}
