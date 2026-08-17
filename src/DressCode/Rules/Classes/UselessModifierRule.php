<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\Scope;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\ClassLikeNode;
use PhpSyntax\Nodes\Member\ClassConstNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Nodes\ModifiersNode;
use PhpSyntax\Nodes\ParameterNode;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Nodes\Statement\EnumNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;


/**
 * A modifier the enclosing class already implies is dropped: `final` on a method or a constant of a final
 * class or of an enum, `readonly` on a property or a promoted parameter of a readonly class.
 */
#[RuleInfo(
	'dresscode/useless-modifier',
	Stage::Structure,
	description: 'Removes a member modifier the class already implies',
)]
final class UselessModifierRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [MethodNode::class, ClassConstNode::class, PropertyNode::class, ParameterNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$class = $context->getAnalysis(Scope::class)->getClass($node);
		if ($node instanceof MethodNode || $node instanceof ClassConstNode) {
			[$kind, $message] = match (true) {
				$class instanceof ClassNode && $class->modifiers->has(TokenKind::Final) => [TokenKind::Final, 'Useless final modifier in a final class'],
				$class instanceof EnumNode => [TokenKind::Final, 'Useless final modifier in an enum'],
				default => [null, null],
			};
		} elseif ($node instanceof PropertyNode || $node instanceof ParameterNode) {
			[$kind, $message] = self::isReadonlyClass($class)
				? [TokenKind::Readonly, 'Useless readonly modifier in a readonly class']
				: [null, null];
		} else {
			return;
		}

		if ($kind === null || $message === null) {
			return;
		}

		foreach ($node->modifiers->getTokens() as $token) {
			if ($token->is($kind) && $context->report($token, $message)) {
				self::removeModifier($node->modifiers, $token);
			}
		}
	}


	private static function isReadonlyClass(?ClassLikeNode $class): bool
	{
		return ($class instanceof ClassNode || $class instanceof AnonymousClassNode)
			&& $class->modifiers->has(TokenKind::Readonly);
	}


	/** The first modifier hands its leading trivia over to what follows it, a later one goes with its trailing space. */
	private static function removeModifier(ModifiersNode $modifiers, Token $token): void
	{
		if ($modifiers->getTokens()[0] === $token) {
			$next = $token->getNext();
			$next?->setLeadingTrivia([...$token->leadingTrivia, ...$next->leadingTrivia]);
		}

		$modifiers->removeToken($token);
	}
}
