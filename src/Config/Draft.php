<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\Engine\Helpers;
use Nette\Neon\Neon;
use function count, is_array;


/**
 * A configuration as `dresscode migrate config` writes it, as `dresscode.neon` or as `dresscode.php`. The decisions are
 * written in the order of the sections of the core, each value as the configuration writes it.
 * @internal
 */
final class Draft
{
	/** @var list<string> */
	private array $use = [];

	/** @var array<string, mixed>  path => value as the configuration writes it */
	private array $decisions = [];


	/** @param  list<string>  $presets */
	public function setUse(array $presets): static
	{
		$this->use = $presets;
		return $this;
	}


	public function setDecision(string $path, mixed $value): static
	{
		$this->decisions[$path] = $value;
		return $this;
	}


	/** The configuration as `dresscode.neon`, the sections apart. */
	public function toNeon(): string
	{
		$sections = array_filter([$this->renderUse(), $this->renderDecisions()], fn(string $section) => $section !== '');
		return $sections ? implode("\n", $sections) : "use: []\n";
	}


	/** The configuration as the source of `dresscode.php`. */
	public function toPhp(): string
	{
		$arguments = $this->use ? "\tuse: " . self::export($this->use, 1) . ",\n" : '';
		if ($decisions = $this->buildTree()) {
			$arguments .= "\tdecisions: " . self::export($decisions, 1) . ",\n";
		}

		return "<?php declare(strict_types=1);\n\nuse DressCode\\Config;\n\n"
			. ($arguments === '' ? "return new Config;\n" : "return new Config(\n$arguments);\n");
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


	/** The key `use`, on one line for a single entry. */
	private function renderUse(): string
	{
		return match (count($this->use)) {
			0 => '',
			1 => "use: {$this->use[0]}\n",
			default => "use:\n" . implode('', array_map(fn(string $entry) => "\t- $entry\n", $this->use)),
		};
	}


	/** The decisions under their sections. */
	private function renderDecisions(): string
	{
		if (!$this->decisions) {
			return '';
		}

		$catalogue = self::getCatalogue();
		$out = '';
		$previous = [];
		foreach ($this->sortDecisions() as $path => $value) {
			$links = explode('.', $path);
			$key = array_pop($links);
			$common = 0;
			while (isset($previous[$common], $links[$common]) && $previous[$common] === $links[$common]) {
				$common++;
			}

			if ($common === 0 && $out !== '') {
				$out .= "\n";
			}

			for ($depth = $common; $depth < count($links); $depth++) {
				$out .= str_repeat("\t", $depth) . "$links[$depth]:\n";
			}

			$written = $catalogue->find($path)?->accept($value)->toWrittenText() ?? Neon::encode($value);
			$out .= str_repeat("\t", count($links)) . "$key: $written\n";
			$previous = $links;
		}

		return $out;
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
