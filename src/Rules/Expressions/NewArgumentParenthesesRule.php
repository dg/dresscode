<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, Parser, Token, TriviaKind};
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\Expression\NewNode;
use function count;


/**
 * Empty parentheses of an instantiation without arguments, required, forbidden or left alone for a named
 * and an anonymous class each; PER wants them on `new Foo()` and not on `new class`. `new Foo()->bar()`
 * keeps them whatever the options say, PHP 8.4 needs them there.
 */
#[RuleInfo(
	'dresscode/new-argument-parentheses',
	Stage::Structure,
	description: 'Requires or forbids the empty parentheses of an instantiation',
)]
final class NewArgumentParenthesesRule extends NodeRule implements ConfigurableRule
{
	private ?string $namedClasses = 'required';
	private ?string $anonymousClasses = 'required';


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'namedClasses' => Expect::anyOf('required', 'forbidden', 'keep')->default('required'),
			'anonymousClasses' => Expect::anyOf('required', 'forbidden', 'keep')->default('required'),
		]);
	}


	public function configure(array $options): void
	{
		$this->namedClasses = $options['namedClasses'] === 'keep' ? null : $options['namedClasses'];
		$this->anonymousClasses = $options['anonymousClasses'] === 'keep' ? null : $options['anonymousClasses'];
	}


	public function getVisitedTypes(): array
	{
		return [NewNode::class, AnonymousClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof NewNode && !$node->class instanceof AnonymousClassNode) {
			$wanted = $this->namedClasses;
			$before = $node->class->getLastToken();
		} elseif ($node instanceof AnonymousClassNode) {
			$wanted = $this->anonymousClasses;
			$before = $node->classKeyword;
		} else {
			return;
		}

		if ($wanted === 'required') {
			$this->addParentheses($node, $before, $context);
		} elseif ($wanted === 'forbidden') {
			$this->removeParentheses($node, $context);
		}
	}


	private function addParentheses(NewNode|AnonymousClassNode $node, ?Token $before, RuleContext $context): void
	{
		if (
			$node->arguments !== null
			|| $before === null
			|| !$context->report($node, 'Missing parentheses after new')
		) {
			return;
		}

		$template = (new Parser)->parseExpression('new Foo()');
		assert($template instanceof NewNode && $template->arguments !== null);
		$args = $template->arguments;
		$template->arguments = null;
		$args->closeParen->setTrailingTrivia($before->trailingTrivia);
		$before->setTrailingTrivia([]);
		$node->arguments = $args;
	}


	private function removeParentheses(NewNode|AnonymousClassNode $node, RuleContext $context): void
	{
		if (
			($args = $node->arguments) === null
			|| !$args->items->isEmpty()
			|| ($node instanceof NewNode && $node->isDereferenced()) // new Foo()->bar() needs them since PHP 8.4
			|| $args->openParen->hasComment()
			|| $args->closeParen->hasComment()
			|| !$context->report($args, 'Empty parentheses after new')
		) {
			return;
		}

		$before = $args->openParen->getPrevious();
		$trailing = $args->closeParen->trailingTrivia;
		$node->arguments = null;
		$onlyWhitespace = true;
		foreach ($trailing as $trivia) {
			$onlyWhitespace = $onlyWhitespace && $trivia->kind === TriviaKind::Whitespace;
		}

		$beforeTrailing = $before->trailingTrivia ?? [];
		$beforeEndsWithSpace = $beforeTrailing !== []
			&& $beforeTrailing[count($beforeTrailing) - 1]->kind === TriviaKind::Whitespace;
		if ($before !== null && $trailing !== [] && !($onlyWhitespace && $beforeEndsWithSpace)) {
			$before->setTrailingTrivia([...$beforeTrailing, ...$trailing]);
		}
	}
}
