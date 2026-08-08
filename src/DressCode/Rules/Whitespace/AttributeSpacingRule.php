<?php declare(strict_types=1);

namespace DressCode\Rules\Whitespace;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AttributeGroupNode;
use PhpSyntax\Nodes\AttributeNode;
use PhpSyntax\Token;


/**
 * `#[Foo]` hugs its brackets and drops empty parentheses: no whitespace after `#[` or before `]` on the
 * same line, and `Foo()` without arguments becomes `Foo`. A group spanning lines is left to its author;
 * a group following another one on its line may be asked to take a line of its own.
 */
#[RuleInfo(
	'dresscode/attribute-spacing',
	Stage::Formatting,
	description: 'Removes whitespace inside the brackets of an attribute group and the empty parentheses of an attribute',
)]
final class AttributeSpacingRule extends Rule implements ConfigurableRule
{
	private bool $groupPerLine = false;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'groupPerLine' => Expect::bool(false)->description('A #[...] group following another one on its line moves to a line of its own'),
		]);
	}


	public function configure(array $options): void
	{
		$this->groupPerLine = $options['groupPerLine'];
	}


	public function getVisitedTypes(): array
	{
		return [AttributeGroupNode::class, AttributeNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof AttributeGroupNode) {
			$open = $node->openAttribute;
			$previous = $open->getPrevious();
			if (
				$this->groupPerLine
				&& !$open->startsLine()
				&& $previous?->parent instanceof AttributeGroupNode
				&& $previous->parent !== $node
				&& $context->report($open, 'An attribute group following another one must be on a line of its own')
			) {
				$indentation = $previous->getLineIndentation();
				$open->ensureLeadingNewline($context->getStyle()->eol);
				$open->setIndentation($indentation);
			}

			if (
				$open->getTrailingSpace() !== ''
				&& $open->getTrailingSpace() !== null
				&& $context->report($open, 'No whitespace after #[')
			) {
				$open->setTrailingSpace('');
			}

			$before = $node->closeBracket->getPrevious();
			if (
				$before !== null
				&& $before->getTrailingSpace() !== ''
				&& $before->getTrailingSpace() !== null
				&& $context->report($node->closeBracket, 'No whitespace before ]')
			) {
				$before->setTrailingSpace('');
			}
		} elseif ($node instanceof AttributeNode) {
			$args = $node->args;
			$name = $node->name->getLastToken();
			if (
				$args === null
				|| $name === null
				|| !$args->args->isEmpty()
				|| $args->openParen->hasCommentUpTo($args->closeParen)
				|| !$context->report($args, 'No empty parentheses after an attribute name')
			) {
				return;
			}

			$name->setTrailingTrivia([...$name->trailingTrivia, ...$args->closeParen->trailingTrivia]);
			$node->setArgs(null);
		}
	}
}
