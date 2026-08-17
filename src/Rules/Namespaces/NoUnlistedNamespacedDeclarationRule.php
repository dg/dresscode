<?php declare(strict_types=1);

namespace DressCode\Rules\Namespaces;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Rules\NodeHelpers;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Analyses\NamespacedSymbols;
use PhpSyntax\Node;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\SymbolKind;
use PhpSyntax\Token;


/**
 * A function or a constant declared in a namespace is listed in the namespaces of the configuration once it says
 * nameResolution: certain, because an unqualified name in that namespace is then taken as global
 * wherever the lists do not name it. A declaration inside a condition counts, and so does define() with the name
 * written as a string. The key turns the rule on, and under an uncertain resolution it has nothing to guard.
 */
#[RuleInfo(
	'dresscode/no-unlisted-namespaced-declaration',
	Stage::Structure,
	description: 'Reports a function or a constant declared in a namespace that the configuration does not list',
)]
final class NoUnlistedNamespacedDeclarationRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [FileNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$symbols = $context->getAnalysis(NamespacedSymbols::class);
		if (!$node instanceof FileNode || !$symbols->complete) {
			return;
		}

		foreach (NodeHelpers::findNamespacedDeclarations($node, $context->getAnalysis(NameResolver::class)) as [$kind, $name, $at]) {
			if ($kind === SymbolKind::Function && !$symbols->hasFunction($name)) {
				$context->report($at, "Function $name() must be listed in namespaces.functions", fixable: false);
			} elseif ($kind === SymbolKind::Constant && !$symbols->hasConstant($name)) {
				$context->report($at, "Constant $name must be listed in namespaces.constants", fixable: false);
			}
		}
	}
}
