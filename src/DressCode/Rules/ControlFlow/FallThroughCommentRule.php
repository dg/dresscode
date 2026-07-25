<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\CaseNode;
use PhpSyntax\Nodes\Expression\ExitNode;
use PhpSyntax\Nodes\Expression\ThrowNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\BlockNode;
use PhpSyntax\Nodes\Statement\BreakNode;
use PhpSyntax\Nodes\Statement\ContinueNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use PhpSyntax\Nodes\Statement\GotoNode;
use PhpSyntax\Nodes\Statement\IfNode;
use PhpSyntax\Nodes\Statement\ReturnNode;
use PhpSyntax\Nodes\Statement\TryNode;
use PhpSyntax\Nodes\StatementNode;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function count;


/**
 * A comment marking an intentional fall-through from a non-empty case into the next one, and no such
 * comment where the case cannot fall through.
 */
#[RuleInfo(
	'dresscode/fall-through-comment',
	Stage::Structure,
	description: 'Requires a comment on an intentional case fall-through',
	modifiesComments: true,
)]
final class FallThroughCommentRule extends Rule implements ConfigurableRule
{
	private string $comment = 'no break';


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'comment' => Expect::string('no break')->assert(fn($text) => trim($text) !== '', 'not empty')
				->description('Text of the fall-through comment, matched case-insensitively and inserted as a line comment'),
		]);
	}


	public function configure(array $options): void
	{
		$this->comment = $options['comment'];
	}


	public function getVisitedTypes(): array
	{
		return [CaseNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof CaseNode
			|| !($list = $node->parent) instanceof NodeList
			|| $node->stmts->isEmpty()
		) {
			return;
		}

		$items = $list->getItems();
		$next = $items[$list->indexOf($node) + 1] ?? null;
		if (!$next instanceof CaseNode) {
			return;
		}

		$stmts = $node->stmts->getItems();
		$fallsThrough = !$this->endsFlow($stmts[count($stmts) - 1]);
		[$token, $comment] = $this->findComment($node, $next);
		if ($fallsThrough && $comment === null) {
			if ($context->report($next, "An intentional fall-through must be marked with a '$this->comment' comment")) {
				$first = $next->getFirstToken();
				$first?->setLeadingTrivia([
					new Trivia(TriviaKind::Whitespace, $node->stmts->getItems()[0]->getFirstToken()?->getLineIndentation() ?? ''),
					new Trivia(TriviaKind::Comment, '// ' . $this->comment),
					new Trivia(TriviaKind::EndOfLine, $context->getStyle()->eol),
					...$first->leadingTrivia,
				]);
			}
		} elseif (!$fallsThrough && $comment !== null && $token !== null) {
			if ($context->report($token, "Useless '$this->comment' comment", trivia: $comment)) {
				$token->removeTrivia($comment);
			}
		}
	}


	/** Whether the statement always leaves the switch, so the case cannot fall through. */
	private function endsFlow(StatementNode $stmt): bool
	{
		return match (true) {
			$stmt instanceof BreakNode,
			$stmt instanceof ContinueNode,
			$stmt instanceof ReturnNode,
			$stmt instanceof GotoNode => true,
			$stmt instanceof ExpressionStatementNode => $stmt->expr instanceof ThrowNode || $stmt->expr instanceof ExitNode,
			$stmt instanceof BlockNode => !$stmt->stmts->isEmpty()
				&& $this->endsFlow($stmt->stmts->getItems()[count($stmt->stmts->getItems()) - 1]),
			$stmt instanceof IfNode => $this->ifEndsFlow($stmt),
			$stmt instanceof TryNode => $this->tryEndsFlow($stmt),
			default => false,
		};
	}


	private function ifEndsFlow(IfNode $stmt): bool
	{
		if ($stmt->else === null || ($stmt->else->body ?? $stmt->else->stmts) === null) {
			return false;
		}

		$branches = [$stmt->body ?? $stmt->stmts, $stmt->else->body ?? $stmt->else->stmts];
		foreach ($stmt->elseifs->getItems() as $elseif) {
			$branches[] = $elseif->body ?? $elseif->stmts;
		}

		foreach ($branches as $branch) {
			$last = $branch instanceof NodeList
				? ($branch->getItems()[count($branch->getItems()) - 1] ?? null)
				: $branch;
			if ($last === null || !$this->endsFlow($last)) {
				return false;
			}
		}

		return true;
	}


	private function tryEndsFlow(TryNode $stmt): bool
	{
		$finally = $stmt->finally?->body;
		if ($finally !== null && $this->endsFlow($finally)) {
			return true;
		}

		if (!$this->endsFlow($stmt->body)) {
			return false;
		}

		foreach ($stmt->catches->getItems() as $catch) {
			if (!$this->endsFlow($catch->body)) {
				return false;
			}
		}

		return true;
	}


	/**
	 * The fall-through comment between the last statement of the case and the next one, with its token.
	 * @return array{?Token, ?Trivia}
	 */
	private function findComment(CaseNode $node, CaseNode $next): array
	{
		$pattern = '~' . str_replace(' ', '\s+', preg_quote($this->comment, '~')) . '~i';
		$last = $node->getLastToken();
		$first = $next->getFirstToken();
		foreach ([[$last, $last?->trailingTrivia], [$first, $first?->leadingTrivia]] as [$token, $trivias]) {
			foreach ($trivias ?? [] as $trivia) {
				if ($trivia->isComment() && preg_match($pattern, $trivia->text) === 1) {
					return [$token, $trivia];
				}
			}
		}

		return [null, null];
	}
}
