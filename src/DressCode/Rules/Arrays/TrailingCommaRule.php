<?php declare(strict_types=1);

namespace DressCode\Rules\Arrays;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentListNode;
use PhpSyntax\Nodes\ClosureUsesNode;
use PhpSyntax\Nodes\Expression\ArrayNode;
use PhpSyntax\Nodes\Expression\ArrowFunctionNode;
use PhpSyntax\Nodes\Expression\ClosureNode;
use PhpSyntax\Nodes\Expression\ListNode;
use PhpSyntax\Nodes\Expression\MatchNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Nodes\VariadicPlaceholderNode;
use PhpSyntax\Token;
use function count, ord;


/**
 * The closing bracket decides the trailing comma: on its own line it asks for the comma, on the line
 * of the last item it forbids one, however many lines an item inside spans. `multiLine` says which
 * kinds of list get the comma; `singleLine` removes it from a list written on one line and covers
 * arrays, argument lists and `list()` whatever `multiLine` says, because there the comma is never wanted.
 */
#[RuleInfo(
	'dresscode/trailing-comma',
	Stage::Structure,
	description: 'Puts the trailing comma into a multi-line list and removes it from a one-line one',
)]
final class TrailingCommaRule extends Rule implements ConfigurableRule
{
	/** @var array<string, true> */
	private array $multiLine = ['arrays' => true];
	private bool $singleLine = true;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'multiLine' => Expect::listOf(Expect::anyOf('arrays', 'arguments', 'parameters', 'match', 'closureUses'))->default(['arrays'])
				->description('Kinds of lists that end with a trailing comma when the closing bracket is on its own line'),
			'singleLine' => Expect::bool(true)
				->description('Removes the trailing comma of an array, argument list or list() written on one line'),
		]);
	}


	public function configure(array $options): void
	{
		$this->multiLine = array_fill_keys($options['multiLine'], true);
		$this->singleLine = $options['singleLine'];
	}


	public function getVisitedTypes(): array
	{
		return [ArrayNode::class, ArgumentListNode::class, ListNode::class, FunctionNode::class, MethodNode::class, ClosureNode::class, ArrowFunctionNode::class, MatchNode::class, ClosureUsesNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		[$element, $list, $open, $close, $what] = match (true) {
			$node instanceof ArrayNode => ['arrays', $node->items, $node->openDelimiter, $node->closeDelimiter, 'array'],
			$node instanceof ArgumentListNode => ['arguments', $node->args, $node->openParen, $node->closeParen, 'argument list'],
			$node instanceof ListNode => [null, $node->items, $node->openParen, $node->closeParen, 'list()'],
			$node instanceof FunctionNode, $node instanceof MethodNode, $node instanceof ClosureNode, $node instanceof ArrowFunctionNode
				=> ['parameters', $node->params, $node->openParen, $node->closeParen, 'parameter list'],
			$node instanceof MatchNode => ['match', $node->arms, $node->openBrace, $node->closeBrace, 'match'],
			$node instanceof ClosureUsesNode => ['closureUses', $node->vars, $node->openParen, $node->closeParen, 'closure use list'],
			default => [null, null, null, null, null],
		};
		if ($list === null) {
			return;
		}

		assert($open instanceof Token && $close instanceof Token);
		$items = $list->getItems();
		$last = $items === [] ? null : $items[count($items) - 1];
		if ($last === null || $last instanceof VariadicPlaceholderNode) {
			return;
		}

		$lastToken = $last->getLastToken();
		if ($lastToken === null) {
			return;
		}

		if ($open->getLine() === $close->getLine()) {
			if (
				!$this->singleLine
				|| !($node instanceof ListNode || $element === 'arrays' || $element === 'arguments')
				|| !$list->hasTrailingSeparator()
			) {
				return;
			}

			$separators = $list->getSeparators();
			$comma = $separators[count($separators) - 1];
			if ($context->report($comma, 'No trailing comma in a one-line list')) {
				$lastToken->setTrailingTrivia([...$lastToken->trailingTrivia, ...$comma->trailingTrivia]);
				$list->setTrailingSeparator(null);
			}

			return;
		} elseif ($element === null || !isset($this->multiLine[$element])) {
			return;
		}

		if ($last->getEndLine() === $close->getLine()) {
			if (
				$list->hasTrailingSeparator()
				&& $context->report($close, "No trailing comma before a closing bracket on the line of the last item of a multi-line $what")
			) {
				$separators = $list->getSeparators();
				$comma = $separators[count($separators) - 1];
				if ($comma->getTrailingSpace() === null) { // a line ending or a comment follows the comma
					$lastToken->setTrailingTrivia([...$lastToken->trailingTrivia, ...$comma->trailingTrivia]);
				}

				$list->setTrailingSeparator(null);
			}

			return;
		}

		if (
			$list->hasTrailingSeparator()
			|| !$context->report($close, "A multi-line $what must end with a trailing comma")
		) {
			return;
		}

		$comma = new Token(ord(','), ',');
		$comma->setTrailingTrivia($lastToken->trailingTrivia);
		$lastToken->setTrailingTrivia([]);
		$list->setTrailingSeparator($comma);
	}
}
