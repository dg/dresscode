<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use DressCode\Config\ConfigResolver;
use DressCode\Engine\Helpers;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{ParseException, Parser, SymbolKind};
use const SORT_FLAG_CASE, SORT_STRING;


/**
 * The functions and constants the namespaces of the scope declare, read from every file and not from a sample, because
 * a declaration left out would be taken as global where nothing reports it.
 * @internal
 */
final readonly class NamespaceDeclarations
{
	private function __construct(
		/** @var list<string>  fully qualified, sorted */
		public array $functions,
		/** @var list<string>  fully qualified, sorted */
		public array $constants,
		/** @var list<string>  the files that do not parse */
		public array $unparsed,
	) {
	}


	/**
	 * Every file naming a namespace or `define()` is parsed, since a declaration can stand anywhere a statement can.
	 * @param  list<string>  $files  relative to the root
	 */
	public static function collect(string $root, array $files): self
	{
		$parser = new Parser;
		$names = [SymbolKind::Function->name => [], SymbolKind::Constant->name => []];
		$unparsed = [];
		foreach ($files as $file) {
			$code = @file_get_contents(Helpers::toAbsolutePath($file, $root)); // @ file may have been deleted meanwhile
			if ($code === false || (stripos($code, 'namespace') === false && stripos($code, 'define') === false)) {
				continue;
			}

			try {
				$node = $parser->parse($code);
			} catch (ParseException) {
				$unparsed[] = $file;
				continue;
			}

			foreach (new NameResolver($node)->findNamespacedDeclarations($node) as $declaration) {
				$key = ConfigResolver::toSymbolKey($declaration->kind, $declaration->name);
				$names[$declaration->kind->name][$key] ??= $declaration->name;
			}
		}

		$sort = function (array $list): array {
			$list = array_values($list);
			sort($list, SORT_STRING | SORT_FLAG_CASE);
			return $list;
		};
		return new self($sort($names[SymbolKind::Function->name]), $sort($names[SymbolKind::Constant->name]), $unparsed);
	}


	public function isEmpty(): bool
	{
		return !$this->functions && !$this->constants;
	}
}
