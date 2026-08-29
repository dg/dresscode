<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Indentation;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ArrayDimFetchNode;
use PhpSyntax\Nodes\Expression\ClassConstantFetchNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\Expression\MethodCallNode;
use PhpSyntax\Nodes\Expression\PropertyFetchNode;
use PhpSyntax\Nodes\Expression\StaticCallNode;
use PhpSyntax\Nodes\Expression\StaticPropertyFetchNode;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Style;
use PhpSyntax\Token;
use PhpSyntax\TriviaKind;
use function count;


/**
 * In a chain of calls and property accesses spread over lines, every `->` or `?->` that starts a line stands
 * one level deeper than the line where the chain begins; the lines of the arguments and closures following
 * such an operator move along with it. An operator sharing a line with the previous link stays where it is.
 * With allowNesting a link may stand one level deeper than the link before it, so that a sub-chain shows it
 * acts on what that link returned, and one level shallower to come back out; the first link of the chain and
 * a step of more than one level are pulled into place.
 */
#[RuleInfo(
	'dresscode/chain-indentation',
	Stage::Formatting,
	description: 'Indents the object operators of a multi-line chain one level deeper than the start of the chain',
)]
final class ChainIndentationRule extends Rule implements ConfigurableRule
{
	private bool $allowNesting = false;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'allowNesting' => Expect::bool(false)
				->description('A link may stand one level deeper than the link before it, which is how a sub-chain shows that its calls act on what that link returned, and one level shallower to come back out'),
		]);
	}


	public function configure(array $options): void
	{
		$this->allowNesting = $options['allowNesting'];
	}


	public function getVisitedTypes(): array
	{
		return [MethodCallNode::class, PropertyFetchNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(
				!$node instanceof MethodCallNode
				&& !$node instanceof PropertyFetchNode
			)
			|| self::continues($node->parent, $node)
		) {
			return;
		}

		$operators = [];
		$root = $node;
		while (($inner = self::innerLink($root)) !== null) {
			if ($root instanceof MethodCallNode || $root instanceof PropertyFetchNode) {
				array_unshift($operators, $root->operator);
			}

			$root = $inner;
		}

		$first = $root->getFirstToken();
		$last = $node->getLastToken();
		if ($first === null || $last === null) {
			return;
		}

		$style = $context->getStyle();
		$base = Indentation::normalize($first->getLineIndentation(), $style);
		$starting = array_values(array_filter($operators, fn(Token $operator) => $operator->startsLine()));
		$previous = 0;
		foreach ($starting as $i => $operator) {
			$end = isset($starting[$i + 1]) ? $starting[$i + 1]->getPrevious() : $last;
			$level = $this->allowNesting
				? min(max(self::levelOf($operator, $base, $style), $previous - 1, 1), $previous + 1)
				: 1;
			$previous = $level;
			$indentation = $base . str_repeat($style->indent, $level);
			if (Indentation::has($operator, $indentation, $indentation)) {
				continue;
			}

			$leading = $operator->leadingTrivia;
			$at = ($leading[count($leading) - 1] ?? null)?->kind === TriviaKind::Whitespace ? $leading[count($leading) - 1] : ($leading[0] ?? null);
			if ($context->report($operator, 'Wrong indentation of a chained call', trivia: $at)) {
				Indentation::set($operator, $indentation, $indentation, $end, $style);
			}
		}
	}


	/** How many levels deeper than the line where the chain begins the operator stands, a partial one down. */
	private static function levelOf(Token $operator, string $base, Style $style): int
	{
		$unit = Indentation::width($style->indent, $style);
		$deeper = Indentation::width($operator->getIndentation(), $style) - Indentation::width($base, $style);
		return $deeper > 0 ? intdiv($deeper, $unit) : 0;
	}


	/** The expression the node is applied to when it is a link of a chain, null when the node begins one. */
	private static function innerLink(ExpressionNode $node): ?ExpressionNode
	{
		return match (true) {
			$node instanceof MethodCallNode, $node instanceof PropertyFetchNode => $node->object,
			$node instanceof ArrayDimFetchNode => $node->var,
			$node instanceof FunctionCallNode => $node->name instanceof ExpressionNode ? $node->name : null,
			$node instanceof StaticCallNode, $node instanceof StaticPropertyFetchNode, $node instanceof ClassConstantFetchNode
				=> $node->class instanceof ExpressionNode ? $node->class : null,
			default => null,
		};
	}


	/** Whether the parent is a link of the chain applied to the node. */
	private static function continues(?Node $parent, ExpressionNode $node): bool
	{
		return $parent instanceof ExpressionNode && self::innerLink($parent) === $node;
	}
}
