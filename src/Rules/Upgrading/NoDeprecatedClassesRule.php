<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\Types;
use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\Statement\NamespaceNode;


/**
 * References of classes, interfaces and enums their declaration deprecates, wherever the name stands, an import,
 * a type, an instantiation, a static access, an attribute. Where the deprecation names the class to use instead,
 * `@deprecated use Acme\Mail\SmtpTransport`, and that class exists, the reference is rewritten to it and the imports
 * follow; any other is reported with what the deprecation says. A class the map of replaced-classes or of
 * forbidden-classes has is not reported.
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
		return [FileNode::class, NamespaceNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ((!$node instanceof FileNode && !$node instanceof NamespaceNode) || NodeHelpers::findImportScope($node) !== $node) {
			return;
		}

		$types = $context->getAnalysis(Types::class);
		$replaced = $context->findRule(ReplacedClassesRule::class);
		$forbidden = $context->findRule(ForbiddenClassesRule::class);
		ClassReplacement::apply($node, $context, function (string $class) use ($types, $replaced, $forbidden): ?array {
			$deprecation = $replaced?->knows($class) || $forbidden?->knows($class) ? null : $types->getClassDeprecation($class);
			return $deprecation === null
				? null
				: [
					"Class $class is deprecated" . NodeHelpers::formatDeprecation($deprecation),
					$deprecation->replacementClass,
				];
		});
	}
}
