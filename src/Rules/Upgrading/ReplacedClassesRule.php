<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{MemberAccess, MemberKind, PhpDoc, Types};
use DressCode\{NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Rules\CodeWriter;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\{ClassConstantFetchNode, StaticMethodCallNode, StaticPropertyFetchNode};
use PhpSyntax\Nodes\{FileNode, NameNode};
use PhpSyntax\Nodes\Statement\NamespaceNode;


/**
 * A tool for replacing a class across a codebase: the project, or a library it stands on, maps a class, interface or
 * enum to the one it wants written instead, and the rule rewrites every reference, an import, a type, an instantiation,
 * a static access, an attribute, a type or a reference in a doc comment, importing the new name the way the scope
 * imports; a class a comment only mentions in its text is left as it is. The fix is not risky, because what changes is
 * exactly what the map asked for. Where the run has the types of the code, a class the project does not have is
 * reported and not written.
 *
 * Where the run has the types, the class of an access to a member `forbiddenMembers` names stays as it is, its import
 * with it: such a member has no place in the class written instead, so writing that class there would name a member it
 * never had. The ban is the only thing said of the access; every other reference of the class is rewritten as usual.
 */
#[RuleInfo(
	Stage::Structure,
	modifiesComments: true,
	decisions: ['upgrading.libraries.packages', 'upgrading.libraries.replacedClasses'],
	reads: [self::ForbiddenMembers],
	analyses: [PhpDoc::class, Types::class, NameResolver::class],
)]
final class ReplacedClassesRule extends NodeRule
{
	public const Map = 'upgrading.libraries.replacedClasses';
	private const ForbiddenMembers = 'upgrading.libraries.forbiddenMembers';

	/** @var array<string, string>  lowercased replaced name => the name written instead, both fully qualified */
	private array $classes = [];

	/** @var MemberMap<mixed> */
	private MemberMap $forbiddenMembers;


	public function configure(Values $values): void
	{
		$options = $values->readMap(self::Map);
		$this->classes = [];
		foreach ($options as $old => $new) {
			$this->classes[strtolower(ltrim((string) $old, '\\'))] = ltrim($new, '\\');
		}

		$this->forbiddenMembers = MemberMap::fromValues($values, self::ForbiddenMembers);
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
			ClassReplacement::rewriteReferences(
				$node,
				$context,
				$find,
				$types === null ? null : fn(NameNode $name) => $this->isForbiddenAccess($name, $types),
				fn(string $class, ?array $member) => $types !== null && $member !== null && $this->isBannedMember($class, ...$member, types: $types)
					? false
					: $find($class),
			);
		}
	}


	/**
	 * Whether the name is the class of an access to a member forbiddenMembers names.
	 */
	private function isForbiddenAccess(NameNode $name, Types $types): bool
	{
		$node = $name->parent;
		if (
			!($node instanceof ClassConstantFetchNode || $node instanceof StaticMethodCallNode || $node instanceof StaticPropertyFetchNode)
			|| $node->class !== $name
		) {
			return false;
		}

		// the types are asked only about a name the map knows
		$lookup = MemberMaps::findLookupName($node);
		if ($lookup === null || $this->forbiddenMembers->getEntries($lookup) === []) {
			return false;
		}

		$access = $types->findMemberAccess($node);
		return $access !== null && $this->forbiddenMembers->has($access, $types);
	}


	/** The same for a member of the class a doc comment names. */
	private function isBannedMember(string $class, string $member, MemberKind $kind, Types $types): bool
	{
		return $this->forbiddenMembers->getEntries(strtolower($member)) !== []
			&& $this->forbiddenMembers->has(new MemberAccess($kind, $member, [$class], $types->hasMember($class, $kind, $member)), $types);
	}
}
