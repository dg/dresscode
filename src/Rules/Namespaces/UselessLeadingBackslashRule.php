<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{NameForm, Node, SymbolKind, Token};
use PhpSyntax\Nodes\{NameNode, UseItemNode};
use PhpSyntax\Nodes\Statement\UseNode;
use function count;


/**
 * Names without a leading backslash where it changes nothing. An import names a fully qualified name anyway:
 * `use Foo\Bar;`, not `use \Foo\Bar;`. Code in the global namespace references classes, functions and constants
 * without it: `new Foo`, `strlen()`, `PHP_EOL`, never `\Foo`; a name whose first segment is shadowed by an import
 * keeps it, because there `\Foo` and `Foo` are two different things. Inside a namespace the backslash says which
 * name is meant and stays.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class UselessLeadingBackslashRule extends NodeRule
{
	private const InImport = 'qualification.uselessBackslash';
	private const InGlobalNamespace = 'qualification.inFileWithoutNamespace';

	/** @var list<class-string<Node>> */
	private array $visitedNodes = [];


	public static function getDecisions(): array
	{
		return [
			new Decision(
				self::InGlobalNamespace,
				new Words([QualificationPolicy::Bare => '`strlen()`, `new DateTime`, never `\strlen()`']),
				'A name in a file without a namespace, where a leading backslash changes nothing',
			),
			new Decision(self::InImport, Domain::state('forbidden'), 'The leading backslash of an import, which changes nothing'),
		];
	}


	public function configure(Values $values): void
	{
		$this->visitedNodes = array_keys(array_filter([
			UseNode::class => !$values->isKept(self::InImport),
			// every name is visited, so only where the file without a namespace is decided
			NameNode::class => !$values->isKept(self::InGlobalNamespace),
		]));
	}


	public function getVisitedNodes(): array
	{
		return $this->visitedNodes;
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof UseNode) {
			self::processImport($node, $context);
		} elseif ($node instanceof NameNode) {
			self::processName($node, $context);
		}
	}


	private static function processImport(UseNode $node, RuleContext $context): void
	{
		// a group writes the backslash once, in front of the prefix its items hang on
		$names = $node->prefix === null
			? array_map(fn(UseItemNode $item) => $item->name, $node->items->getItems())
			: [$node->prefix];
		foreach ($names as $name) {
			if (
				$name->form === NameForm::FullyQualified
				&& $context->report($name, "Useless leading backslash of `{$name->token->text}`, because an import names the fully qualified name anyway.", decision: self::InImport)
			) {
				$name->text = substr($name->token->text, 1);
			}
		}
	}


	private static function processName(NameNode $node, RuleContext $context): void
	{
		if ($node->isDeclaration() || $node->form !== NameForm::FullyQualified) { // an import is taken by its UseNode
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$parts = $node->parts;
		// the first segment of a qualified name goes through the class imports whatever the name stands for
		$kind = count($parts) > 1 ? SymbolKind::ClassLike : $node->symbolKind;
		if (
			$resolver->getNamespace($node) !== ''
			|| isset($resolver->getImports($kind, $node)[NameReferences::toKey($kind, $parts[0])])
			|| !$context->report($node, "Useless leading backslash of `{$node->token->text}`, because the code stands in the global namespace.", decision: self::InGlobalNamespace)
		) {
			return;
		}

		$node->text = implode('\\', $parts);
	}
}
