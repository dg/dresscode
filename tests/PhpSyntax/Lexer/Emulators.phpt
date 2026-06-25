<?php declare(strict_types=1);

/**
 * Emulators over hand-built raw token streams, as an older PHP tokenizer would produce them.
 */

use PhpSyntax\Lexer\Emulators\AsymmetricVisibility;
use PhpSyntax\Lexer\Emulators\PipeOperator;
use PhpSyntax\Lexer\Emulators\PropertyToken;
use PhpSyntax\Lexer\Emulators\VoidCast;
use PhpSyntax\Lexer\Lexer;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/**
 * Builds raw tokens from pairs [kind, text], with the offset following the texts.
 * @param  list<array{int, string}>  $pairs
 * @return list<Token>
 */
function raw(array $pairs): array
{
	$tokens = [];
	$offset = 0;
	foreach ($pairs as [$kind, $text]) {
		$tokens[] = new Token($kind, $text, $offset, 1);
		$offset += strlen($text);
	}

	return $tokens;
}


/**
 * @param  list<Token>  $tokens
 * @return list<array{int, string, ?int}>
 */
function summarize(array $tokens): array
{
	return array_map(fn(Token $t) => [$t->kind, $t->text, $t->originalOffset], $tokens);
}


test('PropertyToken: __PROPERTY__ is magic except after an object operator', function () {
	$emulator = new PropertyToken;
	Assert::true($emulator->isNeeded('echo __Property__;'));
	Assert::false($emulator->isNeeded('echo $property;'));

	$tokens = $emulator->emulate(raw([
		[TokenKind::Identifier, '__PROPERTY__'],
		[ord(';'), ';'],
		[TokenKind::Variable, '$o'],
		[TokenKind::ObjectOperator, '->'],
		[TokenKind::Whitespace, ' '],
		[TokenKind::Comment, '/* c */'],
		[TokenKind::Identifier, '__property__'],
		[TokenKind::NullsafeObjectOperator, '?->'],
		[TokenKind::Identifier, '__PROPERTY__'],
		[TokenKind::DoubleColon, '::'],
		[TokenKind::Identifier, '__PROPERTY__'],
	]));
	Assert::same(
		[TokenKind::MagicProperty, ord(';'), TokenKind::Variable, TokenKind::ObjectOperator, TokenKind::Whitespace, TokenKind::Comment, TokenKind::Identifier, TokenKind::NullsafeObjectOperator, TokenKind::Identifier, TokenKind::DoubleColon, TokenKind::MagicProperty],
		array_column(summarize($tokens), 0),
	);
});


test('AsymmetricVisibility: modifier(set) without whitespace inside', function () {
	$emulator = new AsymmetricVisibility;
	Assert::true($emulator->isNeeded('Public(Set)'));
	Assert::false($emulator->isNeeded('public (set)'));

	$tokens = $emulator->emulate(raw([
		[TokenKind::Public, 'public'],
		[ord('('), '('],
		[TokenKind::Identifier, 'SET'],
		[ord(')'), ')'],
		[TokenKind::Whitespace, ' '],
		[TokenKind::Private, 'private'],
		[TokenKind::Whitespace, ' '],
		[ord('('), '('],
		[TokenKind::Identifier, 'set'],
		[ord(')'), ')'],
		[TokenKind::Whitespace, ' '],
		[TokenKind::Variable, '$o'],
		[TokenKind::ObjectOperator, '->'],
		[TokenKind::Protected, 'protected'],
		[ord('('), '('],
		[TokenKind::Identifier, 'set'],
		[ord(')'), ')'],
	]));
	Assert::same([
		[TokenKind::PublicSet, 'public(SET)', 0],
		[TokenKind::Whitespace, ' ', 11],
		[TokenKind::Private, 'private', 12],
		[TokenKind::Whitespace, ' ', 19],
		[ord('('), '(', 20],
		[TokenKind::Identifier, 'set', 21],
		[ord(')'), ')', 24],
		[TokenKind::Whitespace, ' ', 25],
		[TokenKind::Variable, '$o', 26],
		[TokenKind::ObjectOperator, '->', 28],
		[TokenKind::Protected, 'protected', 30],
		[ord('('), '(', 39],
		[TokenKind::Identifier, 'set', 40],
		[ord(')'), ')', 43],
	], summarize($tokens));
});


test('PipeOperator: adjacent | and >', function () {
	$emulator = new PipeOperator;
	Assert::true($emulator->isNeeded('$a |> f(...)'));
	Assert::false($emulator->isNeeded('$a | $b > $c'));

	$tokens = $emulator->emulate(raw([
		[TokenKind::Variable, '$a'],
		[ord('|'), '|'],
		[ord('>'), '>'],
		[TokenKind::Variable, '$b'],
		[ord('|'), '|'],
		[TokenKind::Whitespace, ' '],
		[ord('>'), '>'],
		[ord('|'), '|'],
	]));
	Assert::same([
		[TokenKind::Variable, '$a', 0],
		[TokenKind::Pipe, '|>', 2],
		[TokenKind::Variable, '$b', 4],
		[ord('|'), '|', 6],
		[TokenKind::Whitespace, ' ', 7],
		[ord('>'), '>', 8],
		[ord('|'), '|', 9],
	], summarize($tokens));
});


test('VoidCast: (void) with optional spaces and tabs inside', function () {
	$emulator = new VoidCast;
	Assert::true($emulator->isNeeded("( \tVOID )"));
	Assert::false($emulator->isNeeded("(\nvoid)"));

	$tokens = $emulator->emulate(raw([
		[ord('('), '('],
		[TokenKind::Whitespace, " \t"],
		[TokenKind::Identifier, 'Void'],
		[TokenKind::Whitespace, ' '],
		[ord(')'), ')'],
		[ord('('), '('],
		[TokenKind::Identifier, 'void'],
		[ord(')'), ')'],
		[ord('('), '('],
		[TokenKind::Whitespace, "\n"],
		[TokenKind::Identifier, 'void'],
		[ord(')'), ')'],
		[ord('('), '('],
		[TokenKind::Identifier, 'void'],
		[TokenKind::Identifier, 'x'],
		[ord(')'), ')'],
		[ord('('), '('],
	]));
	Assert::same([
		[TokenKind::VoidCast, "( \tVoid )", 0],
		[TokenKind::VoidCast, '(void)', 9],
		[ord('('), '(', 15],
		[TokenKind::Whitespace, "\n", 16],
		[TokenKind::Identifier, 'void', 17],
		[ord(')'), ')', 21],
		[ord('('), '(', 22],
		[TokenKind::Identifier, 'void', 23],
		[TokenKind::Identifier, 'x', 27],
		[ord(')'), ')', 28],
		[ord('('), '(', 29],
	], summarize($tokens));
});


test('host emulators follow the PHP version', function () {
	$classes = array_map(get_class(...), Lexer::createHostEmulators());
	$expected = [];
	if (PHP_VERSION_ID < 80400) {
		$expected = [PropertyToken::class, AsymmetricVisibility::class];
	}
	if (PHP_VERSION_ID < 80500) {
		$expected = [...$expected, PipeOperator::class, VoidCast::class];
	}
	Assert::same($expected, $classes);
});
