<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use PhpSyntax\Nodes\Statement\UseNode;
use PhpSyntax\{ParseException, Parser, SymbolKind};
use function in_array, is_int;


/**
 * What decides how a file is processed: the presets, the groups and the rules, the style, the version of PHP the code
 * is written for, whether its types are known, what its namespaces declare, and the rules whose risky fixes are
 * accepted or whose violations only warn. A preset
 * is a profile under a name, an override is one for a part of the tree, and the configuration is the one of the whole
 * project. Where profiles meet, a value is the last one said, a list adds up and the options of a rule merge layer by layer.
 */
readonly class Profile
{
	/** @var array{functions: list<string>, constants: list<string>}  fully qualified names */
	public array $namespaces;

	/**
	 * 'certain' says the namespaces declare nothing beyond the lists, so that an unqualified name no list names is global,
	 * a fix resting on that is not risky and a declaration missing from the lists is reported; 'uncertain' takes such a
	 * name as global without knowing, which is the default
	 * @var 'certain'|'uncertain'|null
	 */
	public ?string $nameResolution;

	/** @var list<Group>  what the profile asks for beyond the looks of the code, every rule of the group with its defaults */
	public array $groups;


	/**
	 * @param list<string|Group> $groups
	 * @param array{functions?: list<string>, constants?: list<string>} $namespaces  functions and constants the namespaces
	 *   declare, each written as an item of a use statement writes it ('App\helper', 'App\Utils\{format, parse}'): an
	 *   unqualified call in a namespace reaches such a function before the global one, which no file that calls it shows
	 */
	public function __construct(
		/** @var list<string>  names or classes, laid below the rest of the profile */
		public array $presets = [],
		/** @var list<string|Group>  groups of rules, by the name of the group (`cleanup`) or by the case itself */
		array $groups = [],
		/** @var array<string, bool|string|int|array<string, mixed>|\Closure(): Rule>  name or class → enabled, the value of its decision, options, or a factory for a rule with dependencies */
		public array $rules = [],
		/** a number of spaces or 'tab' */
		public int|string|null $indent = null,
		/** 'LF', 'CRLF', 'majority' or 'platform' */
		public ?string $eol = null,
		/** the widest line as the reader sees it, which the rules that break or report long lines keep to; false for none */
		public int|false|null $lineLength = null,
		/** the version the rules target, major.minor with an optional patch; without one that of composer.json, else Config::DefaultPhpVersion */
		public ?string $php = null,
		array $namespaces = [],
		?string $nameResolution = null,
		/** @var list<string>  names or classes of the rules whose fixes are made even where they may change what the code does */
		public array $fixRisky = [],
		/** @var list<string>  names or classes of the rules whose violations only warn */
		public array $warnings = [],
	) {
		if ($indent !== null && $indent !== 'tab' && !(is_int($indent) && $indent >= 1)) {
			throw new \InvalidArgumentException("The indentation must be a number of spaces or 'tab'.");
		} elseif ($eol !== null && !in_array($eol, ['LF', 'CRLF', 'majority', 'platform'], true)) {
			throw new \InvalidArgumentException("The line ending must be 'LF', 'CRLF', 'majority' or 'platform'.");
		} elseif ($lineLength !== null && $lineLength !== false && $lineLength < 1) {
			throw new \InvalidArgumentException('The line length must be a positive number of characters or false.');
		} elseif ($php !== null && !preg_match('~^\d+\.\d+(?:\.\d+)?$~D', $php)) {
			throw new \InvalidArgumentException("Invalid PHP version '$php'.");
		} elseif ($nameResolution !== null && $nameResolution !== 'certain' && $nameResolution !== 'uncertain') {
			throw new \InvalidArgumentException("The name resolution must be 'certain' or 'uncertain', '$nameResolution' given.");
		} elseif ($unknown = array_diff_key($namespaces, ['functions' => true, 'constants' => true])) {
			throw new \InvalidArgumentException("The namespaces declare functions and constants, not '" . array_key_first($unknown) . "'.");
		}

		$this->groups = array_map(
			fn(string|Group $group) => $group instanceof Group
				? $group
				: (Group::tryFrom($group) ?? throw new \InvalidArgumentException(
					"Unknown group '$group'; the groups are " . implode(', ', array_column(Group::cases(), 'value')) . '.',
				)),
			$groups,
		);

		$this->nameResolution = $nameResolution;
		$this->namespaces = [
			'functions' => self::expandNames(SymbolKind::Function, $namespaces['functions'] ?? []),
			'constants' => self::expandNames(SymbolKind::Constant, $namespaces['constants'] ?? []),
		];
	}


	/**
	 * The key two spellings of one symbol share, the way PHP reads them: a function in any letter case, a constant
	 * in any letter case of its namespace but not of its own name.
	 * @internal
	 */
	public static function toSymbolKey(SymbolKind $kind, string $name): string
	{
		$name = ltrim($name, '\\');
		$pos = (int) strrpos($name, '\\');
		return $kind === SymbolKind::Constant
			? strtolower(substr($name, 0, $pos)) . substr($name, $pos)
			: strtolower($name);
	}


	/**
	 * The fully qualified names the items of a use statement of the kind stand for, a group for each of its names.
	 * @param  list<string>  $items
	 * @return list<string>
	 */
	private static function expandNames(SymbolKind $kind, array $items): array
	{
		$keyword = $kind === SymbolKind::Function ? 'function' : 'const';
		$names = [];
		foreach ($items as $item) {
			try {
				$statement = (new Parser)->parseStatement("use $keyword $item;");
			} catch (ParseException $e) {
				throw new \InvalidArgumentException("'$item' is not a name the way a use $keyword statement writes it: {$e->getMessage()}", previous: $e);
			}

			foreach ($statement instanceof UseNode ? $statement->items->getItems() : [] as $use) {
				$names[] = match (true) {
					$use->alias !== null => throw new \InvalidArgumentException("'$item' gives the $keyword an alias, which says nothing about what a namespace declares."),
					!str_contains($use->fullName, '\\') => throw new \InvalidArgumentException("'$use->fullName' is in no namespace, and a global $keyword needs no listing."),
					default => $use->fullName,
				};
			}
		}

		return $names;
	}
}
