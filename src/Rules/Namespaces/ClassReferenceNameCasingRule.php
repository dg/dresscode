<?php declare(strict_types=1);

namespace DressCode\Rules\Namespaces;

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
 * Classes and interfaces of PHP and its extensions are referenced with the case of their declaration:
 * `stdClass`, `Exception`, `Traversable`. The list comes from the runtime running the check.
 */
#[RuleInfo(
	'dresscode/class-reference-name-casing',
	Stage::Structure,
	description: 'Writes the names of internal classes and interfaces in their declared case',
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
		$canonical = self::getInternalClasses()[strtolower($resolved)] ?? null;
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


	/** @return array<string, string>  lowercased name → declared name of the classes and interfaces of the runtime */
	private static function getInternalClasses(): array
	{
		static $classes = null;
		if ($classes === null) {
			$classes = [];
			foreach ([...get_declared_classes(), ...get_declared_interfaces()] as $class) {
				if (new \ReflectionClass($class)->isInternal()) {
					$classes[strtolower($class)] = $class;
				}
			}
		}

		return $classes;
	}
}
