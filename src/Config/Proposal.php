<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\Config;
use DressCode\ConfigurationException;
use DressCode\Rules\Literals\StringQuotesRule;
use DressCode\Rules\Whitespace\IndentationRule;
use function count, in_array, sprintf;


/**
 * The configuration `dresscode init` proposes for a project: the scope it finds, the standard it is given,
 * and the decisions that can be measured, each written as a value only where the code already says so.
 * Whatever it does not measure it leaves to the standard, and it never writes `risky`.
 * @internal
 */
final class Proposal
{
	/** where the code of a project usually is; the ones the root has are the scope */
	public const Paths = ['src', 'tests', 'app', 'lib'];

	/** directories of files nobody writes by hand, left out on top of the default exclusions */
	public const GeneratedDirs = ['fixtures', 'Fixtures', 'expected'];

	/**
	 * The standard written when none is given; which one is nearest is not measured, because no measure tried
	 * told the standards apart where it was checked against what the projects declare.
	 */
	public const DefaultStandard = 'per';

	/** the indentation units measured, as the configuration writes them */
	private const Indents = ['tab', '4', '2'];

	/** the other complete standards, which the file names for its reader */
	private const OtherStandards = ['psr12', 'nette', 'symfony'];


	private function __construct(
		/** @var list<string> */
		public readonly array $presets,
		/** the presets were given, not the default */
		public readonly bool $given,
		/** @var list<string> */
		public readonly array $paths,
		/** @var list<string> */
		public readonly array $fileExtensions,
		/** @var list<string> */
		public readonly array $excludePaths,
		/** the files of the scope */
		public readonly int $total,
		public readonly Measurement $indent,
		public readonly Measurement $quotes,
		private readonly Survey $survey,
	) {
	}


	/**
	 * @param  ?list<string>  $presets  the standard, named as the configuration names it; null for the default
	 * @throws ConfigurationException
	 */
	public static function measure(string $root, ?array $presets = null): self
	{
		$paths = array_values(array_filter(self::Paths, fn(string $dir) => is_dir("$root/$dir"))) ?: ['.'];
		$scope = (new RunnerFactory)->createRunner(Config::create()->fileExtensions(['php', 'phpt']), $root, cache: false);
		$files = $generated = [];
		foreach ($scope->findFiles($paths) as $file) {
			$dirs = array_intersect(self::GeneratedDirs, explode('/', $file));
			if ($dirs) {
				$generated += array_flip($dirs);
			} else {
				$files[] = $file;
			}
		}

		$extensions = ['php', ...(array_filter($files, fn(string $file) => str_ends_with($file, '.phpt')) ? ['phpt'] : [])];
		$excludePaths = array_values(array_intersect(self::GeneratedDirs, array_keys($generated)));
		$survey = new Survey(
			$root,
			Survey::pick(array_values(array_filter($files, fn(string $file) => in_array(pathinfo($file, PATHINFO_EXTENSION), $extensions, true)))),
			Config::create()->fileExtensions($extensions)->excludePaths($excludePaths),
		);

		$indents = [];
		foreach (self::Indents as $indent) {
			$indents[$indent] = Config::create()->indent(self::toIndent($indent))->enable(IndentationRule::class);
		}

		return new self(
			$presets ?? [self::DefaultStandard],
			$presets !== null,
			$paths,
			$extensions,
			$excludePaths,
			count($files),
			$survey->measureFiles(IndentationRule::class, $indents),
			$survey->measurePlaces(StringQuotesRule::class, [
				'single' => Config::create()->enable(StringQuotesRule::class, 'single'),
				'double' => Config::create()->enable(StringQuotesRule::class, 'double'),
			], 'strings'),
			$survey,
		);
	}


	/** The configuration as data, the same the text says. */
	public function toConfig(): Config
	{
		$config = Config::create()
			->paths($this->paths)
			->fileExtensions($this->fileExtensions)
			->excludePaths($this->excludePaths);
		foreach ($this->presets as $preset) {
			$config->preset($preset);
		}

		$indent = $this->indent->findPrevailing();
		if ($indent !== null) {
			$config->indent(self::toIndent($indent));
		}

		$quotes = $this->quotes->findPrevailing();
		if ($quotes !== null) {
			$config->enable('string-quotes', $quotes);
		} elseif ($this->quotes->opportunities) {
			$config->disable('string-quotes');
		}

		return $config;
	}


	/**
	 * The configuration as dresscode.neon, every measured value with the share it has in the code. A decision
	 * no value of which reaches the threshold is not written as a value.
	 */
	public function toNeon(): string
	{
		$sections = [
			sprintf(
				"# Written by dresscode init from %d of the %d files. A number is the share of the places a decision\n"
				. "# appears in that already agree with its value; what the file does not name, the standard decides.\n",
				count($this->survey->files),
				$this->total,
			),
			"presets:\n" . implode('', array_map(fn(string $preset) => "\t- $preset\n", $this->presets))
			. ($this->given ? '' : sprintf("\t# not measured; the other complete standards are %s\n", self::describeOthers())),
		];

		$indent = $this->indent->findPrevailing();
		if ($indent !== null) {
			$sections[] = sprintf("indent: %s  # %s\n", $indent, $this->indent->describe());
		} elseif ($this->indent->opportunities) {
			$sections[] = sprintf("# indent: %s; no value reaches %d%%, so the standard decides\n", $this->indent->describe(), 100 * Measurement::Threshold);
		}

		$quotes = $this->quotes->findPrevailing();
		if ($this->quotes->opportunities) {
			// quotes have no tolerance, and a value half of the strings disagree with would rewrite them
			$sections[] = sprintf("rules:\n\tstring-quotes: %s  # %s\n", $quotes ?? 'keep', $this->quotes->describe());
		}

		$sections[] = "paths:\n" . implode('', array_map(fn(string $path) => "\t- $path\n", $this->paths));
		if ($this->excludePaths) {
			$sections[] = "excludePaths:\n" . implode('', array_map(fn(string $path) => "\t- $path\n", $this->excludePaths));
		}

		if ($this->fileExtensions !== ['php']) {
			$sections[] = "fileExtensions:\n" . implode('', array_map(fn(string $ext) => "\t- $ext\n", $this->fileExtensions));
		}

		return implode("\n", $sections);
	}


	/** How many files of the sample the proposal would change. */
	public function countChanged(): int
	{
		return $this->survey->countChanged($this->toConfig());
	}


	/** How many files the sample has. */
	public function countSampled(): int
	{
		return count($this->survey->files);
	}


	/**
	 * What the run makes of the written configuration must be what was measured; anything else is a proposal
	 * that says one thing and does another.
	 * @throws \LogicException
	 */
	public function checkResolution(ResolvedConfig $resolved): void
	{
		$indent = $this->indent->findPrevailing();
		$quotes = $this->quotes->findPrevailing();
		$rule = $resolved->getRule('dresscode/string-quotes');
		$problem = match (true) {
			$indent !== null && $resolved->indent !== ($indent === 'tab' ? "\t" : str_repeat(' ', (int) $indent)) => "the indentation is not $indent",
			$quotes !== null && ($rule?->options['quotes'] ?? null) !== $quotes => "string-quotes is not $quotes",
			$quotes === null && $this->quotes->opportunities && $rule?->isActive() => 'string-quotes runs although it was kept',
			default => null,
		};
		if ($problem !== null) {
			throw new \LogicException("The configuration dresscode init wrote does not say what it measured: $problem.");
		}
	}


	/** What the file and the report say of the standards init did not write: "psr12, nette and symfony". */
	public static function describeOthers(): string
	{
		$others = self::OtherStandards;
		$last = array_pop($others);
		return implode(', ', $others) . " and $last";
	}


	/** @return int|'tab' */
	private static function toIndent(string $indent): int|string
	{
		return $indent === 'tab' ? 'tab' : (int) $indent;
	}
}
