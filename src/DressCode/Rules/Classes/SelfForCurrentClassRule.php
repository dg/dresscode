<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ClassLikeNode;
use PhpSyntax\Nodes\Expression\ClassConstantFetchNode;
use PhpSyntax\Nodes\Expression\InstanceofNode;
use PhpSyntax\Nodes\Expression\NewNode;
use PhpSyntax\Nodes\Expression\StaticCallNode;
use PhpSyntax\Nodes\Expression\StaticPropertyFetchNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use function count;


/**
 * Inside a class, the class refers to itself as `self`, not by its own name: `self::create()`, `new self`.
 */
#[RuleInfo(
	'dresscode/self-for-current-class',
	Stage::Structure,
	description: 'Replaces the name of the current class with self',
)]
final class SelfForCurrentClassRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [ClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ClassNode) {
			return;
		}

		$own = $node->name->token->text;
		$resolver = $context->getAnalysis(NameResolver::class);
		$namespace = $resolver->getNamespace($node);
		$ownFullName = ($namespace === '' ? '' : $namespace . '\\') . $own;
		foreach ($node->getDescendants(NameNode::class) as $name) {
			$parent = $name->parent;
			$parts = $name->getParts();
			if (
				count($parts) !== 1
				|| strcasecmp($parts[0], $own) !== 0
				|| strcasecmp($resolver->resolveClass($name), $ownFullName) !== 0
				|| !($parent instanceof StaticCallNode || $parent instanceof ClassConstantFetchNode || $parent instanceof StaticPropertyFetchNode || $parent instanceof NewNode || $parent instanceof InstanceofNode)
				|| $name->findAncestor(ClassLikeNode::class) !== $node
				|| !$context->report($name, "The current class '$own' must be referenced as 'self'")
			) {
				continue;
			}

			$token = new Token(TokenKind::Identifier, 'self');
			$token->setLeadingTrivia($name->token->leadingTrivia);
			$token->setTrailingTrivia($name->token->trailingTrivia);
			$name->setToken($token);
		}
	}
}
