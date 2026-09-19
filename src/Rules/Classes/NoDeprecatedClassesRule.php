<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\Analyses\Types;
use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Statement\NamespaceNode;
use PhpSyntax\Token;


/**
 * References of classes, interfaces and enums their declaration deprecates, wherever the name stands, an import,
 * a type, an instantiation, a static access, an attribute. Where the deprecation names the class to use instead,
 * `@deprecated use Nette\Forms\Control`, and that class exists, the reference is rewritten to it and the imports
 * follow; any other is reported with what the deprecation says. A class the map of replaced-classes has is not
 * reported.
 */
#[RuleInfo(
	'dresscode/no-deprecated-classes',
	Stage::Structure,
	description: 'Replaces deprecated classes with the class their deprecation names, and reports the rest',
	group: Group::Deprecations,
	requiresTypes: true,
)]
final class NoDeprecatedClassesRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [NamespaceNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof NamespaceNode) {
			return;
		}

		$types = $context->getAnalysis(Types::class);
		$replaced = $context->findRule(ReplacedClassesRule::class);
		ClassReplacement::apply($node, $context, function (string $class) use ($types, $replaced): ?array {
			$deprecation = $replaced?->knows($class) ? null : $types->getClassDeprecation($class);
			return $deprecation === null
				? null
				: [
					"Class $class is deprecated" . ($deprecation->description === '' ? '' : ": $deprecation->description"),
					$deprecation->replacementClass,
				];
		});
	}
}
