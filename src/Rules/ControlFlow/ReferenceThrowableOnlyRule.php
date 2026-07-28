<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\CatchNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\Statement\TryNode;
use PhpSyntax\Nodes\Type\NamedTypeNode;
use PhpSyntax\Token;
use function array_slice;


/**
 * Code catching or typing the general `\Exception` misses errors; `\Throwable` covers both. Only the types of a
 * catch and of a declaration are looked at, and a catch of Exception followed by a catch of Throwable is fine.
 * Reported only: the replacement would change what the code catches.
 */
#[RuleInfo(
	'dresscode/reference-throwable-only',
	Stage::Structure,
	description: 'Reports references to the general Exception where Throwable belongs',
)]
final class ReferenceThrowableOnlyRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [CatchNode::class, NamedTypeNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		if ($node instanceof NamedTypeNode) {
			$names = [$node->name];
		} elseif ($node instanceof CatchNode && !self::isFollowedByThrowableCatch($node, $resolver)) {
			$names = $node->types->getItems();
		} else {
			return;
		}

		foreach ($names as $name) {
			if (self::isClass($name, 'Exception', $resolver)) {
				$context->report($name, 'The general Exception is referenced where Throwable belongs');
			}
		}
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
				if (self::isClass($type, 'Throwable', $resolver)) {
					return true;
				}
			}
		}

		return false;
	}


	private static function isClass(NameNode $name, string $class, NameResolver $resolver): bool
	{
		return strcasecmp($resolver->resolveClass($name), $class) === 0;
	}
}
