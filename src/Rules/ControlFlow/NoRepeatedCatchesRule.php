<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{CatchNode, NameNode};
use PhpSyntax\Nodes\Statement\TryNode;


/**
 * Two catches standing side by side with one body and one variable are one catch of both types:
 * `catch (A $e) { log($e); } catch (B $e) { log($e); }` is `catch (A|B $e) { log($e); }`. PHP tries the catches in
 * their order, so only neighbors merge; a catch between them may take an object both of its class and of the later one.
 * The bodies are the same where their tokens are, whatever the whitespace; a comment anywhere in the catch that would go,
 * or in the parentheses of the one that stays, leaves them as they are. A class the kept catch names already, however
 * the name is written, is not written twice.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=7.1'], analyses: [NameResolver::class])]
final class NoRepeatedCatchesRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('cleanup.repeatedCatch', Domain::state('forbidden'), 'Two adjacent catches of one body, written as one `catch (A|B $e)`')];
	}


	public function getVisitedNodes(): array
	{
		return [TryNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof TryNode) {
			return;
		}

		$kept = null;
		foreach ($node->catches->getItems() as $catch) {
			if ($kept === null || !self::repeats($catch, $kept)) {
				$kept = $catch;
				continue;
			}

			$types = implode('|', array_map(fn(NameNode $type) => $type->text, $catch->types->getItems()));
			if ($context->report($catch->catchKeyword, "The catch of `$types` has the body of the one before it, which takes its types.")) {
				self::merge($catch, $kept, $context->getAnalysis(NameResolver::class));
			} else {
				$kept = $catch; // a catch whose report is refused stays, the neighbor of the next
			}
		}
	}


	/** Whether the catch has the body and the variable of the one before it, with no comment that the merge would drop. */
	private static function repeats(CatchNode $catch, CatchNode $previous): bool
	{
		return $catch->variable?->text === $previous->variable?->text
			&& $catch->body->matches($previous->body)
			&& !array_any($catch->getTokens(), fn(Token $token) => $token->hasComment())
			&& !$previous->catchKeyword->hasCommentUpTo($previous->closeParen);
	}


	/** Appends the types of the catch the kept one lacks, compared as the classes they name, and takes the catch away. */
	private static function merge(CatchNode $catch, CatchNode $kept, NameResolver $resolver): void
	{
		$resolve = fn(NameNode $type) => strtolower(ltrim($resolver->resolveClass($type), '\\'));
		$known = array_map($resolve, $kept->types->getItems());
		// the whitespace ending the list, before the variable, goes on ending it
		$end = $kept->types->getLastToken();
		$trailing = $end->trailingTrivia ?? [];
		$end?->setTrailingTrivia([]);
		foreach ($catch->types->getItems() as $type) {
			if (!in_array($resolve($type), $known, true)) {
				$kept->types->append($type->withoutEdgeTrivia(), Token::fromText('|'));
			}
		}

		$kept->types->getLastToken()?->setTrailingTrivia($trailing);

		// the kept catch ends where the removed one did, before a `finally` or the next line, unless a comment ends it
		$close = $kept->getLastToken();
		if ($close->getTrailingComments() === []) {
			$close->setTrailingTrivia($catch->getLastToken()->trailingTrivia);
			$catch->getLastToken()->setTrailingTrivia([]);
		}

		$catch->remove();
	}
}
