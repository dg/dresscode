<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * A class whose every property is readonly is a readonly class, which says it once instead of on every
 * property; dresscode/useless-modifier then takes the modifiers the class now implies. The class must have
 * a property, because a readonly class with none says nothing, and none of them may be static or hooked,
 * neither of which a readonly class can declare.
 *
 * Only a final class is marked, and only one that extends nothing. A readonly class may extend and be
 * extended by readonly classes alone, and what the parent is, or whether anything extends the class, the
 * file does not say; a class that turns out to have either is not a changed behaviour but a fatal error,
 * which no consent to a risky fix covers.
 */
#[RuleInfo(
	'dresscode/readonly-class-for-readonly-members',
	Stage::Structure,
	description: 'Marks a class whose every property is readonly as readonly',
	requires: ['php' => '>=8.2'],
)]
final class ReadonlyClassForReadonlyMembersRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [ClassNode::class, AnonymousClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof ClassNode && !$node instanceof AnonymousClassNode)
			|| $node->modifiers->isReadonly()
			|| $node->extends !== null
			|| !($node instanceof AnonymousClassNode || $node->modifiers->isFinal())
			|| ($node instanceof AnonymousClassNode && version_compare($context->getPhpVersion(), '8.3', '<'))
		) {
			return;
		}

		$properties = 0;
		foreach ($node->members as $member) {
			if ($member instanceof PropertyNode) {
				if (!$member->modifiers->isReadonly() || $member->modifiers->isStatic() || $member->hooks !== null) {
					return;
				}

				$properties++;
			} elseif ($member instanceof MethodNode && $member->isConstructor()) {
				foreach ($member->parameters->getItems() as $parameter) {
					if ($parameter->isPromoted()) {
						if (!$parameter->modifiers->isReadonly() || $parameter->hooks !== null) {
							return;
						}

						$properties++;
					}
				}
			}
		}

		if (
			$properties === 0
			|| !$context->report($node->classKeyword, 'The class whose every property is readonly must be readonly itself')
		) {
			return;
		}

		$token = new Token(TokenKind::Readonly, 'readonly');
		if ($node->modifiers->isEmpty()) { // the modifier becomes the first token and takes over its place
			$token->setLeadingTrivia($node->classKeyword->leadingTrivia);
			$node->classKeyword->setLeadingTrivia([]);
		}

		$token->setTrailingTrivia([new Trivia(TriviaKind::Whitespace, ' ')]);
		$node->modifiers->append($token);
	}
}
