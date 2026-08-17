<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\IdentifierNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Member\PropertyHookNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function in_array;


/**
 * A closure or arrow function that does not use `$this` is declared static, so that it holds no reference
 * to the object and cannot be bound to one by mistake. `parent::`, `include`, `eval` and variable variables
 * may hide `$this`, such a closure stays; so does one bound right away with `bindTo()`, `call()` or
 * `Closure::bind()`. A closure bound elsewhere is out of sight of the rule.
 */
#[RuleInfo(
	'dresscode/static-closure',
	Stage::Structure,
	description: 'Declares a closure that does not use $this as static',
)]
final class StaticClosureRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [Expression\ClosureNode::class, Expression\ArrowFunctionNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof Expression\ClosureNode && !$node instanceof Expression\ArrowFunctionNode)
			|| $node->staticKeyword !== null
			|| self::mayUseThis($node)
			|| self::isBound($node)
		) {
			return;
		}

		$keyword = $node instanceof Expression\ClosureNode ? $node->functionKeyword : $node->fnKeyword;
		if (!$context->report($keyword, 'A closure not using $this must be static')) {
			return;
		}

		$static = new Token(TokenKind::Static, 'static');
		$static->setLeadingTrivia($keyword->leadingTrivia);
		$static->setTrailingTrivia([new Trivia(TriviaKind::Whitespace, ' ')]);
		$keyword->setLeadingTrivia([]);
		$node->setStaticKeyword($static);
	}


	private static function mayUseThis(Expression\ClosureNode|Expression\ArrowFunctionNode $closure): bool
	{
		foreach ($closure->getDescendants() as $node) {
			$class = match (true) {
				$node instanceof Expression\StaticCallNode, $node instanceof Expression\StaticPropertyFetchNode, $node instanceof Expression\ClassConstantFetchNode => $node->class,
				$node instanceof Expression\NewNode => $node->class,
				default => null,
			};
			if (
				$node instanceof Expression\IncludeNode
				|| $node instanceof Expression\EvalNode
				|| ($node instanceof Expression\VariableNode && ($node->dollar !== null || !$node->name instanceof Token))
				|| (
					$node instanceof Expression\VariableNode
					&& $node->name instanceof Token
					&& $node->name->text === '$this'
					&& self::belongsTo($node, $closure)
				)
				|| (
					$class instanceof NameNode
					&& strtolower($class->getName()) === 'parent'
					&& self::belongsTo($node, $closure)
				)
			) {
				return true;
			}
		}

		return false;
	}


	/** Whether $this at the node is the one of the closure: no function, method or static closure in between. */
	private static function belongsTo(Node $node, Expression\ClosureNode|Expression\ArrowFunctionNode $closure): bool
	{
		for ($ancestor = $node->parent; $ancestor !== null && $ancestor !== $closure; $ancestor = $ancestor->parent) {
			if (
				$ancestor instanceof FunctionNode
				|| $ancestor instanceof MethodNode
				|| $ancestor instanceof PropertyHookNode
				|| (($ancestor instanceof Expression\ClosureNode || $ancestor instanceof Expression\ArrowFunctionNode) && $ancestor->staticKeyword !== null)
			) {
				return false;
			}
		}

		return true;
	}


	/** `(function () {})->bindTo($o)`, `(fn() => 1)->call($o)` and `Closure::bind(function () {}, $o)`. */
	private static function isBound(Expression\ClosureNode|Expression\ArrowFunctionNode $closure): bool
	{
		$parent = $closure->parent;
		if ($parent instanceof Expression\ParenthesizedNode) {
			$call = $parent->parent;
			return $call instanceof Expression\MethodCallNode
				&& $call->object === $parent
				&& $call->name instanceof IdentifierNode
				&& in_array(strtolower($call->name->token->text), ['bindto', 'call'], strict: true);
		}

		$call = $parent instanceof ArgumentNode ? $parent->parent?->parent?->parent : null;
		return $call instanceof Expression\StaticCallNode
			&& $call->class instanceof NameNode
			&& preg_match('~(^|\\\)closure$~i', $call->class->getName())
			&& $call->name instanceof IdentifierNode
			&& strtolower($call->name->token->text) === 'bind'
			&& ($call->args->args->getItems()[0] ?? null) === $parent;
	}
}
