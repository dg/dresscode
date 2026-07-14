<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArrayItemNode;
use PhpSyntax\Nodes\Expression\ArrowFunctionNode;
use PhpSyntax\Nodes\Expression\AssignNode;
use PhpSyntax\Nodes\Expression\BinaryNode;
use PhpSyntax\Nodes\Expression\InstanceofNode;
use PhpSyntax\Nodes\MatchArmNode;
use PhpSyntax\Token;


/**
 * Spaces around binary operators: at least one on each side, or exactly one, unless the operator sits at
 * a line break. Concatenation has a rule of its own.
 */
#[RuleInfo(
	'dresscode/binary-operator-spacing',
	Stage::Formatting,
	description: 'Puts spaces around binary operators',
)]
final class BinaryOperatorSpacingRule extends Rule implements ConfigurableRule
{
	private bool $single = false;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'spacing' => Expect::anyOf('atLeastSingle', 'single')->default('atLeastSingle')
				->description('atLeastSingle keeps extra spaces that align assignments or array items, single collapses them to one'),
		]);
	}


	public function configure(array $options): void
	{
		$this->single = $options['spacing'] === 'single';
	}


	public function getVisitedTypes(): array
	{
		return [BinaryNode::class, AssignNode::class, InstanceofNode::class, ArrayItemNode::class, MatchArmNode::class, ArrowFunctionNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$operator = match (true) {
			$node instanceof BinaryNode => $node->operator->text === '.' ? null : $node->operator,
			$node instanceof AssignNode => $node->operator,
			$node instanceof InstanceofNode => $node->instanceofKeyword,
			$node instanceof ArrayItemNode => $node->doubleArrow,
			$node instanceof MatchArmNode => $node->doubleArrow,
			$node instanceof ArrowFunctionNode => $node->doubleArrow,
			default => null,
		};
		if ($operator === null) {
			return;
		}

		$this->space($operator->getPrevious(), "before the $operator->text operator", $context);
		$this->space($operator, "after the $operator->text operator", $context);
	}


	private function space(?Token $token, string $where, RuleContext $context): void
	{
		$space = $token?->getTrailingSpace();
		if ($token === null || $space === null || ($this->single ? $space === ' ' : $space !== '')) {
			return;
		}

		if ($context->report($token, ($this->single ? 'A single space ' : 'At least one space ') . $where)) {
			$token->setTrailingSpace(' ');
		}
	}
}
