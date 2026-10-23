<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\Engine\Helpers;
use DressCode\Override;
use Nette\Neon\Neon;
use function count, is_array, strlen;


/**
 * A configuration as `dresscode init` and `dresscode migrate config` write it, each key with what it rests on: as
 * `dresscode.neon` with the comments, as `dresscode.php` without them, and as the data a check reads. The decisions are
 * written in the order of the sections of the core, each value as the configuration writes it.
 * @internal
 */
final class Draft
{
	/** a list on one line longer than this is written one item a line */
	private const InlineWidth = 100;

	/** @var list<string> */
	private array $use = [];

	private string $useComment = '';

	/** @var ?array{list<string>, array<string, string>, list<string>}  paths, path left out => why, extensions */
	private ?array $scope = null;

	/** @var ?array{list<string>, list<string>, string}  functions, constants, the comment */
	private ?array $namespaces = null;

	private ?string $typeAnalysis = null;

	/** @var array<string, mixed>  path => value as the configuration writes it */
	private array $decisions = [];

	/** @var array<string, string>  path => what the value rests on */
	private array $comments = [];

	/** @var list<array{Override, string}>  the override, what it rests on */
	private array $overrides = [];


	public function __construct(
		/** the comment the file opens with */
		private readonly string $header = '',
	) {
	}


	/** @param  list<string>  $presets */
	public function setUse(array $presets, string $comment = ''): static
	{
		$this->use = $presets;
		$this->useComment = $comment;
		return $this;
	}


	/**
	 * @param  list<string>  $paths
	 * @param  array<string, string>  $excludePaths  path => why it is left out
	 * @param  list<string>  $fileExtensions
	 */
	public function setScope(array $paths, array $excludePaths = [], array $fileExtensions = ['php']): static
	{
		$this->scope = [$paths, $excludePaths, $fileExtensions];
		return $this;
	}


	/**
	 * The functions and constants the namespaces declare and no others, so that a name is resolved for certain.
	 * @param  list<string>  $functions
	 * @param  list<string>  $constants
	 */
	public function setNamespaces(array $functions, array $constants, string $comment = ''): static
	{
		$this->namespaces = [$functions, $constants, $comment];
		return $this;
	}


	/** The rules get the types of the code from PHPStan. */
	public function setTypeAnalysis(string $comment = ''): static
	{
		$this->typeAnalysis = $comment;
		return $this;
	}


	public function setDecision(string $path, mixed $value, string $comment = ''): static
	{
		$this->decisions[$path] = $value;
		$this->comments[$path] = $comment;
		return $this;
	}


	public function addOverride(Override $override, string $comment = ''): static
	{
		$this->overrides[] = [$override, $comment];
		return $this;
	}


	/**
	 * The configuration as the data a check reads.
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		$config = $this->buildSettings() + $this->buildTree();
		foreach ($this->overrides as [$override]) {
			$config['overrides'][] = ['paths' => $override->paths, ...$override->profile->decisions];
		}

		return $config;
	}


	/** The configuration as `dresscode.neon`, each key with its comment, the sections apart. */
	public function toNeon(): string
	{
		$sections = [
			($this->header === '' ? '' : preg_replace('~^~m', '# ', $this->header) . "\n") . $this->renderUse(),
			$this->renderScope(),
			$this->renderNamespaces(),
			$this->typeAnalysis === null ? '' : 'typeAnalysis: phpstan' . self::formatComment($this->typeAnalysis) . "\n",
			$this->renderDecisions(),
			$this->renderOverrides(),
		];
		$sections = array_filter($sections, fn(string $section) => $section !== '');
		return $sections ? implode("\n", $sections) : "use: []\n";
	}


	/** The configuration as the source of `dresscode.php`. */
	public function toPhp(): string
	{
		$arguments = '';
		foreach ($this->buildSettings() as $key => $value) {
			$arguments .= "\t$key: " . self::export($value, 1) . ",\n";
		}

		if ($decisions = $this->buildTree()) {
			$arguments .= "\tdecisions: " . self::export($decisions, 1) . ",\n";
		}

		if ($this->overrides) {
			$arguments .= "\toverrides: [\n";
			foreach ($this->overrides as [$override]) {
				$arguments .= "\t\tnew Override(" . self::export($override->paths)
					. ', new Profile(decisions: ' . self::export($override->profile->decisions, 2) . ")),\n";
			}

			$arguments .= "\t],\n";
		}

		return "<?php declare(strict_types=1);\n\nuse DressCode\\" . ($this->overrides ? '{Config, Override, Profile}' : 'Config') . ";\n\n"
			. ($arguments === '' ? "return new Config;\n" : "return new Config(\n$arguments);\n");
	}


	/**
	 * The keys of the configuration beside the decisions and the overrides, those with their default value left out.
	 * @return array<string, mixed>
	 */
	private function buildSettings(): array
	{
		[$paths, $excluded, $extensions] = $this->scope ?? [[], [], ['php']];
		return array_filter([
			'use' => $this->use,
			'paths' => $paths,
			'excludePaths' => array_keys($excluded),
			'fileExtensions' => $extensions === ['php'] ? [] : $extensions,
			'nameResolution' => $this->namespaces === null ? null : 'certain',
			'namespaces' => $this->namespaces === null ? [] : ['functions' => $this->namespaces[0], 'constants' => $this->namespaces[1]],
			'typeAnalysis' => $this->typeAnalysis === null ? null : 'phpstan',
		], fn($value) => $value !== null && $value !== []);
	}


	/**
	 * The decisions as the tree of sections, in the order of the sections of the core.
	 * @return array<string, mixed>
	 */
	private function buildTree(): array
	{
		$tree = [];
		foreach ($this->sortDecisions() as $path => $value) {
			$tree = Helpers::placeValue($tree, $path, $value);
		}

		return $tree;
	}


	/**
	 * The decisions in the order of the catalogue, a section by the order of the core and every group below it where
	 * its first decision stands, so that a group is written together.
	 * @return array<string, mixed>
	 */
	private function sortDecisions(): array
	{
		$rank = [];
		foreach (array_keys(self::getCatalogue()->getDecisions()) as $index => $path) {
			for ($prefix = $path; $prefix !== ''; $prefix = substr($prefix, 0, (int) strrpos($prefix, '.'))) {
				$rank[$prefix] ??= $index;
			}
		}

		$sections = array_flip(Catalogue::CoreSections);
		$key = function (string $path) use ($rank, $sections): string {
			$links = explode('.', $path);
			$key = sprintf('%06d', $sections[$links[0]] ?? count($sections));
			for ($i = 2; $i <= count($links); $i++) {
				$key .= sprintf('.%06d', $rank[implode('.', array_slice($links, 0, $i))] ?? 999999);
			}

			return "$key $path";
		};
		$decisions = $this->decisions;
		uksort($decisions, fn(string $a, string $b) => $key($a) <=> $key($b));
		return $decisions;
	}


	private static function getCatalogue(): Catalogue
	{
		static $catalogue;
		return $catalogue ??= new ConfigResolver(new PluginRegistry)->getCatalogue();
	}


	/** The key `use`, on one line for a single entry, the comment following the standard. */
	private function renderUse(): string
	{
		$comment = self::formatComment($this->useComment);
		return match (count($this->use)) {
			0 => '',
			1 => "use: {$this->use[0]}$comment\n",
			default => "use:\n" . implode('', array_map(
				fn(string $entry, int $i) => "\t- $entry" . ($i === 0 ? $comment : '') . "\n",
				$this->use,
				array_keys($this->use),
			)),
		};
	}


	/** The paths, those left out each with the reason beside it, and the extensions where they are not only `php`. */
	private function renderScope(): string
	{
		if ($this->scope === null) {
			return '';
		}

		[$paths, $excluded, $extensions] = $this->scope;
		$out = 'paths: ' . self::renderList($paths) . "\n";
		if ($excluded) {
			$width = max(array_map(strlen(...), array_keys($excluded)));
			$out .= "excludePaths:\n" . implode('', array_map(
				fn(string $path, string $why) => "\t- " . str_pad($path, $width) . "   # $why\n",
				array_keys($excluded),
				$excluded,
			));
		}

		return $out . ($extensions === ['php'] ? '' : 'fileExtensions: ' . self::renderList($extensions) . "\n");
	}


	private function renderNamespaces(): string
	{
		if ($this->namespaces === null) {
			return '';
		}

		[$functions, $constants, $comment] = $this->namespaces;
		$lists = '';
		foreach (['functions' => $functions, 'constants' => $constants] as $key => $names) {
			$lists .= $names ? "\t$key:\n" . implode('', array_map(fn(string $name) => "\t\t- $name\n", $names)) : '';
		}

		return ($comment === '' ? '' : preg_replace('~^~m', '# ', $comment) . "\n")
			. "nameResolution: certain\n"
			. ($lists === '' ? '' : "namespaces:\n$lists");
	}


	/** The decisions under their sections, each with its comment, the comments aligned. */
	private function renderDecisions(): string
	{
		if (!$this->decisions) {
			return '';
		}

		$catalogue = self::getCatalogue();
		$lines = [];
		$previous = [];
		foreach ($this->sortDecisions() as $path => $value) {
			$links = explode('.', $path);
			$key = array_pop($links);
			$common = 0;
			while (isset($previous[$common], $links[$common]) && $previous[$common] === $links[$common]) {
				$common++;
			}

			if ($common === 0 && $lines) {
				$lines[] = ['', ''];
			}

			for ($depth = $common; $depth < count($links); $depth++) {
				$lines[] = [str_repeat("\t", $depth) . "$links[$depth]:", ''];
			}

			$written = $catalogue->find($path)?->accept($value)->toWrittenText() ?? Neon::encode($value);
			$lines[] = [str_repeat("\t", count($links)) . "$key: $written", $this->comments[$path]];
			$previous = $links;
		}

		$width = fn(string $line) => strlen(str_replace("\t", '    ', $line));
		$column = max(0, ...array_map(fn(array $line) => $width($line[0]), $lines)) + 3;
		return implode('', array_map(
			fn(array $line) => $line[1] === ''
				? "$line[0]\n"
				: $line[0] . str_repeat(' ', $column - $width($line[0])) . "# $line[1]\n",
			$lines,
		));
	}


	/** The overrides, each with what it rests on above it. */
	private function renderOverrides(): string
	{
		if (!$this->overrides) {
			return '';
		}

		$out = "overrides:\n";
		foreach ($this->overrides as [$override, $comment]) {
			// an entry is written without the blank lines NEON puts between the keys of a block
			$entry = rtrim(
				(string) preg_replace(
					'~\n+~',
					"\n",
					Neon::encode(['paths' => $override->paths, ...$override->profile->decisions], blockMode: true),
				),
			);
			$out .= ($comment === '' ? '' : "\t# $comment\n") . "\t-\n" . preg_replace('~^~m', "\t\t", $entry) . "\n";
		}

		return $out;
	}


	/** @param  list<string>  $items */
	private static function renderList(array $items): string
	{
		$inline = '[' . implode(', ', $items) . ']';
		return strlen($inline) <= self::InlineWidth ? $inline : "\n" . implode('', array_map(fn(string $item) => "\t- $item\n", $items));
	}


	private static function formatComment(string $comment): string
	{
		return $comment === '' ? '' : "   # $comment";
	}


	private static function export(mixed $value, int $level = 0): string
	{
		if ($value === null) {
			return 'null';
		} elseif (!is_array($value)) {
			return var_export($value, return: true);
		} elseif (array_is_list($value) && !array_any($value, fn($item) => is_array($item))) {
			return '[' . implode(', ', array_map(fn($item) => self::export($item), $value)) . ']';
		}

		$indent = str_repeat("\t", $level + 1);
		$list = array_is_list($value);
		$items = '';
		foreach ($value as $key => $item) {
			$items .= $indent . ($list ? '' : var_export($key, return: true) . ' => ') . self::export($item, $level + 1) . ",\n";
		}

		return "[\n$items" . str_repeat("\t", $level) . ']';
	}
}
