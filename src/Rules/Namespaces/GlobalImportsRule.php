<?php declare(strict_types=1);

namespace DressCode\Rules\Namespaces;

use DressCode\ConfigurableRule;
use DressCode\NodeRule;
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
use PhpSyntax\Nodes\Statement\NamespaceNode;
use PhpSyntax\Nodes\Statement\UseNode;
use PhpSyntax\Parser;
use PhpSyntax\SymbolKind;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function defined, in_array, is_array;


/**
 * A namespaced file tells the compiler which global functions and constants it means, so that it can turn the
 * optimizable ones (`count()`, `strlen()`, `is_array()`...) into opcodes. It does so by importing them, and
 * which ones is a matter of the options, `optimized` being the short name for that list; a missing import joins
 * the first use statement of its kind or gets one of its own, and a leading backslash on an imported name goes
 * away. The other way is `backslash`, which imports nothing and writes the leading backslash instead, as a
 * standard asks for when the file must not depend on its imports. The shape and the order of the use statements
 * and the imports nothing uses belong to other rules.
 */
#[RuleInfo(
	'dresscode/global-imports',
	Stage::Structure,
	description: 'Imports the global functions and constants a namespaced file uses',
)]
final class GlobalImportsRule extends NodeRule implements ConfigurableRule
{
	private const OptimizedFunctions = [
		'strlen', 'is_null', 'is_bool', 'is_long', 'is_int', 'is_integer', 'is_float', 'is_double', 'is_string',
		'is_array', 'is_object', 'is_resource', 'is_scalar', 'boolval', 'intval', 'floatval', 'doubleval', 'strval',
		'defined', 'chr', 'ord', 'call_user_func_array', 'call_user_func', 'in_array', 'count', 'sizeof', 'get_class',
		'get_called_class', 'gettype', 'func_num_args', 'func_get_args', 'array_slice', 'array_key_exists', 'sprintf',
	];

	private const Backslash = 'backslash';

	/** @var string|list<string> */
	private string|array $functions = 'optimized';

	/** @var string|list<string> */
	private string|array $constants = 'none';


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'functions' => Expect::anyOf('optimized', 'all', 'none', self::Backslash, Expect::listOf('string'))->default('optimized')
				->description('Which global functions to import: the ones the compiler turns into opcodes, all, none, or those matching the patterns with *; backslash writes them fully qualified instead'),
			'constants' => Expect::anyOf('all', 'none', self::Backslash, Expect::listOf('string'))->default('none')
				->description('Which global constants to import: all, none, or those matching the patterns with *, case-sensitively; backslash writes them fully qualified instead'),
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
		$imported = [SymbolKind::Function->name => [], SymbolKind::Constant->name => []];
		foreach ($node->statements->getItems() as $stmt) {
			$kind = $stmt instanceof UseNode ? $stmt->kind : null;
			if ($kind === SymbolKind::Function || $kind === SymbolKind::Constant) {
				foreach ($stmt->items->getItems() as $item) {
					// the alias the import takes and the name it stands for; an item of a group takes one too
					$alias = $item->alias->text ?? $item->name->shortName;
					$imported[$kind->name][$kind === SymbolKind::Function ? strtolower($alias) : $alias] = $item->fullName;
				}
			}
		}

		$uses = [SymbolKind::Function->name => [], SymbolKind::Constant->name => []];
		foreach ($node->find(NameNode::class) as $name) {
			$parent = $name->parent;
			if (
				$parent instanceof FunctionCallNode
				&& $parent->name === $name
				&& $resolver->isGlobalFunctionCall($parent)
			) {
				$uses[SymbolKind::Function->name][strtolower($name->parts[0])][] = $name;
			} elseif ($parent instanceof ConstantFetchNode && !str_contains($resolver->resolveConstant($name), '\\')) {
				$uses[SymbolKind::Constant->name][$name->parts[0]][] = $name;
			}
		}

		foreach ([SymbolKind::Function, SymbolKind::Constant] as $kind) {
			$missing = [];
			$qualify = ($kind === SymbolKind::Function ? $this->functions : $this->constants) === self::Backslash;
			foreach ($uses[$kind->name] as $key => $occurrences) {
				$name = $occurrences[0]->parts[0];
				// what the alias of the name imports here: the global name itself, something else, or nothing yet
				$target = $imported[$kind->name][$key] ?? null;
				$isImported = $target !== null
					&& ($kind === SymbolKind::Function ? strcasecmp($target, $name) === 0 : $target === $name);
				if ($qualify) {
					// an alias standing for something else is not this global name, so it is left alone
					foreach ($target === null || $isImported ? $occurrences : [] as $occurrence) {
						$this->addBackslash($kind, $occurrence, $context);
					}

					continue;
				}

				// a name the namespace declares is not free: importing it would take it from the local one
				if ($target === null && $this->isWanted($kind, $name) && $resolver->isAliasFree($name, $kind, $node)) {
					if ($context->report($occurrences[0], ($kind === SymbolKind::Function ? "Global function $name()" : "Global constant '$name'") . ' must be imported')) {
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


	private function isWanted(SymbolKind $kind, string $name): bool
	{
		$policy = $kind === SymbolKind::Function ? $this->functions : $this->constants;
		if (is_array($policy)) {
			foreach ($policy as $pattern) {
				$regex = '~^' . str_replace('\*', '.*', preg_quote($pattern, '~')) . '$~' . ($kind === SymbolKind::Function ? 'i' : '');
				if (preg_match($regex, $name)) {
					return true;
				}
			}

			return false;
		}

		return match ($policy) {
			'optimized' => in_array(strtolower($name), self::OptimizedFunctions, strict: true),
			'all' => $kind === SymbolKind::Function
				? function_exists($name)
				: defined($name) && !in_array(strtoupper($name), ['TRUE', 'FALSE', 'NULL'], strict: true),
			default => false,
		};
	}


	private function addBackslash(SymbolKind $kind, NameNode $name, RuleContext $context): void
	{
		$what = $kind === SymbolKind::Function ? "Global function {$name->parts[0]}()" : "Global constant '{$name->parts[0]}'";
		if (
			$name->kind === NameKind::FullyQualified
			|| !$context->report($name, "$what must be written with the leading backslash")
		) {
			return;
		}

		$name->text = '\\' . $name->parts[0];
	}


	private function stripBackslash(NameNode $name, RuleContext $context): void
	{
		if (
			$name->kind !== NameKind::FullyQualified
			|| !$context->report($name, 'An imported name must be used without the leading backslash')
		) {
			return;
		}

		$name->text = $name->parts[0];
	}


	/**
	 * The names join the first use statement of the kind; without one they get a statement of their own after
	 * the last import of the kinds sorting before this one, else above the first constant import, else first
	 * in the namespace, a blank line apart.
	 * @param list<string> $names
	 */
	private function import(NamespaceNode $scope, SymbolKind $kind, array $names, RuleContext $context): void
	{
		$list = $scope->statements;
		$items = $list->getItems();
		$after = $firstConst = $existing = null;
		foreach ($items as $i => $stmt) {
			$isConst = $stmt instanceof UseNode && $stmt->kind === SymbolKind::Constant;
			// an item joins a plain import; under the prefix of a group it would import something else
			if ($stmt instanceof UseNode && !$stmt->isGroup() && $stmt->kind === $kind) {
				$existing ??= $stmt;
			}

			if ($isConst) {
				$firstConst ??= $i;
			}

			if ($stmt instanceof UseNode && ($kind === SymbolKind::Constant || !$isConst)) {
				$after = $i + 1;
			}
		}

		if ($existing !== null) {
			foreach ($names as $name) {
				$existing->addImport($name);
			}

			return;
		}

		$parser = new Parser;
		$keyword = $kind === SymbolKind::Function ? 'function' : 'const';
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

		$statement->setEdgeTrivia($leading, [$eol]);
		$list->insert($index, $statement);
	}
}
