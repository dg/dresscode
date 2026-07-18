<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\Member\ClassConstNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Nodes\Statement\TraitNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * Every property, method and constant of a class or trait declares its visibility (`var` becomes `public`),
 * and the modifiers come in a fixed order: abstract or final, visibility, static, readonly.
 */
#[RuleInfo(
	'dresscode/visibility-required',
	Stage::Structure,
	description: 'Requires visibility on class members and orders their modifiers',
)]
final class VisibilityRequiredRule extends Rule
{
	private const Order = [
		TokenKind::Abstract => 0, TokenKind::Final => 0,
		TokenKind::Public => 1, TokenKind::Protected => 1, TokenKind::Private => 1,
		TokenKind::PublicSet => 2, TokenKind::ProtectedSet => 2, TokenKind::PrivateSet => 2,
		TokenKind::Static => 3,
		TokenKind::Readonly => 4,
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
			$kind = $token->is(TokenKind::Var) ? TokenKind::Public : $token->kind;
			$desired[] = [$kind, $kind === TokenKind::Public && $token->is(TokenKind::Var) ? 'public' : $token->text];
			$hasVisibility = $hasVisibility || (self::Order[$kind] ?? null) === 1;
		}

		if (!$hasVisibility) {
			$desired[] = [TokenKind::Public, 'public'];
		}

		usort($desired, fn($a, $b) => (self::Order[$a[0]] ?? 9) <=> (self::Order[$b[0]] ?? 9));
		$current = array_map(fn(Token $t) => [$t->kind, $t->text], $tokens);
		if ($current === $desired) {
			return;
		}

		$message = $hasVisibility
			? 'Modifiers must be ordered: abstract or final, visibility, static, readonly'
			: 'Visibility must be declared';
		$first = $tokens[0] ?? match (true) {
			$node instanceof MethodNode => $node->functionKeyword,
			$node instanceof ClassConstNode => $node->constKeyword,
			default => $node->type?->getFirstToken() ?? $node->items->getFirstToken(),
		};
		if ($first === null || !$context->report($first, $message)) {
			return;
		}

		$leading = $first->leadingTrivia;
		$first->setLeadingTrivia([]);
		foreach ($tokens as $token) {
			$node->modifiers->removeToken($token);
		}

		foreach ($desired as $i => [$kind, $text]) {
			$token = new Token($kind, $text);
			$token->setLeadingTrivia($i === 0 ? $leading : []);
			$token->setTrailingTrivia([new Trivia(TriviaKind::Whitespace, ' ')]);
			$node->modifiers->append($token);
		}
	}
}
