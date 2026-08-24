<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Types;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Flag;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{Expression, FunctionLikeNode, Statement, StatementNode};
use PhpSyntax\Nodes\Member\MethodNode;
use function count;


/**
 * A function that always leaves by throwing or by exiting returns `never`, the type PHP 8.1 gave that, and
 * a reader and an analyser then know that the code after a call of it is unreachable.
 *
 * The body must end with a `throw` or an `exit` and hold no `return` and no `yield` of its own: with no return,
 * whatever runs before the last statement reaches it. A method whose name starts with `__` keeps its signature,
 * the names PHP keeps for the magic methods, whose signatures it prescribes.
 *
 * A method gets the type only where nothing can be made to repeat it: `never` has no subtype, so a descendant
 * declaring the method again would have to declare `never` too, and one living in a file the run does not have
 * in hand is a fatal error. That leaves a private method, a final one and a class nothing can extend, which
 * is a final one, an enum and an anonymous one.
 *
 * A closure is left alone unless `upgrading.syntax.neverReturnTypeOnClosures` asks for it: what it returns is
 * usually read off the call it is written into, not declared.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.1'])]
final class NeverForThrowingFunctionRule extends NodeRule
{
	private const Named = 'upgrading.syntax.neverReturnType';
	private const Closure = 'upgrading.syntax.neverReturnTypeOnClosures';

	private bool $closure = false;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Named, Domain::adopted(), 'The return type `never` of PHP 8.1 on a function or a method that always throws or exits, a method only where no descendant can declare it again'),
			new Decision(self::Closure, new Flag, 'Whether a closure that always throws or exits gets the return type `never` too, though its signature usually belongs to the call it is passed to', parameter: true, default: false),
		];
	}


	public function configure(Values $values): void
	{
		$this->closure = $values->get(self::Closure)->getFlag();
	}


	public function getVisitedNodes(): array
	{
		return [Statement\FunctionNode::class, MethodNode::class, Expression\ClosureNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof Statement\FunctionNode
			&& !$node instanceof MethodNode
			&& !$node instanceof Expression\ClosureNode
		) {
			return;
		}

		if (
			$node->returnType !== null
			|| $node->body === null
			|| ($node instanceof MethodNode && (str_starts_with($node->name->text, '__') || $node->isOverridable()))
			|| ($node instanceof Expression\ClosureNode && !$this->closure)
			|| !self::alwaysLeaves($node, $node->body)
			|| !$context->report($node->closeParen, match (true) {
				$node instanceof MethodNode => "The method `{$node->name->text}()`",
				$node instanceof Expression\ClosureNode => 'The closure',
				default => "The function `{$node->name->text}()`",
			} . ' never returns and must have the return type `never`.')
		) {
			return;
		}

		$node->setReturnType((new Builder)->type('never'));
	}


	/** Whether the body ends by throwing or exiting and gives the caller nothing by any other way. */
	private static function alwaysLeaves(Node $function, Statement\BlockNode $body): bool
	{
		$statements = $body->statements->getItems();
		$last = count($statements) === 0 ? null : $statements[count($statements) - 1];
		if (!self::isLeaving($last)) {
			return false;
		}

		return array_all([...$function->find(Statement\ReturnNode::class), ...$function->find(Expression\YieldNode::class),
			...$function->find(Expression\YieldFromNode::class)], fn($node) => $node->findAncestor(FunctionLikeNode::class) !== $function);
	}


	/** Whether the statement throws or exits, which is how a function of the type never leaves. */
	private static function isLeaving(?StatementNode $statement): bool
	{
		$expression = $statement instanceof Statement\ExpressionStatementNode ? $statement->expression : null;
		return $expression instanceof Expression\ThrowNode || $expression instanceof Expression\ExitNode;
	}
}
