<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\Member\{ClassConstNode, MethodNode, PropertyNode};
use PhpSyntax\Nodes\Statement\{ClassNode, TraitNode};


/**
 * Every property, method and constant of a class or trait declares its visibility (`var` becomes `public`),
 * and the modifiers come in a fixed order: `abstract` or `final`, visibility, set visibility, `static`, `readonly`.
 */
#[RuleInfo(
	'dresscode/visibilityRequired',
	Stage::Structure,
	description: 'Requires visibility on class members and orders their modifiers',
)]
final class VisibilityRequiredRule extends NodeRule
{
	private const Order = [
		Token::Abstract => 0, Token::Final => 0,
		Token::Public => 1, Token::Protected => 1, Token::Private => 1,
		Token::PublicSet => 2, Token::ProtectedSet => 2, Token::PrivateSet => 2,
		Token::Static => 3,
		Token::Readonly => 4,
	];


	public function getVisitedTypes(): array
	{
		return [PropertyNode::class, MethodNode::class, ClassConstNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof PropertyNode && !$node instanceof MethodNode && !$node instanceof ClassConstNode) {
			return;
		}

		$class = $node->parent?->parent;
		if (!$class instanceof ClassNode && !$class instanceof TraitNode && !$class instanceof AnonymousClassNode) {
			return;
		}

		$tokens = $node->modifiers->getTokens();
		$desired = [];
		$hasVisibility = false;
		foreach ($tokens as $token) {
			$kind = $token->is(Token::Var) ? Token::Public : $token->id;
			$desired[] = [$kind, $token->is(Token::Var) ? 'public' : $token->text];
			$hasVisibility = $hasVisibility || (self::Order[$kind] ?? null) === 1;
		}

		if (!$hasVisibility) {
			$desired[] = [Token::Public, 'public'];
		}

		usort($desired, fn($a, $b) => (self::Order[$a[0]] ?? 9) <=> (self::Order[$b[0]] ?? 9));
		$current = array_map(fn(Token $t) => [$t->id, $t->text], $tokens);
		if ($current === $desired) {
			return;
		}

		$message = $hasVisibility
			? 'Modifiers must be ordered: `abstract` or `final`, visibility, `static`, `readonly`'
			: 'Visibility must be declared';
		$first = $tokens[0] ?? match (true) {
			$node instanceof MethodNode => $node->functionKeyword,
			$node instanceof ClassConstNode => $node->constKeyword,
			default => $node->type?->getFirstToken() ?? $node->items->getFirstToken(),
		};
		if ($first === null || !$context->report($first, $message)) {
			return;
		}

		foreach ($tokens as $token) {
			$node->modifiers->removeToken($token);
		}

		foreach ($desired as [$kind, $text]) {
			$node->modifiers->append(new Token($kind, $text));
		}
	}
}
