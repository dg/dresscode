<?php declare(strict_types=1);

namespace DressCode\Rules\Namespaces;

use DressCode\Analyses\PhpSymbols;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\SymbolKind;
use PhpSyntax\Token;


/**
 * Classes, interfaces and enums of PHP and of the extensions shipped with it are referenced with the case of their
 * declaration: `stdClass`, `Exception`, `Random\Randomizer`.
 */
#[RuleInfo(
	'dresscode/class-reference-name-casing',
	Stage::Structure,
	description: 'Writes the names of internal classes, interfaces and enums in their declared case',
)]
final class ClassReferenceNameCasingRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [NameNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof NameNode || $node->role !== SymbolKind::ClassLike || $node->isDeclaration()) {
			return;
		}

		$resolved = $context->getAnalysis(NameResolver::class)->resolveClass($node);
		$canonical = $context->getAnalysis(PhpSymbols::class)->findClassName($resolved);
		if (
			$canonical === null
			|| $resolved === $canonical
			|| $resolved !== implode('\\', $node->parts) // an alias or a namespace resolves elsewhere
			|| !$context->report($node, "The class name must be written '$canonical'")
		) {
			return;
		}

		$node->text = ($node->isFullyQualified() ? '\\' : '') . $canonical;
	}
}
