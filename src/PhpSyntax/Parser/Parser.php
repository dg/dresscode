<?php declare(strict_types=1);

namespace PhpSyntax\Parser;

use PhpSyntax\Lexer\Lexer;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArrayItemNode;
use PhpSyntax\Nodes\EmptyArrayItemNode;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\SeparatedNodeList;
use PhpSyntax\Nodes\Statement\HaltCompilerNode;
use PhpSyntax\Nodes\StatementNode;
use PhpSyntax\ParseException;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use function count, ord;


/**
 * LALR(1) parser over the generated tables; builds the concrete syntax tree.
 * Based on works by Nikita Popov, Moriyoshi Koizumi and Masato Bito.
 */
final class Parser
{
	use ParserData;

	private const SymbolNone = -1;

	/** @var list<Token> */
	private array $tokens = [];
	private int $position = 0;

	/** @var array<int, Node|Token|null>  semantic value stack: tokens and results of reductions */
	private array $semStack = [];

	/** result of the last reduction */
	private Node|Token|null $semValue = null;


	public function __construct(
		private readonly Lexer $lexer = new Lexer,
	) {
	}


	/** @throws ParseException */
	public function parse(string $code): FileNode
	{
		$this->tokens = $this->lexer->tokenize($code);
		$this->position = 0;
		$stmts = $this->run();
		if (!$stmts instanceof NodeList) {
			throw new \LogicException('The start production must yield a list of statements.');
		}

		$eof = $this->tokens[count($this->tokens) - 1];
		$data = $this->tokens[count($this->tokens) - 2] ?? null;
		if ($data?->kind === TokenKind::HaltCompilerData) {
			$halt = $stmts->items[count($stmts->items) - 1] ?? null;
			if (!$halt instanceof HaltCompilerNode) {
				throw new \LogicException('__halt_compiler() data without the halt statement.');
			}

			$halt->setData($data);
		}

		/** @var NodeList<StatementNode> $stmts */
		return (new FileNode($stmts, $eof))->attach();
	}


	private function run(): Node|Token|null
	{
		$symbol = self::SymbolNone;
		$token = $this->tokens[$this->position];
		$state = 0;
		$stateStack = [$state];
		$stackPos = 0;
		$this->semStack = [];

		do {
			if (self::ActionBase[$state] === 0) {
				$rule = self::ActionDefault[$state];
			} else {
				if ($symbol === self::SymbolNone) {
					$token = $this->tokens[$this->position];
					$symbol = self::TokenToSymbol[match ($token->kind) {
						TokenKind::CloseTag => ord(';'),
						TokenKind::OpenTagWithEcho => TokenKind::Echo,
						TokenKind::HaltCompilerData => TokenKind::EndOfFile,
						default => $token->kind,
					}];
				}

				$idx = self::ActionBase[$state] + $symbol;
				if ((($idx >= 0 && $idx < count(self::Action) && self::ActionCheck[$idx] === $symbol)
					|| ($state < self::Yy2Tblstate
						&& ($idx = self::ActionBase[$state + self::NumNonLeafStates] + $symbol) >= 0
						&& $idx < count(self::Action) && self::ActionCheck[$idx] === $symbol))
					&& ($action = self::Action[$idx]) !== self::DefaultAction
				) {
					/*
					>= numNonLeafStates: shift and reduce
					> 0: shift
					= 0: accept
					< 0: reduce
					= -YYUNEXPECTED: error
					*/
					if ($action > 0) { // shift
						++$stackPos;
						$stateStack[$stackPos] = $state = $action;
						$this->semStack[$stackPos] = $token;
						if ($token->kind !== TokenKind::EndOfFile && $token->kind !== TokenKind::HaltCompilerData) {
							$this->position++;
						}

						$symbol = self::SymbolNone;
						if ($action < self::NumNonLeafStates) {
							continue;
						}

						$rule = $action - self::NumNonLeafStates; // shift-and-reduce
					} else {
						$rule = -$action;
					}
				} else {
					$rule = self::ActionDefault[$state];
				}
			}

			do {
				if ($rule === 0) { // accept
					return $this->semValue;

				} elseif ($rule !== self::UnexpectedTokenRule) { // reduce
					$this->reduce($rule, $stackPos);

					// goto - shift nonterminal
					$stackPos -= self::RuleToLength[$rule];
					$nonTerminal = self::RuleToNonTerminal[$rule];
					$idx = self::GotoBase[$nonTerminal] + $stateStack[$stackPos];
					$state = $idx >= 0 && $idx < count(self::Goto) && self::GotoCheck[$idx] === $nonTerminal
						? self::Goto[$idx]
						: self::GotoDefault[$nonTerminal];

					++$stackPos;
					$stateStack[$stackPos] = $state;
					$this->semStack[$stackPos] = $this->semValue;

				} else {
					throw $this->createUnexpectedTokenException($state, $token);
				}

				if ($state < self::NumNonLeafStates) {
					break;
				}

				$rule = $state - self::NumNonLeafStates; // shift-and-reduce
			} while (true);
		} while (true);
	}


	/**
	 * The grammar parses "[1,]" and "[]" with an empty item after the last comma; that item is really
	 * a trailing separator, or nothing at all.
	 * @param  SeparatedNodeList<ArrayItemNode|EmptyArrayItemNode>  $items
	 * @return SeparatedNodeList<ArrayItemNode|EmptyArrayItemNode>
	 */
	protected function finishArrayItems(SeparatedNodeList $items): SeparatedNodeList
	{
		$last = $items->items[count($items->items) - 1];
		if ($last instanceof EmptyArrayItemNode) {
			$last->parent = null;
			return (new SeparatedNodeList(array_slice($items->items, 0, -1), $items->separators))->attach();
		}

		return $items;
	}


	/**
	 * A production without an action passes a single child through, an empty one yields null,
	 * any other becomes a generic node.
	 */
	protected function reduceGeneric(int $rule, int $pos): void
	{
		$length = self::RuleToLength[$rule];
		if ($length === 0) {
			$this->semValue = null;
		} elseif ($length === 1) {
			$this->semValue = $this->semStack[$pos];
		} else {
			$children = [];
			for ($i = $pos - $length + 1; $i <= $pos; $i++) {
				if ($this->semStack[$i] !== null) {
					$children[] = $this->semStack[$i];
				}
			}

			$this->semValue = (new GenericNode($rule, $children))->attach();
		}
	}


	private function createUnexpectedTokenException(int $state, Token $token): ParseException
	{
		$message = $token->kind === TokenKind::EndOfFile
			? 'Unexpected end of file'
			: "Unexpected '$token->text'";
		if ($expected = $this->getExpectedTokens($state)) {
			$last = array_pop($expected);
			$message .= ', expecting ' . ($expected ? implode(', ', $expected) . ' or ' : '') . $last;
		}

		return new ParseException($message, $token->originalLine, $token->originalOffset);
	}


	/**
	 * Names of tokens acceptable in the state, or [] when there are too many to be helpful.
	 * @return list<string>
	 */
	private function getExpectedTokens(int $state): array
	{
		$expected = [];
		$base = self::ActionBase[$state];
		foreach (self::SymbolToName as $symbol => $name) {
			$idx = $base + $symbol;
			if (
				(($idx >= 0 && $idx < count(self::Action) && self::ActionCheck[$idx] === $symbol)
					|| ($state < self::Yy2Tblstate
						&& ($idx = self::ActionBase[$state + self::NumNonLeafStates] + $symbol) >= 0
						&& $idx < count(self::Action) && self::ActionCheck[$idx] === $symbol))
				&& self::Action[$idx] !== self::UnexpectedTokenRule
				&& self::Action[$idx] !== self::DefaultAction
				&& $symbol !== 0
			) {
				if (count($expected) === 4) {
					return [];
				}

				$expected[] = $name;
			}
		}

		return $expected;
	}
}
