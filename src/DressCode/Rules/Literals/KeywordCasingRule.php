<?php declare(strict_types=1);

namespace DressCode\Rules\Literals;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\IdentifierNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use function count, in_array;


/**
 * Keywords in lowercase, `self` and `parent` included.
 */
#[RuleInfo(
	'dresscode/keyword-casing',
	Stage::Structure,
	description: 'Writes keywords in lowercase',
)]
final class KeywordCasingRule extends Rule
{
	private const Keywords = [
		TokenKind::Throw, TokenKind::Include, TokenKind::IncludeOnce, TokenKind::Eval, TokenKind::Require, TokenKind::RequireOnce,
		TokenKind::LogicalOr, TokenKind::LogicalXor, TokenKind::LogicalAnd, TokenKind::Print, TokenKind::Yield, TokenKind::YieldFrom,
		TokenKind::Instanceof, TokenKind::New, TokenKind::Clone, TokenKind::Exit, TokenKind::If, TokenKind::Elseif, TokenKind::Else,
		TokenKind::Endif, TokenKind::Echo, TokenKind::Do, TokenKind::While, TokenKind::Endwhile, TokenKind::For, TokenKind::Endfor,
		TokenKind::Foreach, TokenKind::Endforeach, TokenKind::Declare, TokenKind::Enddeclare, TokenKind::As, TokenKind::Switch,
		TokenKind::Match, TokenKind::Endswitch, TokenKind::Case, TokenKind::Default, TokenKind::Break, TokenKind::Continue,
		TokenKind::Goto, TokenKind::Function, TokenKind::Fn, TokenKind::Const, TokenKind::Return, TokenKind::Try, TokenKind::Catch,
		TokenKind::Finally, TokenKind::Use, TokenKind::Insteadof, TokenKind::Global, TokenKind::Static, TokenKind::Abstract,
		TokenKind::Final, TokenKind::Private, TokenKind::Protected, TokenKind::Public, TokenKind::Readonly, TokenKind::PublicSet,
		TokenKind::ProtectedSet, TokenKind::PrivateSet, TokenKind::Var, TokenKind::Unset, TokenKind::Isset, TokenKind::Empty,
		TokenKind::HaltCompiler, TokenKind::ClassKeyword, TokenKind::Trait, TokenKind::Interface, TokenKind::Enum, TokenKind::Extends,
		TokenKind::Implements, TokenKind::List, TokenKind::Array, TokenKind::Callable, TokenKind::Namespace,
	];


	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token) {
			return;
		}

		$isKeyword = in_array($node->kind, self::Keywords, strict: true) && !$node->parent instanceof IdentifierNode;
		if (!$isKeyword && $node->parent instanceof NameNode) {
			$parts = $node->parent->getParts();
			$isKeyword = count($parts) === 1 && in_array(strtolower($parts[0]), ['self', 'parent'], strict: true);
		}

		$lower = strtolower($node->text);
		if (
			$isKeyword
			&& $lower !== $node->text
			&& $context->report($node, "The keyword '$node->text' must be written '$lower'")
		) {
			$node->setText($lower);
		}
	}
}
