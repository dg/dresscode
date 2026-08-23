<?php declare(strict_types=1);

namespace DressCode\Rules\Namespaces;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\NameKind;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ConstantFetchNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\Statement\GroupUseNode;
use PhpSyntax\Nodes\Statement\NamespaceNode;
use PhpSyntax\Nodes\Statement\UseNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function count, in_array, is_array;


/**
 * A namespaced file imports the global functions and constants it uses, so that the compiler knows them and
 * turns the optimizable ones (`count()`, `strlen()`, `is_array()`...) into opcodes; which ones is a matter of
 * the options, `optimized` being the short name for that list. A missing import joins the first use statement
 * of its kind or gets one of its own, and a leading backslash on an imported name goes away. The shape and the
 * order of the use statements and the imports nothing uses belong to other rules.
 */
#[RuleInfo(
	'dresscode/global-imports',
	Stage::Structure,
	description: 'Imports the global functions and constants a namespaced file uses',
)]
final class GlobalImportsRule extends Rule implements ConfigurableRule
{
	private const OptimizedFunctions = [
		'strlen', 'is_null', 'is_bool', 'is_long', 'is_int', 'is_integer', 'is_float', 'is_double', 'is_string',
		'is_array', 'is_object', 'is_resource', 'is_scalar', 'boolval', 'intval', 'floatval', 'doubleval', 'strval',
		'defined', 'chr', 'ord', 'call_user_func_array', 'call_user_func', 'in_array', 'count', 'sizeof', 'get_class',
		'get_called_class', 'gettype', 'func_num_args', 'func_get_args', 'array_slice', 'array_key_exists', 'sprintf',
	];

	/** @var string|list<string> */
	private string|array $functions = 'optimized';

	/** @var string|list<string> */
	private string|array $constants = 'none';


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'functions' => Expect::anyOf('optimized', 'all', 'none', Expect::listOf('string'))->default('optimized')
				->description('Which global functions to import: the ones the compiler turns into opcodes, all, none, or those matching the patterns with *'),
			'constants' => Expect::anyOf('all', 'none', Expect::listOf('string'))->default('none')
				->description('Which global constants to import: all, none, or those matching the patterns with *, case-sensitively'),
		]);
	}


	public function configure(array $options): void
	{
		$this->functions = $options['functions'];
		$this->constants = $options['constants'];
	}


	public function getVisitedTypes(): array
	{
		return [NamespaceNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof NamespaceNode) {
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$imported = [TokenKind::Function => [], TokenKind::Const => []];
		foreach ($node->stmts->getItems() as $stmt) {
			$kind = $stmt instanceof UseNode ? $stmt->type?->kind : null;
			if ($stmt instanceof UseNode && ($kind === TokenKind::Function || $kind === TokenKind::Const)) {
				foreach ($stmt->items->getItems() as $item) {
					if (count($item->name->getParts()) === 1 && $item->alias === null) {
						$imported[$kind][] = $item->name->getName();
					}
				}
			}
		}

		$uses = [TokenKind::Function => [], TokenKind::Const => []];
		foreach ($node->getDescendants(NameNode::class) as $name) {
			$parent = $name->parent;
			if (
				$parent instanceof FunctionCallNode
				&& $parent->name === $name
				&& $resolver->isGlobalFunctionCall($parent)
			) {
				$uses[TokenKind::Function][strtolower($name->getParts()[0])][] = $name;
			} elseif ($parent instanceof ConstantFetchNode && !str_contains($resolver->resolveConstant($name), '\\')) {
				$uses[TokenKind::Const][$name->getParts()[0]][] = $name;
			}
		}

		foreach ($uses as $kind => $names) {
			$known = $kind === TokenKind::Function ? array_map('strtolower', $imported[$kind]) : $imported[$kind];
			$missing = [];
			foreach ($names as $key => $occurrences) {
				$name = $occurrences[0]->getParts()[0];
				$isImported = in_array($key, $known, strict: true);
				if (!$isImported && $this->isWanted($kind, $name)) {
					if ($context->report($occurrences[0], ($kind === TokenKind::Function ? "Global function $name()" : "Global constant '$name'") . ' must be imported')) {
						$missing[] = $name;
						$isImported = true;
					}
				}

				foreach ($isImported ? $occurrences : [] as $occurrence) {
					$this->stripBackslash($occurrence, $context);
				}
			}

			if ($missing !== []) {
				sort($missing);
				$this->import($node, $kind, $missing, $context);
			}
		}
	}


	private function isWanted(int $kind, string $name): bool
	{
		$policy = $kind === TokenKind::Function ? $this->functions : $this->constants;
		if (is_array($policy)) {
			foreach ($policy as $pattern) {
				$regex = '~^' . str_replace('\*', '.*', preg_quote($pattern, '~')) . '$~' . ($kind === TokenKind::Function ? 'i' : '');
				if (preg_match($regex, $name)) {
					return true;
				}
			}

			return false;
		}

		return match ($policy) {
			'optimized' => in_array(strtolower($name), self::OptimizedFunctions, strict: true),
			'all' => $kind === TokenKind::Function
				? function_exists($name)
				: defined($name) && !in_array(strtoupper($name), ['TRUE', 'FALSE', 'NULL'], strict: true),
			default => false,
		};
	}


	private function stripBackslash(NameNode $name, RuleContext $context): void
	{
		if (
			$name->getKind() !== NameKind::FullyQualified
			|| !$context->report($name, 'An imported name must be used without the leading backslash')
		) {
			return;
		}

		$token = new Token(TokenKind::Identifier, $name->getParts()[0], $name->token->originalOffset, $name->token->originalLine);
		$token->setLeadingTrivia($name->token->leadingTrivia);
		$token->setTrailingTrivia($name->token->trailingTrivia);
		$name->setToken($token);
	}


	/**
	 * The names join the first use statement of the kind; without one they get a statement of their own after
	 * the last import of the kinds sorting before this one, else above the first constant import, else first
	 * in the namespace, a blank line apart.
	 * @param list<string> $names
	 */
	private function import(NamespaceNode $scope, int $kind, array $names, RuleContext $context): void
	{
		$list = $scope->stmts;
		$items = $list->getItems();
		$after = $firstConst = $existing = null;
		foreach ($items as $i => $stmt) {
			$isConst = $stmt instanceof UseNode && $stmt->type?->kind === TokenKind::Const;
			if ($stmt instanceof UseNode && $stmt->type?->kind === $kind) {
				$existing ??= $stmt;
			}

			if ($isConst) {
				$firstConst ??= $i;
			}

			if (
				(
					$stmt instanceof UseNode
					|| $stmt instanceof GroupUseNode
				)
				&& (
					$kind === TokenKind::Const
					|| !$isConst
				)
			) {
				$after = $i + 1;
			}
		}

		$parser = new Parser;
		$keyword = $kind === TokenKind::Function ? 'function' : 'const';
		if ($existing !== null) {
			$statement = $parser->parseStatement("use $keyword " . implode(', ', $names) . ';');
			assert($statement instanceof UseNode);
			foreach ($statement->items->getItems() as $item) {
				$existing->items->append(clone $item);
			}

			return;
		}

		$statement = $parser->parseStatement("use $keyword " . implode(', ', $names) . ';');
		$eol = new Trivia(TriviaKind::EndOfLine, $context->getStyle()->eol);
		$index = $after ?? $firstConst ?? 0;
		$neighbor = $items[$index] ?? null;
		$indentation = ($after === null ? $neighbor : $items[$after - 1])?->getFirstToken()?->getIndentation()
			?? ($scope->openBrace ? $context->getStyle()->indent : '');
		$indent = $indentation === '' ? [] : [new Trivia(TriviaKind::Whitespace, $indentation)];
		$neighborFirst = $neighbor?->getFirstToken();
		if ($after !== null) {
			$leading = $indent;
		} elseif ($firstConst !== null && $neighborFirst !== null) {
			$leading = $neighborFirst->leadingTrivia;
			$neighborFirst->setLeadingTrivia($indent);
		} else {
			$leading = $scope->openBrace ? $indent : [$eol, ...$indent]; // first in the namespace, a blank line apart unless braced
			if (
				$neighborFirst !== null
				&& ($neighborFirst->leadingTrivia[0] ?? null)?->kind !== TriviaKind::EndOfLine
			) {
				$neighborFirst->setBlankLinesBefore(1, $context->getStyle()->eol);
			}
		}

		$statement->getFirstToken()?->setLeadingTrivia($leading);
		$statement->getLastToken()?->setTrailingTrivia([$eol]);
		$list->insert($index, $statement);
	}
}
