<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage, Values};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, SymbolKind, Token};
use PhpSyntax\Nodes\{NameNode, UseItemNode};


/**
 * References of the classes, interfaces and enums a project, or a library it stands on, says its code must not have,
 * each reported with what the map says to do instead, wherever the name stands, a type, an instantiation, a static
 * access, an attribute; nothing is rewritten, which is what the map is for where no other class could simply stand
 * for the one. An import is not reported: it names nothing once the references are gone, and goes with them.
 */
#[RuleInfo(
	Stage::Structure,
	decisions: ['upgrading.libraries.packages', 'upgrading.libraries.forbiddenClasses'],
	analyses: [NameResolver::class],
)]
final class ForbiddenClassesRule extends NodeRule
{
	public const Map = MemberMapGrammar::ForbiddenClasses;

	/** @var array<string, array{string, string}>  lowercased class => the class as the map spells it and the end of the message */
	private array $classes = [];


	public function configure(Values $values): void
	{
		$options = $values->readMap(self::Map);
		$this->classes = [];
		foreach ($options as $class => $message) {
			$this->classes[strtolower(ltrim((string) $class, '\\'))] = [ltrim((string) $class, '\\'), $message === null ? '' : ": $message"];
		}
	}


	public function getVisitedNodes(): array
	{
		return [NameNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			$this->classes === []
			|| !$node instanceof NameNode
			|| $node->parent instanceof UseItemNode
			|| $node->symbolKind !== SymbolKind::ClassLike
			|| !$node->isReference()
		) {
			return;
		}

		$entry = $this->classes[strtolower($context->getAnalysis(NameResolver::class)->resolveClass($node))] ?? null;
		if ($entry !== null) {
			$context->report($node, "Class `$entry[0]` is forbidden$entry[1].", fixable: false);
		}
	}
}
