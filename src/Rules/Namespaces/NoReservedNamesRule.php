<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\GlobalCalls;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, SymbolKind, Token};
use PhpSyntax\Nodes\{ConstItemNode, UseItemNode};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\Member\ClassConstNode;
use PhpSyntax\Nodes\Scalar\StringNode;
use PhpSyntax\Nodes\Statement\{ClassNode, ConstNode, EnumNode, FunctionNode, InterfaceNode, TraitNode};


/**
 * A name PHP keeps for the language: `let` and `is` for a class-like, a function and a constant, `readonly` for a
 * function and `_` for a constant from PHP 8.6, `_` for a class and a class alias from 8.4, and `namespace` for a
 * class constant. The declaration is reported, an import bringing such a name with or without `as`, and a constant
 * or a class alias created by `define()` or `class_alias()` with the name written out. Nothing is renamed: the code
 * using the name may be anywhere.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class NoReservedNamesRule extends NodeRule
{
	private const ClassNames = ['let' => '8.6', 'is' => '8.6', '_' => '8.4'];

	/** what is named => lowercased name => the version that reserved it */
	private const Reserved = [
		'class' => self::ClassNames,
		'class alias' => self::ClassNames,
		'function' => ['let' => '8.6', 'is' => '8.6', 'readonly' => '8.6'],
		'constant' => ['let' => '8.6', 'is' => '8.6', '_' => '8.6'],
		'class constant' => ['namespace' => '8.6'],
	];

	/** function => the parameter naming what it creates, its position, and what that is */
	private const Creators = [
		'class_alias' => ['alias', 1, 'class alias'],
		'define' => ['constant_name', 0, 'constant'],
	];


	public static function getDecisions(): array
	{
		return [new Decision('upgrading.php.reservedNames', Domain::state('forbidden'), 'Names PHP 8.6 reserved, in declarations and imports')];
	}


	public function getVisitedNodes(): array
	{
		return [
			ClassNode::class, InterfaceNode::class, TraitNode::class, EnumNode::class, FunctionNode::class,
			ConstNode::class, ClassConstNode::class, UseItemNode::class, FunctionCallNode::class,
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		// each name as where it stands, what it says, the kind of symbol it names, and the noun of a message
		$names = match (true) {
			$node instanceof ClassNode => [[$node->name, $node->name->text, 'class', 'class']],
			$node instanceof InterfaceNode => [[$node->name, $node->name->text, 'class', 'interface']],
			$node instanceof TraitNode => [[$node->name, $node->name->text, 'class', 'trait']],
			$node instanceof EnumNode => [[$node->name, $node->name->text, 'class', 'enum']],
			$node instanceof FunctionNode => [[$node->name, $node->name->text, 'function', 'function']],
			$node instanceof ConstNode => array_map(
				fn(ConstItemNode $item) => [$item->name, $item->name->text, 'constant', 'constant'],
				$node->items->getItems(),
			),
			$node instanceof ClassConstNode => array_map(
				fn(ConstItemNode $item) => [$item->name, $item->name->text, 'class constant', 'class constant'],
				$node->items->getItems(),
			),
			$node instanceof UseItemNode => [[
				$node->alias ?? $node->name,
				$node->alias->text ?? $node->name->shortName,
				match ($node->symbolKind) {
					SymbolKind::Function => 'function',
					SymbolKind::Constant => 'constant',
					default => 'class alias',
				},
				'import',
			]],
			$node instanceof FunctionCallNode => self::findCreatedName($node, $context),
			default => [],
		};

		foreach ($names as [$at, $name, $kind, $noun]) {
			$since = self::Reserved[$kind][strtolower($name)] ?? null;
			if ($since !== null) {
				$context->report($at, "The name `$name` of the $noun is reserved since PHP $since.", fixable: false);
			}
		}
	}


	/**
	 * The name a call of `define()` or `class_alias()` creates, where it is written out.
	 * @return list<array{Node, string, string, string}>
	 */
	private static function findCreatedName(FunctionCallNode $call, RuleContext $context): array
	{
		$function = GlobalCalls::findFunction($call, self::Creators, $context);
		if ($function === null) {
			return [];
		}

		[$parameter, $position, $what] = self::Creators[$function];
		$value = $call->arguments->findArgument($parameter, $position)?->value;
		return $value instanceof StringNode ? [[$value, $value->toValue(), $what, $what]] : [];
	}
}
