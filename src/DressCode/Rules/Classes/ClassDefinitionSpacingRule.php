<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\ClassLikeNode;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Nodes\Statement\EnumNode;
use PhpSyntax\Nodes\Statement\InterfaceNode;
use PhpSyntax\Nodes\Statement\TraitNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;


/**
 * The head of a class declaration with single spaces between its words, up to the opening brace:
 * `final class Foo extends Bar implements Baz`, `enum E: string`, `new class ($a) extends Bar` (or `new class($a)`
 * without spaceBeforeParenthesis).
 */
#[RuleInfo(
	'dresscode/class-definition-spacing',
	Stage::Formatting,
	description: 'Puts single spaces in the head of a class declaration',
)]
final class ClassDefinitionSpacingRule extends Rule implements ConfigurableRule
{
	private bool $spaceBeforeParenthesis = true;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'spaceBeforeParenthesis' => Expect::bool(true)->description('Between class and the arguments of an anonymous class: new class ($a), false hugs them'),
		]);
	}


	public function configure(array $options): void
	{
		$this->spaceBeforeParenthesis = $options['spaceBeforeParenthesis'];
	}


	public function getVisitedTypes(): array
	{
		return [ClassLikeNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ClassNode
			&& !$node instanceof InterfaceNode
			&& !$node instanceof TraitNode
			&& !$node instanceof EnumNode
			&& !$node instanceof AnonymousClassNode
		) {
			return;
		}

		$start = match (true) {
			$node instanceof ClassNode => $node->modifiers->getTokens()[0] ?? $node->classKeyword,
			$node instanceof InterfaceNode => $node->interfaceKeyword,
			$node instanceof TraitNode => $node->traitKeyword,
			$node instanceof EnumNode => $node->enumKeyword,
			default => $node->classKeyword->getPrevious()?->is(TokenKind::New) ? $node->classKeyword->getPrevious() : $node->classKeyword,
		};

		for ($token = $start; $token !== null && $token !== $node->openBrace; $token = $token->getNext()) {
			if ($node instanceof AnonymousClassNode && $token === $node->args?->openParen) {
				$token = $node->args->closeParen; // the arguments have spacing rules of their own
			}

			$next = $token->getNext();
			if ($next === null) {
				break;
			}

			$expected = match (true) {
				$token->is('(', ':') => $token->is('(') ? '' : ' ',
				$next->is(',', ')', ':') => '',
				$next->is('(') => $node instanceof AnonymousClassNode && $this->spaceBeforeParenthesis ? ' ' : '',
				default => ' ',
			};
			$space = $token->getTrailingSpace();
			if (
				$space !== null
				&& $space !== $expected
				&& $context->report($token, 'Wrong whitespace in the head of a class declaration')
			) {
				$token->setTrailingSpace($expected);
			}
		}
	}
}
