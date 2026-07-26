<?php declare(strict_types=1);

namespace DressCode\Rules\Namespaces;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\NameKind;
use PhpSyntax\NameRole;
use PhpSyntax\Node;
use PhpSyntax\Nodes\NameNode;
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
final class ClassReferenceNameCasingRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [NameNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof NameNode || $node->getRole() !== NameRole::ClassLike) {
			return;
		}

		$resolved = $context->getAnalysis(NameResolver::class)->resolveClass($node);
		$canonical = self::getInternalClasses()[strtolower($resolved)] ?? null;
		if (
			$canonical === null
			|| $resolved === $canonical
			|| $resolved !== implode('\\', $node->getParts()) // an alias or a namespace resolves elsewhere
			|| !$context->report($node, "The class name must be written '$canonical'")
		) {
			return;
		}

		$old = $node->token;
		$token = new Token($old->kind, ($node->getKind() === NameKind::FullyQualified ? '\\' : '') . $canonical);
		$token->setLeadingTrivia($old->leadingTrivia);
		$token->setTrailingTrivia($old->trailingTrivia);
		$node->setToken($token);
	}


	/** @return array<string, string>  lowercased name → declared name of the classes and interfaces of the runtime */
	private static function getInternalClasses(): array
	{
		static $classes = null;
		if ($classes === null) {
			$classes = [];
			foreach ([...get_declared_classes(), ...get_declared_interfaces()] as $class) {
				if ((new \ReflectionClass($class))->isInternal()) {
					$classes[strtolower($class)] = $class;
				}
			}
		}

		return $classes;
	}
}
