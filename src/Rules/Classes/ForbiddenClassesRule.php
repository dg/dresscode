<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\ConfigurableRule;
use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\UseItemNode;
use PhpSyntax\SymbolKind;
use PhpSyntax\Token;


/**
 * References of the classes, interfaces and enums a project, or a library it stands on, says its code must not have,
 * each reported with what the map says to do instead, wherever the name stands, a type, an instantiation, a static
 * access, an attribute; nothing is rewritten, which is what the map is for where no other class could simply stand
 * for the one. An import is not reported: it names nothing once the references are gone, and goes with them.
 */
#[RuleInfo(
	'dresscode/forbidden-classes',
	Stage::Structure,
	description: 'Reports references of the configured classes with what to do instead',
	group: Group::Deprecations,
)]
final class ForbiddenClassesRule extends NodeRule implements ConfigurableRule
{
	/** @var array<string, array{string, string}>  lowercased class → the class as the map spells it and what to do instead */
	private array $classes = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::arrayOf(Expect::string(), Expect::string()->pattern('\\\\?\w+(\\\\\w+)*'))
			->description('The forbidden class, fully qualified → what to do instead, as the end of the message');
	}


	public function configure(array $options): void
	{
		$this->classes = [];
		foreach ($options as $class => $message) {
			if ($message !== MemberMaps::Keep) { // an entry a later layer withdrew
				$this->classes[strtolower(ltrim((string) $class, '\\'))] = [ltrim((string) $class, '\\'), $message];
			}
		}
	}


	public function getVisitedTypes(): array
	{
		return [NameNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			$this->classes === []
			|| !$node instanceof NameNode
			|| $node->parent instanceof UseItemNode
			|| $node->role !== SymbolKind::ClassLike
			|| !$node->isReference()
		) {
			return;
		}

		$entry = $this->classes[strtolower($context->getAnalysis(NameResolver::class)->resolveClass($node))] ?? null;
		if ($entry !== null) {
			$context->report($node, "Class $entry[0] is forbidden: $entry[1]", fixable: false);
		}
	}


	/** Whether the map has the class, fully qualified, which is what a rule reading the deprecations asks to stay silent. */
	public function knows(string $class): bool
	{
		return isset($this->classes[strtolower($class)]);
	}
}
