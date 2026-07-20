<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage, Values};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Nodes\ParameterNode;
use function count;


/**
 * The `public` of a property with a set visibility, which PHP 8.4 implies: `protected(set) int $x` is read publicly
 * as `public protected(set) int $x` is. A promoted property is one too. The `public` is written in front of the set
 * visibility, where `classes.modifierOrder` wants it; a declaration with a comment among its modifiers is only
 * reported.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.4'])]
final class PublicWithSetVisibilityRule extends NodeRule
{
	private const Path = 'classes.publicWithSetVisibility';
	private const SetVisibilities = [Token::PublicSet, Token::ProtectedSet, Token::PrivateSet];

	private bool $required = true;


	public static function getDecisions(): array
	{
		return [new Decision(self::Path, Domain::state('required', 'forbidden'), 'The `public` of a property with a set visibility, `public protected(set) int $x`, which reads the same without it')];
	}


	public function configure(Values $values): void
	{
		$this->required = $values->get(self::Path)->getWord() === 'required';
	}


	public function getVisitedNodes(): array
	{
		return [PropertyNode::class, ParameterNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof PropertyNode && !$node instanceof ParameterNode) {
			return;
		}

		$tokens = $node->modifiers->getTokens();
		$set = array_find($tokens, fn(Token $token) => in_array($token->id, self::SetVisibilities, true));
		if ($set === null) {
			return;
		}

		$visibility = $node->modifiers->getVisibilityToken();
		$name = $node instanceof ParameterNode ? $node->variable->plainName : $node->items->getItems()[0]->plainName;
		if ($this->required && $visibility === null) {
			$fixable = !$tokens[0]->hasCommentUpTo($tokens[count($tokens) - 1]);
			if (
				!$context->report($set, "The property `\$$name` must declare its visibility `public` in front of its set visibility.", fixable: $fixable)
				|| !$fixable
			) {
				return;
			}

			$texts = [];
			foreach ($tokens as $token) {
				$texts = [...$texts, ...($token === $set ? ['public', $token->text] : [$token->text])];
				$node->modifiers->removeToken($token);
			}

			foreach ($texts as $text) {
				$node->modifiers->append(Token::fromText($text));
			}

		} elseif (
			!$this->required
			&& $visibility?->is(Token::Public)
			&& $context->report($visibility, "The property `\$$name` must not declare its visibility `public`, which its set visibility implies.")
		) {
			$node->modifiers->removeToken($visibility);
		}
	}
}
