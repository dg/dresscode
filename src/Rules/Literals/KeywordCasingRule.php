<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{IdentifierNode, NameNode};
use function count, in_array;


/**
 * Keywords in lowercase, `self` and `parent` included.
 */
#[RuleInfo(
	'dresscode/keywordCasing',
	Stage::Structure,
	description: 'Writes keywords in lowercase',
)]
final class KeywordCasingRule extends NodeRule
{
	private const Keywords = [
		Token::Throw, Token::Include, Token::IncludeOnce, Token::Eval, Token::Require, Token::RequireOnce,
		Token::LogicalOr, Token::LogicalXor, Token::LogicalAnd, Token::Print, Token::Yield, Token::YieldFrom,
		Token::Instanceof, Token::New, Token::Clone, Token::Exit, Token::If, Token::Elseif, Token::Else,
		Token::Endif, Token::Echo, Token::Do, Token::While, Token::Endwhile, Token::For, Token::Endfor,
		Token::Foreach, Token::Endforeach, Token::Declare, Token::Enddeclare, Token::As, Token::Switch,
		Token::Match, Token::Endswitch, Token::Case, Token::Default, Token::Break, Token::Continue,
		Token::Goto, Token::Function, Token::Fn, Token::Const, Token::Return, Token::Try, Token::Catch,
		Token::Finally, Token::Use, Token::Insteadof, Token::Global, Token::Static, Token::Abstract,
		Token::Final, Token::Private, Token::Protected, Token::Public, Token::Readonly, Token::PublicSet,
		Token::ProtectedSet, Token::PrivateSet, Token::Var, Token::Unset, Token::Isset, Token::Empty,
		Token::HaltCompiler, Token::ClassKeyword, Token::Trait, Token::Interface, Token::Enum, Token::Extends,
		Token::Implements, Token::List, Token::Array, Token::Callable, Token::NamespaceKeyword,
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

		static $keywords = array_flip(self::Keywords);
		$parent = $node->parent;
		$isKeyword = isset($keywords[$node->id]) && !$parent instanceof IdentifierNode;
		if (!$isKeyword && !$parent instanceof NameNode) {
			return;
		}

		$lower = strtolower($node->text);
		if ($lower === $node->text) {
			return;
		}

		if (!$isKeyword) {
			$parts = $parent->parts;
			$isKeyword = count($parts) === 1 && in_array(strtolower($parts[0]), ['self', 'parent'], true);
		}

		if (
			$isKeyword
			&& $context->report($node, "The keyword `$node->text` must be written `$lower`")
		) {
			$node->setText($lower);
		}
	}
}
