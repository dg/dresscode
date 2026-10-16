<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values, Violation};
use DressCode\Domains\Text;
use PhpSyntax\{Node, Nodes, Token, Trivia};
use function count;


/**
 * A comment marking an intentional fall-through from a non-empty case into the next one, and no such
 * comment where the case cannot fall through. Whether a fall-through is intended only the author knows, so a missing
 * marker is reported and left to them.
 */
#[RuleInfo(Stage::Structure, modifiesComments: true)]
final class FallThroughCommentRule extends NodeRule
{
	private const Comment = 'controlFlow.switch.fallThroughComment';

	/** @var list<string>  the texts of the marker that pass, the first named in a message */
	private array $comments = ['no break'];


	public static function getDecisions(): array
	{
		return [
			new Decision(
				self::Comment,
				new Text(tolerance: true),
				'The text of the comment a non-empty `case` falling through to the next one carries, without the marker of the comment, matched case-insensitively and reported only where none is found; a case that cannot fall through loses it',
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->comments = $values->get(self::Comment)->getTexts();
	}


	public function getVisitedNodes(): array
	{
		return [Nodes\CaseNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof Nodes\CaseNode
			|| !$node->parent instanceof Nodes\PlainNodeList
			|| $node->statements->isEmpty()
		) {
			return;
		}

		$next = $node->getNextSibling();
		if (!$next instanceof Nodes\CaseNode) {
			return;
		}

		$stmts = $node->statements->getItems();
		$fallsThrough = !$stmts[count($stmts) - 1]->alwaysLeaves();
		// a comment saying more than the marker marks the case, but is not removed where the case cannot fall through
		[$token, $comment] = $this->findComment($node, $next, exact: !$fallsThrough);
		if ($fallsThrough && $comment === null) {
			$context->report(
				$stmts[count($stmts) - 1],
				'A case falling through to the next one must end with `break` or be marked with a '
				. Violation::formatCode($this->comments[0])
				. ' comment.',
				fixable: false,
			);
		} elseif (!$fallsThrough && $comment !== null && $token !== null) {
			if ($context->report(
				$token,
				'Useless ' . Violation::formatCode($comment->getCommentText()) . ' comment, because the case cannot fall through.',
				trivia: $comment,
			)) {
				$token->removeTrivia($comment);
			}
		}
	}


	/**
	 * The fall-through comment between the last statement of the case and the next one, with its token: one that
	 * is exactly the marker, or one that holds its words.
	 * @return array{?Token, ?Trivia}
	 */
	private function findComment(Nodes\CaseNode $node, Nodes\CaseNode $next, bool $exact): array
	{
		$words = implode('|', array_map(fn(string $text) => str_replace(' ', '\s+', preg_quote($text, '~')), $this->comments));
		$pattern = $exact ? '~^(?:' . $words . ')$~i' : '~(?<!\w)(?:' . $words . ')(?!\w)~i';
		$last = $node->getLastToken();
		$first = $next->getFirstToken();
		foreach ([[$last, $last->trailingTrivia], [$first, $first->leadingTrivia]] as [$token, $trivias]) {
			foreach ($trivias as $trivia) {
				if ($trivia->isComment() && preg_match($pattern, $trivia->getCommentText()) === 1) {
					return [$token, $trivia];
				}
			}
		}

		return [null, null];
	}
}
