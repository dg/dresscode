<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use PhpSyntax\Nodes\Member\{ClassConstNode, MethodNode, PropertyNode};
use PhpSyntax\Token;


/**
 * The modifiers of a property, a method and a constant as `VisibilityRequiredRule` reads and writes them.
 * @internal
 */
final class MemberModifiers
{
	public const Visibility = 1;
	public const SetVisibility = 2;

	private const Order = [
		Token::Abstract => 0, Token::Final => 0,
		Token::Public => self::Visibility, Token::Protected => self::Visibility, Token::Private => self::Visibility,
		Token::PublicSet => self::SetVisibility, Token::ProtectedSet => self::SetVisibility, Token::PrivateSet => self::SetVisibility,
		Token::Static => 3,
		Token::Readonly => 4,
	];


	/** The place of the modifier in the canonical order: `abstract` or `final`, visibility, set visibility, `static`, `readonly`. */
	public static function rank(Token $token): int
	{
		return self::Order[$token->is(Token::Var) ? Token::Public : $token->id] ?? 9;
	}


	public static function describeMember(PropertyNode|MethodNode|ClassConstNode $node): string
	{
		return match (true) {
			$node instanceof MethodNode => "method `{$node->name->text}()`",
			$node instanceof ClassConstNode => 'constant `' . $node->items->getItems()[0]->name->token->text . '`',
			default => 'property `$' . $node->items->getItems()[0]->plainName . '`',
		};
	}


	/** @param list<string> $texts */
	public static function write(PropertyNode|MethodNode|ClassConstNode $node, array $texts): void
	{
		foreach ($node->modifiers->getTokens() as $token) {
			$node->modifiers->removeToken($token);
		}

		foreach ($texts as $text) {
			$node->modifiers->append(Token::fromText($text));
		}
	}
}
