<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{IdentifierNode, NameNode};
use PhpSyntax\Nodes\Scalar\{BooleanNode, MagicConstantNode, NullNode};
use function count, in_array;


/**
 * The casing of keywords, `self` and `parent` included, of `true`, `false` and `null`, and of magic constants such
 * as `__DIR__`. A leading backslash of `\true` is part of how the literal is written and stays.
 */
#[RuleInfo(Stage::Structure)]
final class BuiltinCasingRule extends NodeRule
{
	private const Keyword = 'builtin.keyword';
	private const TrueFalseNull = 'builtin.trueFalseNull';
	private const MagicConstant = 'builtin.magicConstant';

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

	/** the word of each decision, null for keep */
	private ?string $keyword;
	private ?string $trueFalseNull;
	private ?string $magicConstant;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Keyword, new Words(['lowercase' => '`if`, `function`, `self`, `parent`']), 'The case of keywords, `self` and `parent` included'),
			new Decision(self::TrueFalseNull, new Words(['lowercase' => '`true`, `false`, `null`', 'uppercase' => '`TRUE`, `FALSE`, `NULL`']), 'The case of `true`, `false` and `null`, a leading backslash staying'),
			new Decision(self::MagicConstant, new Words(['uppercase' => '`__DIR__`, `__CLASS__`', 'lowercase' => '`__dir__`, `__class__`']), 'The case of the magic constants such as `__DIR__`'),
		];
	}


	public function configure(Values $values): void
	{
		$this->keyword = $values->find(self::Keyword)?->getWord();
		$this->trueFalseNull = $values->find(self::TrueFalseNull)?->getWord();
		$this->magicConstant = $values->find(self::MagicConstant)?->getWord();
	}


	public function getVisitedNodes(): array
	{
		return [
			...($this->keyword !== null ? [Token::class] : []),
			...($this->trueFalseNull !== null ? [BooleanNode::class, NullNode::class] : []),
			...($this->magicConstant !== null ? [MagicConstantNode::class] : []),
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token) {
			if (($node instanceof BooleanNode || $node instanceof NullNode) && $this->trueFalseNull !== null) {
				$this->checkLiteral($node, $this->trueFalseNull, self::TrueFalseNull, $context);
			} elseif ($node instanceof MagicConstantNode && $this->magicConstant !== null) {
				$this->checkLiteral($node, $this->magicConstant, self::MagicConstant, $context);
			}

			return;
		}

		// every token passes here, so the keyword is checked inline
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
			&& $context->report($node, "The keyword `$node->text` must be written `$lower`.", decision: self::Keyword)
		) {
			$node->setText($lower);
		}
	}


	private function checkLiteral(BooleanNode|NullNode|MagicConstantNode $node, string $case, string $decision, RuleContext $context): void
	{
		$text = $node->token->text;
		$wanted = $case === 'uppercase' ? strtoupper($text) : strtolower($text);
		$message = $node instanceof MagicConstantNode
			? "The magic constant `$text` must be written `$wanted`."
			: "The literal `$text` must be written `$wanted`.";
		if ($wanted !== $text && $context->report($node, $message, decision: $decision)) {
			$node->token->setText($wanted);
		}
	}
}
