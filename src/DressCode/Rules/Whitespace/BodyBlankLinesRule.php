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
use PhpSyntax\Nodes\Expression\MatchNode;
use PhpSyntax\Nodes\Statement\BlockNode;
use PhpSyntax\Nodes\Statement\SwitchNode;
use PhpSyntax\Token;
use PhpSyntax\TriviaKind;


/**
 * No blank line after the opening brace of a block, be it the body of a function, of a control structure,
 * a switch or a match; the class braces belong to dresscode/declaration-blank-lines. The closing brace keeps
 * its blank line unless asked otherwise, because such a line separates the branches of `} catch` and
 * `} else` chains.
 */
#[RuleInfo(
	'dresscode/body-blank-lines',
	Stage::Cleanup,
	description: 'Removes the blank line after the opening brace of a block',
)]
final class BodyBlankLinesRule extends Rule implements ConfigurableRule
{
	private bool $beforeClosingBrace = false;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'beforeClosingBrace' => Expect::bool(false)->description('The blank line before the closing brace goes too'),
		]);
	}


	public function configure(array $options): void
	{
		$this->beforeClosingBrace = $options['beforeClosingBrace'];
	}


	public function getVisitedTypes(): array
	{
		return [BlockNode::class, SwitchNode::class, MatchNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		[$open, $close] = match (true) {
			$node instanceof BlockNode => [$node->openBrace, $node->closeBrace],
			$node instanceof SwitchNode => [$node->openBrace, $node->closeBrace],
			$node instanceof MatchNode => [$node->openBrace, $node->closeBrace],
			default => [null, null],
		};
		if ($open === null) {
			return;
		}

		$this->trimBlankLinesBefore($open->getNext(), 'after the opening brace', $context);
		if ($this->beforeClosingBrace && $close !== null && $close !== $open->getNext()) {
			$this->trimBlankLinesBefore($close, 'before the closing brace', $context);
		}
	}


	private function trimBlankLinesBefore(?Token $token, string $place, RuleContext $context): void
	{
		if ($token === null || !$token->startsLine()) {
			return;
		}

		$extra = $token->leadingTrivia[0] ?? null;
		if (
			$extra?->kind === TriviaKind::EndOfLine
			&& $context->report($token, BlankLines::describe(0, $place, BlankLines::countBefore($token)), trivia: $extra)
		) {
			$token->setBlankLinesBefore(0);
		}
	}
}
