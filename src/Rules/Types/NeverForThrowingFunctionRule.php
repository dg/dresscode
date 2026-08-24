<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Types;

use DressCode\{ConfigurableRule, Group, NodeRule, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, Parser, Token};
use PhpSyntax\Nodes\{AnonymousClassNode, ClassLikeNode, Expression, FunctionLikeNode, Statement, StatementNode};
use PhpSyntax\Nodes\Member\MethodNode;
use function count;


/**
 * A function that always leaves by throwing or by exiting returns `never`, the type PHP 8.1 gave that, and
 * a reader and an analyser then know that the code after a call of it is unreachable.
 *
 * The body must end with a `throw` or an `exit` and hold no `return` and no `yield` of its own: with no return,
 * whatever runs before the last statement reaches it. A magic method keeps its signature, PHP prescribing it.
 *
 * A method gets the type only where nothing can be made to repeat it: `never` has no subtype, so a descendant
 * declaring the method again would have to declare `never` too, and one living in a file the run does not have
 * in hand is a fatal error. That leaves a private method, a final one and a class nothing can extend, which
 * is a final one, an enum and an anonymous one.
 *
 * A closure is left alone unless the project asks for it: what it returns is usually read off the call it is
 * written into, not declared.
 */
#[RuleInfo(
	'dresscode/never-for-throwing-function',
	Stage::Structure,
	description: 'Declares a function that always throws or exits as returning never',
	group: Group::Types,
	requires: ['php' => '>=8.1'],
)]
final class NeverForThrowingFunctionRule extends NodeRule implements ConfigurableRule
{
	private bool $closures = false;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'closures' => Expect::bool(false)
				->description('A closure is declared too, though its signature usually belongs to the call it is passed to'),
		]);
	}


	public function configure(array $options): void
	{
		$this->closures = $options['closures'];
	}


	public function getVisitedTypes(): array
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
			|| ($node instanceof MethodNode && (str_starts_with($node->name->text, '__') || self::isOverridable($node)))
			|| ($node instanceof Expression\ClosureNode && !$this->closures)
			|| !self::alwaysLeaves($node, $node->body)
			|| !$context->report($node->closeParen, 'The function that always throws must be declared as returning never')
		) {
			return;
		}

		$template = (new Parser)->parseStatement('function dressCodeTemplate(): never {}');
		assert($template instanceof Statement\FunctionNode && $template->colon !== null && $template->returnType !== null);
		[$colon, $type] = [$template->colon, $template->returnType];
		$template->colon = null;
		$template->returnType = null;
		$trailing = $node->closeParen->trailingTrivia;
		$node->closeParen->setTrailingTrivia([]);
		$node->colon = $colon;
		$node->returnType = $type;
		$type->getLastToken()?->setTrailingTrivia($trailing);
	}


	/** Whether a descendant may declare the method again, which `never`, having no subtype, would then force upon it. */
	private static function isOverridable(MethodNode $method): bool
	{
		if ($method->modifiers->isPrivate() || $method->modifiers->isFinal()) {
			return false;
		}

		$class = $method->findAncestor(ClassLikeNode::class);
		return !$class instanceof AnonymousClassNode
			&& !$class instanceof Statement\EnumNode
			&& !($class instanceof Statement\ClassNode && $class->modifiers->isFinal());
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
			...$function->find(Expression\YieldFromNode::class),
		], fn($node) => $node->findAncestor(FunctionLikeNode::class) !== $function);
	}


	/** Whether the statement throws or exits, which is how a function of the type never leaves. */
	private static function isLeaving(?StatementNode $statement): bool
	{
		$expression = $statement instanceof Statement\ExpressionStatementNode ? $statement->expression : null;
		return $expression instanceof Expression\ThrowNode || $expression instanceof Expression\ExitNode;
	}
}
