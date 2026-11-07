<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Config, ConfigurationException};
use DressCode\Engine\Helpers;
use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use function count, is_array, is_string, strlen;


/**
 * A configuration as `dresscode migrate config` writes it, each key with what it rests on: as `dresscode.neon` with the
 * comments and as `dresscode.php` without them. The decisions are written in the order of the sections of the core, each
 * value as the configuration writes it.
 * @internal
 */
final class Draft
{
	/** a list on one line longer than this is written one item a line */
	private const InlineWidth = 100;

	/** @var list<string> */
	private array $use = [];

	/** @var ?array{list<string>, array<string, string>, list<string>}  paths, path left out => why, extensions */
	private ?array $scope = null;

	/** @var array<string, mixed>  path => value as the configuration writes it */
	private array $decisions = [];

	/** @var array<string, string>  path => what the value rests on */
	private array $comments = [];


	public function __construct(
		/** the comment the file opens with */
		private readonly string $header = '',
		/** the comment the file closes with */
		private readonly string $footer = '',
	) {
	}


	/** @param  list<string>  $presets */
	public function setUse(array $presets): static
	{
		$this->use = $presets;
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


	public function setDecision(string $path, mixed $value, string $comment = ''): static
	{
		$this->decisions[$path] = $value;
		$this->comments[$path] = $comment;
		return $this;
	}


	/**
	 * The configuration written in the format given, `neon` or `php`, resolved the way a run reads it before it is
	 * anywhere a run could read it and checked to say what the draft does.
	 * @throws \LogicException  where it says something else
	 * @throws ConfigurationException
	 */
	public function renderChecked(string $root, string $format = 'neon'): string
	{
		$text = $format === 'php' ? $this->toPhp() : $this->toNeon();
		$temp = sys_get_temp_dir() . '/dresscode-draft-' . uniqid(more_entropy: true) . ".$format";
		FileSystem::write($temp, $text);
		try {
			$config = Loader::loadFile($temp);
			$this->checkLoaded($config);
			$this->checkResolution((new RunnerFactory)->resolve($config, $root)->resolvedConfig);
		} finally {
			@unlink($temp); // @ file may have been deleted meanwhile
		}

		return $text;
	}


	/**
	 * What the configuration loads as must be what the draft says of the presets and the scope, which a run reads before
	 * it resolves anything.
	 * @throws \LogicException
	 */
	private function checkLoaded(Config $config): void
	{
		[$paths, $excluded, $extensions] = $this->scope ?? [[], [], ['php']];
		$use = [...$config->use, ...array_map(fn($plugin) => is_string($plugin) ? $plugin : $plugin::class, $config->plugins)];
		$expected = $this->use;
		sort($use);
		sort($expected);
		$problem = match (true) {
			$use !== $expected => '`use` names other presets',
			$config->paths !== $paths => '`paths` are other paths',
			$config->excludePaths !== array_values(array_unique([...Config::DefaultExcludePaths, ...array_keys($excluded)]))
				=> '`excludePaths` are other paths',
			$config->fileExtensions !== $extensions => '`fileExtensions` are other extensions',
			default => null,
		};
		if ($problem !== null) {
			throw new \LogicException("The configuration written does not say what it was made to: $problem.");
		}
	}


	/**
	 * What a run makes of the configuration must be what the draft says of its decisions; anything else is a
	 * configuration that says one thing and does another.
	 * @throws \LogicException
	 */
	public function checkResolution(ResolvedConfig $resolved): void
	{
		$catalogue = self::getCatalogue();
		foreach ($this->decisions as $path => $value) {
			$decision = $catalogue->find($path);
			if ($decision === null) { // a decision of a plugin, which the core does not know how to accept
				continue;
			}

			$expected = $decision->accept($value);
			$actual = ($resolved->decisions[$path] ?? null)?->value;
			if ($actual === null || $actual->isKept() !== $expected->isKept() || $actual->toData() !== $expected->toData()) {
				throw new \LogicException("The configuration written does not say what it was made to: `$path` resolves to another value.");
			}
		}
	}


	/** The configuration as `dresscode.neon`, each key with its comment, the sections apart. */
	public function toNeon(): string
	{
		$sections = [
			($this->header === '' ? '' : self::renderComment($this->header, '#')) . $this->renderUse(),
			$this->renderScope(),
			$this->renderDecisions(),
		];
		$sections = array_filter($sections, fn(string $section) => $section !== '');
		return $this->appendFooter($sections ? implode("\n", $sections) : "use: []\n", '#');
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

		return $this->appendFooter(
			"<?php declare(strict_types=1);\n\n"
			. ($this->header === '' ? '' : self::renderComment($this->header, '//') . "\n")
			. "use DressCode\\Config;\n\n"
			. ($arguments === '' ? "return new Config;\n" : "return new Config(\n$arguments);\n"),
			'//',
		);
	}


	private function appendFooter(string $text, string $mark): string
	{
		return $this->footer === '' ? $text : "$text\n" . self::renderComment($this->footer, $mark);
	}


	/** The text as a comment, each of its lines opened with the mark of a comment. */
	private static function renderComment(string $text, string $mark): string
	{
		return implode('', array_map(fn(string $line) => rtrim("$mark $line") . "\n", explode("\n", $text)));
	}


	/**
	 * The keys of the configuration beside the decisions, those with their default value left out.
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
		], fn(array $value) => $value !== []);
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
			1 => 'use: ' . Neon::encode($this->use[0]) . "\n",
			default => "use:\n" . implode('', array_map(fn(string $entry) => "\t- " . Neon::encode($entry) . "\n", $this->use)),
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
			$written = array_map(fn(string $path) => Neon::encode($path), array_keys($excluded));
			$width = max(array_map(strlen(...), $written));
			$out .= "excludePaths:\n" . implode('', array_map(
				fn(string $path, string $why) => "\t- " . str_pad($path, $width) . "   # $why\n",
				$written,
				$excluded,
			));
		}

		return $out . ($extensions === ['php'] ? '' : 'fileExtensions: ' . self::renderList($extensions) . "\n");
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


	/** @param  list<string>  $items */
	private static function renderList(array $items): string
	{
		$items = array_map(fn(string $item) => Neon::encode($item), $items);
		$inline = '[' . implode(', ', $items) . ']';
		return strlen($inline) <= self::InlineWidth ? $inline : "\n" . implode('', array_map(fn(string $item) => "\t- $item\n", $items));
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
