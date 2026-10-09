<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values, Violation};
use DressCode\Domains\Words;
use PhpSyntax\{Builder, Node, Token, Trivia};
use PhpSyntax\Nodes\{AnonymousClassNode, AttributeNode};
use PhpSyntax\Nodes\Expression\NewNode;
use function count;


/**
 * Empty parentheses where arguments may be left out, required, forbidden or left alone for a named class, an
 * anonymous class and an attribute each; PER wants them on `new Foo()` and not on `new class` or `#[Foo]`.
 * `new Foo()->bar()` keeps them whatever the decisions say, PHP 8.4 needs them there.
 */
#[RuleInfo(Stage::Structure)]
final class EmptyArgumentParenthesesRule extends NodeRule
{
	private const NamedClass = 'classes.emptyParentheses.instantiation';
	private const AnonymousClass = 'classes.emptyParentheses.anonymousClass';
	private const Attribute = 'classes.emptyParentheses.attribute';

	private ?string $namedClass = null;
	private ?string $anonymousClass = null;
	private ?string $attribute = null;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::NamedClass, new Words(['required' => '`new Foo()`', 'forbidden' => '`new Foo`']), 'The empty parentheses of `new` with a class, `new static` and `new $class` alike, which `new Foo()->bar()` keeps whatever this says, PHP 8.4 needing them there'),
			new Decision(self::AnonymousClass, new Words(['required' => '`new class()`', 'forbidden' => '`new class`']), 'The empty parentheses of an anonymous class'),
			new Decision(self::Attribute, new Words(['required' => '`#[Foo()]`', 'forbidden' => '`#[Foo]`']), 'The empty parentheses of an attribute'),
		];
	}


	public function configure(Values $values): void
	{
		$this->namedClass = $values->find(self::NamedClass)?->getWord();
		$this->anonymousClass = $values->find(self::AnonymousClass)?->getWord();
		$this->attribute = $values->find(self::Attribute)?->getWord();
	}


	public function getVisitedNodes(): array
	{
		return [NewNode::class, AnonymousClassNode::class, AttributeNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof NewNode && !$node->class instanceof AnonymousClassNode) {
			[$wanted, $decision] = [$this->namedClass, self::NamedClass];
			$before = $node->class->getLastToken();
		} elseif ($node instanceof AnonymousClassNode) {
			[$wanted, $decision] = [$this->anonymousClass, self::AnonymousClass];
			$before = $node->classKeyword;
		} elseif ($node instanceof AttributeNode) {
			[$wanted, $decision] = [$this->attribute, self::Attribute];
			$before = $node->name->getLastToken();
		} else {
			return;
		}

		if ($wanted === 'required') {
			$this->addParentheses($node, $before, $decision, $context);
		} elseif ($wanted === 'forbidden') {
			$this->removeParentheses($node, $decision, $context);
		}
	}


	private function addParentheses(
		NewNode|AnonymousClassNode|AttributeNode $node,
		?Token $before,
		string $decision,
		RuleContext $context,
	): void
	{
		if (
			$node->arguments !== null
			|| $before === null
			|| !$context->report($node, 'Expected `()` after ' . self::describe($node) . '.', decision: $decision)
		) {
			return;
		}

		$args = (new Builder)->arguments([]);
		$args->closeParen->setTrailingTrivia($before->trailingTrivia);
		$before->setTrailingTrivia([]);
		$node->arguments = $args;
	}


	private function removeParentheses(NewNode|AnonymousClassNode|AttributeNode $node, string $decision, RuleContext $context): void
	{
		if (
			($args = $node->arguments) === null
			|| !$args->items->isEmpty()
			|| ($node instanceof NewNode && $node->isDereferenced()) // new Foo()->bar() needs them since PHP 8.4
			|| $args->openParen->hasComment()
			|| $args->closeParen->hasComment()
			|| !$context->report($args, 'Expected no `()` after ' . self::describe($node) . '.', decision: $decision)
		) {
			return;
		}

		$before = $args->openParen->getPrevious();
		$trailing = $args->closeParen->trailingTrivia;
		$node->arguments = null;
		$onlyWhitespace = true;
		foreach ($trailing as $trivia) {
			$onlyWhitespace = $onlyWhitespace && $trivia->is(Trivia::Whitespace);
		}

		$beforeTrailing = $before->trailingTrivia ?? [];
		$beforeEndsWithSpace = $beforeTrailing !== []
			&& $beforeTrailing[count($beforeTrailing) - 1]->is(Trivia::Whitespace);
		if ($before !== null && $trailing !== [] && !($onlyWhitespace && $beforeEndsWithSpace)) {
			$before->setTrailingTrivia([...$beforeTrailing, ...$trailing]);
		}
	}


	/** `new Foo`, `new class` or the attribute `Foo`, as a message names it. */
	private static function describe(NewNode|AnonymousClassNode|AttributeNode $node): string
	{
		return match (true) {
			$node instanceof AttributeNode => "the attribute `{$node->name->text}`",
			$node instanceof AnonymousClassNode => '`new class`',
			default => Violation::formatCode('new ' . $node->class->text),
		};
	}
}
