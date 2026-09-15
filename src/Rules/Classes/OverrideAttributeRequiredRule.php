<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\Analyses\Types;
use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AttributeGroupNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\TraitNode;
use PhpSyntax\Parser;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function in_array;


/**
 * A method that overrides one of a parent class or implements one of an interface carries `#[\Override]` of
 * PHP 8.3, which makes PHP check that it still overrides something: a parent that renames or drops the method
 * then turns a silent new method into an error.
 *
 * What the method overrides the types of the code say, so the rule runs only where the configuration gives
 * them. A constructor and a destructor are left alone, a child declaring them without regard for the parent,
 * and so is a method of a trait, which overrides whatever the class using it has.
 */
#[RuleInfo(
	'dresscode/override-attribute-required',
	Stage::Structure,
	description: 'Marks a method overriding an inherited one with #[\Override]',
	group: Group::Modernization,
	requires: ['php' => '>=8.3'],
	requiresTypes: true,
)]
final class OverrideAttributeRequiredRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [MethodNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof MethodNode
			|| in_array(strtolower($node->name->text), ['__construct', '__destruct'], true)
			|| $node->findAncestor(TraitNode::class) !== null
			|| self::isMarked($node)
		) {
			return;
		}

		$overridden = $context->getAnalysis(Types::class)->findOverridden($node);
		if (
			$overridden === null
			|| !$context->report($node->name, 'The method overriding ' . $overridden->declaringClass . '::' . $overridden->name . '() must be marked with #[\Override]')
		) {
			return;
		}

		$attribute = (new Parser)->parseFragment(AttributeGroupNode::class, '#[\Override]');
		$first = $node->getFirstToken();
		$node->attributes->append($attribute);
		if ($first !== null) {
			// the attribute takes over what stood in front of the method, the doc comment among it
			$indentation = $first->getIndentation();
			$attribute->getFirstToken()?->setLeadingTrivia($first->leadingTrivia);
			$first->setLeadingTrivia([
				new Trivia(TriviaKind::EndOfLine, $context->getStyle()->eol),
				new Trivia(TriviaKind::Whitespace, $indentation),
			]);
		}
	}


	/** Whether the method carries the attribute already, whichever way its name is written. */
	private static function isMarked(MethodNode $method): bool
	{
		return array_any(
			$method->attributes->getItems(),
			fn(Node $group) => preg_match('~(^|\W)Override\b~i', $group->text) === 1,
		);
	}
}
