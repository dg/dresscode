<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Rules\NodeHelpers;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\ClosureUseNode;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\FunctionLikeNode;
use PhpSyntax\Nodes\IdentifierNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use PhpSyntax\Token;
use function count, in_array;


/**
 * `setAccessible()` has done nothing since PHP 8.1, where reflection reaches a private member without being
 * asked, and PHP 8.5 deprecated it outright, so the call goes.
 *
 * Which object the call stands on the code must say itself: the receiver is a variable that the function it
 * stands in assigns once and only once, from a `new ReflectionProperty` or a `new ReflectionMethod`. A receiver
 * of any other shape stays, because a `setAccessible()` of another class is another method; a function reaching
 * a variable by a name it does not spell out keeps all its calls.
 */
#[RuleInfo(
	'dresscode/useless-set-accessible',
	Stage::Structure,
	description: 'Removes setAccessible() calls, which do nothing since PHP 8.1',
	group: Group::Cleanup,
	requires: ['php' => '>=8.1'],
)]
final class UselessSetAccessibleRule extends NodeRule
{
	private const ReflectionClasses = ['ReflectionProperty', 'ReflectionMethod'];


	public function getVisitedTypes(): array
	{
		return [ExpressionStatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ExpressionStatementNode || $node->hasComment()) {
			return;
		}

		$call = $node->expression;
		if (
			!$call instanceof Expression\MethodCallNode
			|| $call->isNullsafe()
			|| !$call->name instanceof IdentifierNode
			|| strcasecmp($call->name->text, 'setAccessible') !== 0
			|| !$call->object instanceof Expression\VariableNode
			|| ($name = $call->object->plainName) === null
			|| !self::hasPlainArguments($call)
			|| !$this->isReflectionVariable($call->object, $name, $context)
			|| !$context->report($call, 'Useless setAccessible() call, reflection reaches the member without it since PHP 8.1')
		) {
			return;
		}

		$node->remove();
	}


	/** Whether every argument is written out and reading it again would do nothing, so that dropping it is free. */
	private static function hasPlainArguments(Expression\MethodCallNode $call): bool
	{
		foreach ($call->arguments->items as $argument) {
			if (
				!$argument instanceof ArgumentNode
				|| $argument->ampersand
				|| $argument->ellipsis
				|| !$argument->value->isRepeatableRead()
			) {
				return false;
			}
		}

		return true;
	}


	/** Whether the variable holds a reflection of a member and nothing else, by the one assignment it has. */
	private function isReflectionVariable(Expression\VariableNode $variable, string $name, RuleContext $context): bool
	{
		$owner = $variable->findAncestor(FunctionLikeNode::class);
		$scope = $owner ?? $variable->getFile();
		if ($scope === null || NodeHelpers::findDynamicVariableAccesses($scope, $context) !== []) {
			return false;
		}

		$assignments = [];
		foreach ($scope->find(Expression\VariableNode::class, fn(Expression\VariableNode $v) => $v->plainName === $name) as $occurrence) {
			$parent = $occurrence->parent;
			if ($parent instanceof ClosureUseNode) {
				// a closure holding the variable by reference writes it from wherever it is called
				if ($parent->ampersand !== null) {
					return false;
				}
			} elseif ($occurrence->findAncestor(FunctionLikeNode::class) !== $owner) {
				continue; // a variable of a nested function is another variable of the same name
			} elseif ($parent instanceof Expression\AssignmentNode && $parent->target === $occurrence) {
				$assignments[] = $parent->expression;
			} elseif (self::isWritten($occurrence)) {
				return false;
			}
		}

		$new = count($assignments) === 1 ? $assignments[0] : null;
		return $new instanceof Expression\NewNode
			&& $new->class instanceof NameNode
			&& in_array($context->getAnalysis(NameResolver::class)->resolveClass($new->class), self::ReflectionClasses, true);
	}


	/** Whether something other than a plain assignment writes the variable, which makes its value unknown. */
	private static function isWritten(Expression\VariableNode $variable): bool
	{
		$parent = $variable->parent;
		return $parent instanceof Expression\AssignmentByReferenceNode
			|| $parent instanceof Expression\CombinedAssignmentNode
			|| $parent instanceof Expression\PrefixOpNode
			|| $parent instanceof Expression\PostfixOpNode
			|| ($parent instanceof ArgumentNode && $parent->ampersand !== null);
	}
}
