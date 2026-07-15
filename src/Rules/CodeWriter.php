<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use DressCode\RuleContext;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, SymbolKind};
use PhpSyntax\Nodes\Statement;


/**
 * What a rule writing code into a file needs so that the code takes the shape the file has: whether the file imports
 * a function under another name. A rule shipped by a package writes with it too.
 */
final class CodeWriter
{
	/** Whether the file imports a function under a name other than its own, so that a call names another function than it spells. */
	public static function importsFunctionAs(RuleContext $context): bool
	{
		/** @var \WeakMap<NameResolver, bool> $known  by the resolver, which the first mutation replaces */
		static $known = new \WeakMap;
		$resolver = $context->getAnalysis(NameResolver::class);
		if (isset($known[$resolver])) {
			return $known[$resolver];
		}

		$file = $context->file;
		$scopes = [$file, ...array_filter($file->statements->getItems(), fn($statement) => $statement instanceof Statement\NamespaceNode)];
		return $known[$resolver] = array_any($scopes, fn(Node $scope) => array_any(
			$resolver->getImports(SymbolKind::Function, $scope),
			fn(string $function, string $alias) => strcasecmp($alias, QualifiedNames::stripNamespace($function)) !== 0,
		));
	}
}
