<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\{ConfigurableRule, Group, NodeRule, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Context, Expect, Schema};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Statement\NamespaceNode;


/**
 * A tool for replacing a class across a codebase: the project maps a class, interface or enum to the one it wants
 * written instead, and the rule rewrites every reference, an import, a type, an instantiation, a static access, an
 * attribute, importing the new name the way the scope imports. The fix is not risky, because what changes is
 * exactly what the project asked for.
 */
#[RuleInfo(
	'dresscode/replaced-classes',
	Stage::Structure,
	description: 'Writes the class a project or its libraries write instead of another one',
	group: Group::Deprecations,
)]
final class ReplacedClassesRule extends NodeRule implements ConfigurableRule
{
	/** @var array<string, string>  lowercased replaced name → the name written instead, both fully qualified */
	private array $classes = [];


	public static function getOptionsSchema(): Schema
	{
		$name = fn() => Expect::string()->pattern('\\\\?\w+(\\\\\w+)*');
		return Expect::arrayOf($name(), $name())
			->description('The class → the class written instead, both fully qualified')
			->transform(function (array $options, Context $context): array {
				foreach ($options as $old => $new) {
					if (strcasecmp(ltrim((string) $old, '\\'), ltrim($new, '\\')) === 0) {
						$context->addError("The class $old is given as its own replacement.", 'dresscode.sameClass');
					}
				}

				return $options;
			});
	}


	public function configure(array $options): void
	{
		$this->classes = [];
		foreach ($options as $old => $new) {
			$this->classes[strtolower(ltrim((string) $old, '\\'))] = ltrim($new, '\\');
		}
	}


	public function getVisitedTypes(): array
	{
		return [NamespaceNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof NamespaceNode) {
			ClassReplacement::apply($node, $this->classes, $context, fn(string $old, string $new) => "Class $old is replaced by $new");
		}
	}
}
