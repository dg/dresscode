<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{PhpDoc, Types};
use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use DressCode\Rules\CodeWriter;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\Statement\NamespaceNode;


/**
 * References of classes, interfaces and enums their declaration deprecates, wherever the name stands, an import,
 * a type, an instantiation, a static access, an attribute. Where the deprecation names the class to use instead,
 * `@deprecated use Acme\Mail\SmtpTransport`, and that class exists, the reference is rewritten to it and the imports
 * follow; any other is reported with what the deprecation says. In a doc comment only a class the deprecation names
 * a replacement for is rewritten, one without it being left as it is. A class the map of replacedClasses or of
 * forbiddenClasses has is not reported.
 */
#[RuleInfo(
	Stage::Structure,
	typesRequired: true,
	modifiesComments: true,
	reads: [self::ReplacedClasses, self::ForbiddenClasses],
	analyses: [PhpDoc::class, Types::class, NameResolver::class],
)]
final class NoDeprecatedClassesRule extends NodeRule
{
	private const ReplacedClasses = 'upgrading.libraries.replacedClasses';
	private const ForbiddenClasses = 'upgrading.libraries.forbiddenClasses';

	/** @var array<string, true>  lowercased class, fully qualified, that a map of the libraries has */
	private array $mapped = [];


	public static function getDecisions(): array
	{
		return [new Decision('upgrading.declarations.deprecatedClass', new Words(['replaced' => 'replaced by the class the deprecation names, reported where it names none']), 'A class whose declaration is `@deprecated`')];
	}


	public function configure(Values $values): void
	{
		$this->mapped = [];
		foreach ([...array_keys($values->readMap(self::ReplacedClasses)), ...array_keys($values->readMap(self::ForbiddenClasses))] as $class) {
			$this->mapped[strtolower(ltrim((string) $class, '\\'))] = true;
		}
	}


	public function getVisitedNodes(): array
	{
		return [FileNode::class, NamespaceNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ((!$node instanceof FileNode && !$node instanceof NamespaceNode) || CodeWriter::findImportScope($node) !== $node) {
			return;
		}

		$types = $context->getAnalysis(Types::class);
		$find = function (string $class) use ($types): ?array {
			$deprecation = isset($this->mapped[strtolower($class)]) ? null : $types->findClassDeprecation($class);
			return $deprecation === null
				? null
				: [
					"Class `$class` is deprecated" . $deprecation->formatDescription(),
					$deprecation->replacementClass,
				];
		};
		ClassReplacement::rewriteReferences(
			$node,
			$context,
			$find,
			findInDocs: fn(string $class) => ($found = $find($class)) !== null && $found[1] !== null ? $found : null,
		);
	}
}
