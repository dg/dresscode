<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{MemberAccess, MemberKind, Types};
use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\CodeWriter;
use Nette\Schema\{Context, Expect, Schema};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\{ClassConstantFetchNode, StaticMethodCallNode, StaticPropertyFetchNode};
use PhpSyntax\Nodes\{FileNode, NameNode};
use PhpSyntax\Nodes\Statement\NamespaceNode;


/**
 * A tool for replacing a class across a codebase: the project, or a library it stands on, maps a class, interface
 * or enum to the one it wants written instead, and the rule rewrites every reference, an import, a type, an
 * instantiation, a static access, an attribute, a type or a reference in a doc comment, importing the new name the
 * way the scope imports; a class a comment only mentions in its text is left as it is. The fix is not
 * risky, because what changes is exactly what the map asked for. Where the run has the types of the code, a class the project does not have
 * is reported and not written.
 *
 * Where the run has the types, the class of an access to a member forbiddenMembers names stays as it is, its import with it: such a member has
 * no place in the class written instead, so writing that class there would name a member it never had. The ban is
 * the only thing said of the access; every other reference of the class is rewritten as usual.
 */
#[RuleInfo(
	'dresscode/replacedClasses',
	Stage::Structure,
	description: 'Writes the class a project or its libraries write instead of another one',
	modifiesComments: true,
)]
final class ReplacedClassesRule extends NodeRule implements ConfigurableRule
{
	/** @var array<string, string>  lowercased replaced name => the name written instead, both fully qualified */
	private array $classes = [];


	public static function getOptionsSchema(): Schema
	{
		$name = fn() => Expect::string()->pattern('\\\\?\w+(\\\\\w+)*');
		return Expect::arrayOf($name(), $name())
			->description('The class → the class written instead, both fully qualified')
			->transform(function (array $options, Context $context): array {
				foreach ($options as $old => $new) {
					if (strcasecmp(ltrim((string) $old, '\\'), ltrim($new, '\\')) === 0) {
						$context->addError("The class `$old` is given as its own replacement.", 'dresscode.sameClass');
					}
				}

				return $options;
			});
	}


	public function configure(array $options): void
	{
		$this->classes = [];
		foreach ($options as $old => $new) {
			if ($new !== MemberMaps::Keep) { // an entry a later layer withdrew
				$this->classes[strtolower(ltrim((string) $old, '\\'))] = ltrim($new, '\\');
			}
		}
	}


	public function getVisitedTypes(): array
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
			ClassReplacement::apply(
				$node,
				$context,
				$find,
				$types === null ? null : fn(NameNode $name) => self::isBannedAccess($name, $types, $context),
				fn(string $class, ?array $member) => $types !== null && $member !== null && self::isBannedMember($class, ...$member, types: $types, context: $context)
					? false
					: $find($class),
			);
		}
	}


	/**
	 * Whether the name is the class of an access to a member forbiddenMembers names.
	 */
	private static function isBannedAccess(NameNode $name, Types $types, RuleContext $context): bool
	{
		$node = $name->parent;
		$forbidden = $context->findRule(ForbiddenMembersRule::class);
		if (
			$forbidden === null
			|| !($node instanceof ClassConstantFetchNode || $node instanceof StaticMethodCallNode || $node instanceof StaticPropertyFetchNode)
			|| $node->class !== $name
		) {
			return false;
		}

		$access = $types->findMemberAccess($node);
		return $access !== null && $forbidden->hasMember($access, $types);
	}


	/** The same for a member of the class a doc comment names. */
	private static function isBannedMember(string $class, string $member, MemberKind $kind, Types $types, RuleContext $context): bool
	{
		$access = new MemberAccess($kind, $member, [$class], $types->hasMember($class, $kind, $member));
		return $context->findRule(ForbiddenMembersRule::class)?->hasMember($access, $types) === true;
	}


	/**
	 * Whether the map has the class, fully qualified, which is what a rule reading the deprecations asks to stay silent.
	 * @internal
	 */
	public function hasClass(string $class): bool
	{
		return isset($this->classes[strtolower($class)]);
	}
}
