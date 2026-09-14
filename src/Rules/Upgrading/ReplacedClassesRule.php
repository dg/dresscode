<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{PhpDoc, Types};
use DressCode\{NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Rules\CodeWriter;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\Statement\NamespaceNode;


/**
 * A tool for replacing a class across a codebase: the project, or a library it stands on, maps a class, interface or
 * enum to the one it wants written instead, and the rule rewrites every reference, an import, a type, an instantiation,
 * a static access, an attribute, a type or a reference in a doc comment, importing the new name the way the scope
 * imports; a class a comment only mentions in its text is left as it is. The fix is not risky, because what changes is
 * exactly what the map asked for. Where the run has the types of the code, a class the project does not have is
 * reported and not written.
 */
#[RuleInfo(
	Stage::Structure,
	modifiesComments: true,
	decisions: ['upgrading.libraries.packages', 'upgrading.libraries.replacedClasses'],
	analyses: [PhpDoc::class, Types::class, NameResolver::class],
)]
final class ReplacedClassesRule extends NodeRule
{
	public const Map = 'upgrading.libraries.replacedClasses';

	/** @var array<string, string>  lowercased replaced name => the name written instead, both fully qualified */
	private array $classes = [];


	public function configure(Values $values): void
	{
		$options = $values->readMap(self::Map);
		$this->classes = [];
		foreach ($options as $old => $new) {
			$this->classes[strtolower(ltrim((string) $old, '\\'))] = ltrim($new, '\\');
		}
	}


	public function getVisitedNodes(): array
	{
		return [FileNode::class, NamespaceNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			($node instanceof FileNode || $node instanceof NamespaceNode)
			&& $this->classes !== []
			&& CodeWriter::findImportScope($node) === $node
		) {
			$types = $context->findAnalysis(Types::class);
			$find = function (string $class) use ($types): ?array {
				$new = $this->classes[strtolower($class)] ?? null;
				return match (true) {
					$new === null => null,
					$types !== null && $types->findClassName($new) === null => ["Class `$class` is replaced by `$new`, but class `$new` does not exist in the project", null],
					default => ["Class `$class` is replaced by `$new`", $new],
				};
			};
			ClassReplacement::rewriteReferences($node, $context, $find, $find);
		}
	}
}
