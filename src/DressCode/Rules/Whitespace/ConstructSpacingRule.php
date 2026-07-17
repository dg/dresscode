<?php declare(strict_types=1);

namespace DressCode\Rules\Whitespace;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\ClosureUsesNode;
use PhpSyntax\Nodes\IdentifierNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use function in_array;


/**
 * A single space after a language construct followed by more on its line (`return $a`, `new Foo`,
 * `function ()`), a single space before `as`, `else`, `elseif` and the `use` of a closure; `fn` hugs
 * its parenthesis.
 */
#[RuleInfo(
	'dresscode/construct-spacing',
	Stage::Formatting,
	description: 'Puts a single space around language constructs',
)]
final class ConstructSpacingRule extends Rule
{
	private const FollowedBySpace = [
		TokenKind::Abstract, TokenKind::As, TokenKind::Case, TokenKind::Catch, TokenKind::ClassKeyword, TokenKind::Clone,
		TokenKind::Const, TokenKind::Do, TokenKind::Echo, TokenKind::Else, TokenKind::Elseif, TokenKind::Extends,
		TokenKind::Final, TokenKind::Finally, TokenKind::For, TokenKind::Foreach, TokenKind::Function, TokenKind::Global,
		TokenKind::If, TokenKind::Implements, TokenKind::Include, TokenKind::IncludeOnce, TokenKind::Insteadof,
		TokenKind::Instanceof, TokenKind::Interface, TokenKind::Namespace, TokenKind::New, TokenKind::Print,
		TokenKind::Private, TokenKind::Protected, TokenKind::Public, TokenKind::Readonly, TokenKind::Require,
		TokenKind::RequireOnce, TokenKind::Return, TokenKind::Static, TokenKind::Switch, TokenKind::Match, TokenKind::Throw,
		TokenKind::Trait, TokenKind::Try, TokenKind::Use, TokenKind::Var, TokenKind::While, TokenKind::Yield,
		TokenKind::YieldFrom, TokenKind::Enum, TokenKind::Goto, TokenKind::Break, TokenKind::Continue,
	];
	private const PrecededBySpace = [TokenKind::As, TokenKind::Else, TokenKind::Elseif, TokenKind::Insteadof];


	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof Token
			|| $node->parent instanceof IdentifierNode
			|| $node->parent instanceof NameNode // a keyword as a name: new static(...), Foo::class
		) {
			return;
		}

		if ($node->is(TokenKind::Fn)) {
			$after = $node->getTrailingSpace();
			if (
				$after !== null
				&& $after !== ''
				&& $context->report($node, 'No whitespace between the fn keyword and its parenthesis')
			) {
				$node->setTrailingSpace('');
			}

			return;
		}

		$followedByCode = in_array($node->kind, self::FollowedBySpace, strict: true);
		$precededByCode = in_array($node->kind, self::PrecededBySpace, strict: true) || ($node->is(TokenKind::Use) && $node->parent instanceof ClosureUsesNode);
		if (!$followedByCode && !$precededByCode) {
			return;
		}

		$next = $node->getNext();
		if ($node->parent instanceof AnonymousClassNode && $next?->is('(')) {
			return; // new class ($a): dresscode/class-definition-spacing decides the space
		}

		$after = $node->getTrailingSpace();
		if (
			$followedByCode
			&& $after !== null
			&& $after !== ' '
			&& $next !== null
			&& !$next->is(';', ':', ',', ')', ']', TokenKind::DoubleColon, TokenKind::CloseTag)
			&& $context->report($node, "A single space after the {$node->text} keyword")
		) {
			$node->setTrailingSpace(' ');
		}

		if (!$precededByCode) {
			return;
		}

		$previous = $node->getPrevious();
		$before = $previous?->getTrailingSpace();
		if (
			$previous !== null
			&& $before !== null
			&& $before !== ' '
			&& $context->report($node, "A single space before the {$node->text} keyword")
		) {
			$previous->setTrailingSpace(' ');
		}
	}
}
