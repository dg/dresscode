<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\ConfigurableRule;
use DressCode\NodeHelpers;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ClassLikeNode;
use PhpSyntax\Nodes\ElseIfNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\BlockNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Nodes\Statement\IfNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * An `else` after branches that all end by leaving (`return`, `throw`, `break`, `continue`, `exit`, `goto`)
 * is dropped and its statements follow the `if`; an empty `else` is dropped too. An `else` declaring
 * a function or a class stays, because such declarations are hoisted. When asked, an `elseif` after such
 * an `if` becomes an `if` of its own, with the later branches; a chain of `elseif` that reads as one is
 * a matter of taste, so it stays by default.
 */
#[RuleInfo(
	'dresscode/useless-else',
	Stage::Structure,
	description: 'Removes an else after branches which always leave, and may turn an elseif there into an if',
)]
final class UselessElseRule extends Rule implements ConfigurableRule
{
	private bool $elseif = false;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'elseif' => Expect::bool(false)->description('An elseif after an if that always leaves becomes an if of its own'),
		]);
	}


	public function configure(array $options): void
	{
		$this->elseif = $options['elseif'];
	}


	public function getVisitedTypes(): array
	{
		return [IfNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof IfNode
			|| !($list = $node->parent) instanceof NodeList
			|| !$node->body instanceof BlockNode
		) {
			return;
		}

		$elseif = $node->elseifs->getItems()[0] ?? null;
		if ($elseif !== null && $this->elseif && NodeHelpers::leaves($node->body)) {
			if (
				$elseif->body instanceof BlockNode
				&& $context->report($elseif, 'Useless elseif, the branches before it always leave')
			) {
				$this->splitElseif($node, $elseif, $list, $context);
			}

			return;
		}

		if (!($else = $node->else) || !($body = $else->body) instanceof BlockNode) {
			return;
		}

		$stmts = $body->stmts->getItems();
		$empty = $stmts === [] && !$body->openBrace->hasCommentUpTo($body->closeBrace);
		$branches = [$node->body];
		foreach ($node->elseifs->getItems() as $elseif) {
			$branches[] = $elseif->body;
		}

		foreach ($branches as $branch) {
			if (!$empty && (!$branch instanceof BlockNode || !NodeHelpers::leaves($branch))) {
				return;
			}
		}

		foreach ($stmts as $stmt) {
			if ($stmt instanceof FunctionNode || $stmt instanceof ClassLikeNode) {
				return;
			}
		}

		if (!$context->report($else, $empty ? 'Empty else' : 'Useless else, the branches before it always leave')) {
			return;
		}

		$closing = $body->closeBrace->trailingTrivia;
		$index = $list->indexOf($node);
		foreach ($stmts as $stmt) {
			$body->stmts->removeItem($stmt);
			$list->insert(++$index, $stmt);
		}

		$node->setElse(null);
		$node->getLastToken()?->setTrailingTrivia($closing);
	}


	/**
	 * The elseif becomes an if statement of its own after the if, taking the later branches with it.
	 * @param NodeList<Node> $list
	 */
	private function splitElseif(IfNode $node, ElseIfNode $elseif, NodeList $list, RuleContext $context): void
	{
		$new = (new Parser)->parseStatement('if (0) {}');
		assert($new instanceof IfNode && $elseif->body !== null);
		$cond = clone $elseif->cond;
		$cond->getFirstToken()?->setLeadingTrivia([]);
		$cond->getLastToken()?->setTrailingTrivia([]);
		$body = $elseif->body;
		$elseif->setBody(null);
		$new->setCond($cond);
		$new->setBody($body);
		$node->elseifs->removeItem($elseif);
		foreach ($node->elseifs->getItems() as $later) {
			$node->elseifs->removeItem($later);
			$new->elseifs->append($later);
		}

		$else = $node->else;
		$node->setElse(null);
		$new->setElse($else);

		$style = $context->getStyle();
		$indentation = $node->getFirstToken()?->getLineIndentation() ?? '';
		$node->getLastToken()?->setTrailingTrivia([new Trivia(TriviaKind::EndOfLine, $style->eol)]);
		$new->getFirstToken()?->setLeadingTrivia($indentation === '' ? [] : [new Trivia(TriviaKind::Whitespace, $indentation)]);
		$list->insert($list->indexOf($node) + 1, $new);
	}
}
