<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Statement\TryNode;
use PhpSyntax\Token;


/**
 * A catch after one catching Throwable can never run; reported, never removed.
 */
#[RuleInfo(
	'dresscode/no-unreachable-catch',
	Stage::Structure,
	description: 'Reports a catch block following one that catches Throwable',
)]
final class NoUnreachableCatchRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [TryNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof TryNode) {
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$dead = false;
		foreach ($node->catches->getItems() as $catch) {
			if ($dead) {
				$context->report($catch, 'Unreachable catch block, a previous one catches Throwable');
				continue;
			}

			foreach ($catch->types->getItems() as $type) {
				$dead = $dead || strcasecmp($resolver->resolveClass($type), 'Throwable') === 0;
			}
		}
	}
}
