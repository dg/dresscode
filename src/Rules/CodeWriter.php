<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use DressCode\RuleContext;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{NameForm, SymbolKind, UnqualifiedResolution};
use PhpSyntax\Nodes\NameNode;


/**
 * What a rule writing code into a file needs so that the code takes the shape the file has: a function spelled the way
 * the file reaches it. A rule shipped by a package writes with it too.
 */
final class CodeWriter
{
	/**
	 * How the name of another global function is written in place of the name of a call of a global one: bare where
	 * the replaced name is bare, nothing takes the bare name and it is no less certain than the replaced one, which is
	 * the fallback the call already stood on; else with the leading backslash.
	 */
	public static function spellFunction(string $function, NameNode $replaced, RuleContext $context): string
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		return $replaced->form === NameForm::Unqualified
			&& $resolver->isAliasFree($function, SymbolKind::Function, $replaced)
			&& (
				$resolver->getUnqualifiedResolution($replaced->text, SymbolKind::Function, $replaced) === UnqualifiedResolution::Uncertain
				|| $resolver->getUnqualifiedResolution($function, SymbolKind::Function, $replaced) === UnqualifiedResolution::Global
			)
			? $function
			: '\\' . $function;
	}
}
