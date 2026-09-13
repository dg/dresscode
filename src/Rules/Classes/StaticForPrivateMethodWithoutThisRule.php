<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\ClassLikeNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\Expression\MethodCallNode;
use PhpSyntax\Nodes\Expression\StaticMethodCallNode;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\IdentifierNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function in_array;


/**
 * A private method that uses no `$this` is `static`, which says it needs no object, and the class calls it as
 * `self::method()`; a method that only called such methods through `$this` becomes one in the next round.
 *
 * The method is left alone where the static context could change what its body does: `$this` anywhere in it, a
 * closure that inherits it included and an anonymous class that has its own excluded, a variable variable or
 * `compact()`, `extract()` and `get_defined_vars()`, which could reach it by name, `debug_backtrace()`, which shows
 * the object, `parent::` and a call through `self::` or `static::` of a method the class does not declare static,
 * which PHP refuses to call without an object. A method calling itself through `$this` stays too.
 *
 * Every fix is risky, because what the file does not show can notice the change: a closure made of the method is
 * static and refuses to be bound to an object, and reflection reports it static.
 */
#[RuleInfo(
	'dresscode/static-for-private-method-without-this',
	Stage::Structure,
	description: 'Marks a private method that does not use $this as static',
	group: Group::Cleanup,
	risky: true,
)]
final class StaticForPrivateMethodWithoutThisRule extends NodeRule
{
	private const ScopeReaders = ['compact', 'extract', 'get_defined_vars', 'debug_backtrace'];


	public function getVisitedTypes(): array
	{
		return [ClassNode::class, AnonymousClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ClassNode && !$node instanceof AnonymousClassNode) {
			return;
		}

		foreach ($node->members as $method) {
			if (
				!$method instanceof MethodNode
				|| !$this->canBeStatic($method, $node, $context)
				|| !$context->report($method->name, "The private method {$method->name->text}() uses no \$this and must be static")
			) {
				continue;
			}

			$token = new Token(TokenKind::Static, 'static');
			$token->setTrailingTrivia([new Trivia(TriviaKind::Whitespace, ' ')]);
			$method->modifiers->append($token);
			$this->replaceCalls($node, $method->name->text);
		}
	}


	private function canBeStatic(MethodNode $method, ClassNode|AnonymousClassNode $class, RuleContext $context): bool
	{
		if (
			!$method->modifiers->isPrivate()
			|| $method->modifiers->isStatic()
			|| $method->body === null
			|| str_starts_with($method->name->text, '__')
		) {
			return false;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		foreach ($method->body->find(Node::class) as $inner) {
			if ($inner->findAncestor(ClassLikeNode::class) !== $class) {
				continue; // what an anonymous class inside does is its own
			}

			if (
				($inner instanceof VariableNode && ($inner->plainName === null || $inner->plainName === 'this'))
				|| ($inner instanceof StaticMethodCallNode && !$this->canCallWithoutObject($inner, $class))
				|| (
					$inner instanceof FunctionCallNode
					&& $inner->name instanceof NameNode
					&& $resolver->isGlobalFunctionCall($inner)
					&& in_array(strtolower($resolver->resolveFunction($inner->name)), self::ScopeReaders, true)
				)
			) {
				return false;
			}
		}

		return true;
	}


	/**
	 * Whether the static call does the same in a static method: a call of another class never passes the object on,
	 * a call through `self::` or `static::` does only to a method the class does not declare static.
	 */
	private function canCallWithoutObject(StaticMethodCallNode $call, ClassNode|AnonymousClassNode $class): bool
	{
		if (
			!$call->class instanceof NameNode
			|| !in_array(strtolower($call->class->text), ['self', 'static', 'parent'], true)
		) {
			return true;
		} elseif (strtolower($call->class->text) === 'parent' || !$call->name instanceof IdentifierNode) {
			return false;
		}

		foreach ($class->members as $member) {
			if ($member instanceof MethodNode && strcasecmp($member->name->text, $call->name->text) === 0) {
				return $member->modifiers->isStatic();
			}
		}

		return false;
	}


	/** Rewrites every `$this->method()` of the class, an anonymous class inside it aside, to `self::method()`. */
	private function replaceCalls(ClassNode|AnonymousClassNode $class, string $method): void
	{
		foreach ($class->find(MethodCallNode::class) as $call) {
			if (
				$call->findAncestor(ClassLikeNode::class) !== $class
				|| $call->isNullsafe()
				|| !$call->object instanceof VariableNode
				|| $call->object->plainName !== 'this'
				|| !$call->name instanceof IdentifierNode
				|| strcasecmp($call->name->text, $method) !== 0
				|| $call->object->getLastToken()?->hasCommentUpTo($call->arguments->openParen) !== false
			) {
				continue;
			}

			$call->replaceWith(StaticMethodCallNode::of(NameNode::fromText('self'), $method, clone $call->arguments));
		}
	}
}
