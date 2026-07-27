<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\NameRole;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\CatchNode;
use PhpSyntax\Nodes\Expression\InstanceofNode;
use PhpSyntax\Nodes\Expression\NewNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\SeparatedNodeList;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Nodes\Statement\TryNode;
use PhpSyntax\Token;


/**
 * Code catching or typing the general `\Exception` misses errors; `\Throwable` covers both. Extending,
 * instantiating and `instanceof` are fine, as is a catch of Exception followed by a catch of Throwable.
 * Reported only: the replacement would change what the code catches.
 */
#[RuleInfo(
	'dresscode/reference-throwable-only',
	Stage::Structure,
	description: 'Reports references to the general Exception where Throwable belongs',
)]
final class ReferenceThrowableOnlyRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [NameNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof NameNode || $node->getRole() !== NameRole::ClassLike) {
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$parent = $node->parent;
		if (
			strcasecmp($resolver->resolveClass($node), 'Exception') !== 0
			|| $parent instanceof NewNode
			|| $parent instanceof InstanceofNode
			|| (($parent instanceof ClassNode || $parent instanceof AnonymousClassNode) && $parent->extends === $node)
			|| ($parent instanceof SeparatedNodeList && $parent->parent instanceof CatchNode && self::isFollowedByThrowableCatch($parent->parent, $resolver))
		) {
			return;
		}

		$context->report($node, 'The general Exception is referenced where Throwable belongs');
	}


	private static function isFollowedByThrowableCatch(CatchNode $catch, NameResolver $resolver): bool
	{
		$try = $catch->parent?->parent;
		if (!$try instanceof TryNode) {
			return false;
		}

		$catches = $try->catches->getItems();
		foreach (array_slice($catches, $try->catches->indexOf($catch) + 1) as $later) {
			foreach ($later->types->getItems() as $type) {
				if (strcasecmp($resolver->resolveClass($type), 'Throwable') === 0) {
					return true;
				}
			}
		}

		return false;
	}
}
