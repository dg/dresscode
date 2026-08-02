<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Nodes\Statement\InterfaceNode;
use PhpSyntax\Nodes\Statement\TraitNode;
use PhpSyntax\Token;
use function strlen;


/**
 * The kind of a type is not repeated in its name: no `AbstractFoo` or `FooAbstract` for an abstract class,
 * no `FooInterface`, `FooTrait`, nor `FooError` for a plain class. Reported.
 */
#[RuleInfo(
	'dresscode/no-kind-in-class-name',
	Stage::Structure,
	description: 'Reports a class, interface or trait name repeating its kind',
)]
final class NoKindInClassNameRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [ClassNode::class, InterfaceNode::class, TraitNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		[$name, $words] = match (true) {
			$node instanceof ClassNode => [$node->name, $node->modifiers->isAbstract() ? ['Abstract'] : []],
			$node instanceof InterfaceNode => [$node->name, ['Interface']],
			$node instanceof TraitNode => [$node->name, ['Trait']],
			default => [null, []],
		};
		if ($name === null) {
			return;
		}

		$text = $name->token->text;
		foreach ($words as $word) {
			$length = strlen($word);
			// a prefix only where the name breaks after it: TraitsAware says what it knows, not what it is
			if (
				strlen($text) > $length
				&& strcasecmp(substr($text, 0, $length), $word) === 0
				&& preg_match('~^[A-Z0-9]~', substr($text, $length)) === 1
			) {
				$context->report($name, "Useless prefix '" . substr($text, 0, $length) . "' in the class name");
			}

			if (strlen($text) > $length && strcasecmp(substr($text, -$length), $word) === 0) {
				$context->report($name, "Useless suffix '" . substr($text, -$length) . "' in the class name");
			}
		}

		if (
			$node instanceof ClassNode
			&& !$node->modifiers->isAbstract()
			&& strlen($text) > 5
			&& strcasecmp(substr($text, -5), 'Error') === 0
		) {
			$context->report($name, "Useless suffix '" . substr($text, -5) . "' in the class name");
		}
	}
}
