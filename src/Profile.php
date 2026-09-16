<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use PhpSyntax\{Builder, ParseException, SymbolKind};
use PhpSyntax\Nodes\Statement\UseNode;
use function is_string;


/**
 * What decides how a file is processed: the presets it uses, the decisions, the version of PHP the code is written
 * for, what its namespaces declare, and the rules whose risky fixes are accepted or whose violations only warn. A
 * preset is a file of this shape under a name, an override is a profile for a part of the tree, and the configuration
 * is the one of the whole project. Where profiles meet, a value is the last one said and a list adds up.
 */
readonly class Profile
{
	/** @var list<string>  presets by name, or files of the same shape by their path relative to the root, laid below the profile in the order written */
	public array $use;

	/** @var array{functions: list<string>, constants: list<string>}  fully qualified names */
	public array $namespaces;

	/**
	 * `'certain'` says the namespaces declare nothing beyond the lists, so that an unqualified name no list names is global,
	 * a fix resting on that is not risky and a declaration missing from the lists is reported; `'uncertain'` takes such a
	 * name as global without knowing, which is the default
	 * @var 'certain'|'uncertain'|null
	 */
	public ?string $nameResolution;


	/**
	 * @param list<string|Plugin> $use  the presets; a plugin is the configuration's alone, which takes it out before
	 * @param array{functions?: list<string>, constants?: list<string>} $namespaces  functions and constants the namespaces
	 *   declare, each written as an item of a `use` statement writes it (`'Acme\helper'`, `'Acme\{format, parse}'`): an
	 *   unqualified call in a namespace reaches such a function before the global one, which no file that calls it shows
	 */
	public function __construct(
		array $use = [],
		/**
		 * what the code is written for: `php` => the version the rules target, major.minor with an optional patch, without
		 * one that of composer.json, else `Config::DefaultPhpVersion`; in the configuration of the project also a package
		 * => the version of it the code is written for
		 * @var array<string, string>
		 */
		public array $targets = [],
		array $namespaces = [],
		?string $nameResolution = null,
		/** @var list<string>  decisions, sections, presets or classes of rules whose fixes are made even where they may change what the code does */
		public array $fixRisky = [],
		/** @var list<string>  decisions, sections, presets or classes of rules whose violations only warn */
		public array $warnOnly = [],
		/**
		 * a comment the project already writes to say a line is meant as it is: pattern => the rules or decisions it
		 * silences where it stands, as `dresscode:ignore` does
		 * @var array<string, string|list<string>>
		 */
		public array $suppressionComments = [],
		/** @var array<string, mixed>  the decisions, a tree of sections the resolver reads against the catalogue */
		public array $decisions = [],
	) {
		if (isset($targets['php']) && !Config\Versions::isVersion($targets['php'])) {
			throw new \InvalidArgumentException("The PHP version must be written as `8.2`, `{$targets['php']}` given.");
		} elseif ($nameResolution !== null && $nameResolution !== 'certain' && $nameResolution !== 'uncertain') {
			throw new \InvalidArgumentException("The name resolution must be `certain` or `uncertain`, `$nameResolution` given.");
		} elseif ($unknown = array_diff_key($namespaces, ['functions' => true, 'constants' => true])) {
			throw new \InvalidArgumentException('The namespaces declare functions and constants, not `' . array_key_first($unknown) . '`.');
		} elseif ($reserved = array_intersect(array_keys($decisions), Config\Catalogue::ReservedKeys)) {
			throw new \InvalidArgumentException('The decisions are sections, and `' . reset($reserved) . '` is a key of the configuration.');
		}

		$this->use = array_map(fn(string|Plugin $entry): string => match (true) {
			$entry instanceof Plugin || is_subclass_of($entry, Plugin::class) => throw new \InvalidArgumentException('Plugin `' . (is_string($entry) ? $entry : $entry::class) . '` is used by the configuration of the project, never by a preset or an override.'),
			$entry === '' => throw new \InvalidArgumentException('`use` names a preset or the path of a file, an empty string given.'),
			default => $entry,
		}, $use);

		foreach ($suppressionComments as $pattern => $names) {
			if (@preg_match((string) $pattern, '') === false) { // @ an invalid pattern only warns
				throw new \InvalidArgumentException("`$pattern` in `suppressionComments` is not a regular expression, such as `~intentionally ==~`.");
			} elseif ($names === [] || $names === '') {
				throw new \InvalidArgumentException("`suppressionComments` names no rule for `$pattern`.");
			}
		}

		$this->nameResolution = $nameResolution;
		$this->namespaces = [
			'functions' => self::expandNames(SymbolKind::Function, $namespaces['functions'] ?? []),
			'constants' => self::expandNames(SymbolKind::Constant, $namespaces['constants'] ?? []),
		];
	}


	/**
	 * The fully qualified names the items of a `use` statement of the kind stand for, a group for each of its names.
	 * @param  list<string>  $items
	 * @return list<string>
	 */
	private static function expandNames(SymbolKind $kind, array $items): array
	{
		$keyword = $kind === SymbolKind::Function ? 'function' : 'const';
		$names = [];
		foreach ($items as $item) {
			try {
				$statement = (new Builder)->statement("use $keyword $item;");
			} catch (ParseException $e) {
				throw new \InvalidArgumentException("`$item` is not a name the way a `use $keyword` statement writes it: {$e->getMessage()}", previous: $e);
			}

			foreach ($statement instanceof UseNode ? $statement->items->getItems() : [] as $use) {
				$names[] = match (true) {
					$use->alias !== null => throw new \InvalidArgumentException("`$item` gives the $keyword an alias, which says nothing about what a namespace declares."),
					!str_contains($use->fullName, '\\') => throw new \InvalidArgumentException("`$use->fullName` is in no namespace, and a global $keyword needs no listing."),
					default => $use->fullName,
				};
			}
		}

		return $names;
	}
}
