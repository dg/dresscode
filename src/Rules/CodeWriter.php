<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use DressCode\RuleContext;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{NameForm, Node, SymbolKind, UnqualifiedResolution};
use PhpSyntax\Nodes\{ExpressionNode, NameNode, Statement};


/**
 * What a rule writing code into a file needs so that the code takes the shape the file has: a function spelled the
 * way the file reaches it. A rule shipped by a package writes with it too.
 */
final class CodeWriter
{
	/**
	 * How the name of another global function is written in place of the name of a call of a global one: bare where
	 * the replaced name is bare, nothing takes the bare name and it is no less certain than the replaced one, which is
	 * the fallback the call already stood on; else, and for a name that is an expression, with the leading backslash.
	 */
	public static function spellFunction(string $function, NameNode|ExpressionNode $replaced, RuleContext $context): string
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		return $replaced instanceof NameNode
			&& $replaced->form === NameForm::Unqualified
			&& $resolver->isAliasFree($function, SymbolKind::Function, $replaced)
			&& (
				$resolver->getUnqualifiedResolution($replaced) === UnqualifiedResolution::Uncertain
				|| $resolver->getUnqualifiedResolution($function, SymbolKind::Function, $replaced) === UnqualifiedResolution::Global
			)
				? $function
				: '\\' . $function;
	}


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
