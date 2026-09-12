<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\Config;
use DressCode\ConfigurationException;
use DressCode\Rules\ControlFlow\MultiLineConditionRule;
use DressCode\Rules\Literals\StringQuotesRule;
use DressCode\Rules\Whitespace\IndentationRule;
use function count, in_array, is_array, sprintf;


/**
 * The configuration `dresscode init` proposes for a project: the scope it finds, the standard it is given,
 * and the decisions that can be measured, each written as a value only where the code already says so.
 * Whatever it does not measure it leaves to the standard, and it never writes `risky`.
 * @internal
 */
final class Proposal
{
	/**
	 * Where the code of a project usually is, beside what the autoload says: the test suites the autoload-dev
	 * rarely names and the entry points it cannot name at all. A name with a `*` is a pattern of the root.
	 */
	public const Paths = ['src', 'tests', 'test', 'app', 'lib', 'bin', 'cron', 'www*'];

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
		/** the shape of the conditions on several lines */
		public readonly Measurement $conditions,
		private readonly Survey $survey,
	) {
	}


	/**
	 * @param  ?list<string>  $presets  the standard, named as the configuration names it; null for the default
	 * @throws ConfigurationException
	 */
	public static function measure(string $root, ?array $presets = null): self
	{
		$paths = self::findPaths($root);
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
			$survey->measurePlaces(
				MultiLineConditionRule::class,
				[
					'perLine' => Config::create()->enable(MultiLineConditionRule::class, ['shape' => 'perLine']),
					'compact' => Config::create()->enable(MultiLineConditionRule::class, ['shape' => 'compact']),
				],
				'conditions',
				Config::create()->enable(MultiLineConditionRule::class, ['shape' => ['perLine', 'compact']]),
			),
			$survey,
		);
	}


	/**
	 * The scope of a project: the directories its autoload names, the conventional ones the root has besides
	 * them, and the root itself when neither says anything. A directory that lies in another one is already
	 * in the scope, and one that does not exist would only make the run throw.
	 * @return list<string>
	 */
	public static function findPaths(string $root): array
	{
		$paths = RunnerFactory::detectAutoloadPaths(RunnerFactory::findComposerFile($root), $root);
		foreach (self::Paths as $name) {
			// a pattern is matched against the names of the root, never against a path of its own, or a root
			// holding a bracket or a star would be part of the pattern and quietly match nothing
			$found = str_contains($name, '*')
				? array_values(array_filter(scandir($root) ?: [], fn(string $entry) => fnmatch($name, $entry) && is_dir("$root/$entry")))
				: (is_dir("$root/$name") ? [$name] : []);
			$paths = [...$paths, ...$found];
		}

		$scope = [];
		foreach (array_unique($paths) as $path) {
			foreach ($paths as $other) {
				if ($other !== $path && self::isInside($path, $other)) {
					continue 2;
				}
			}

			$scope[] = $path;
		}

		sort($scope, SORT_STRING);
		return $scope ?: ['.'];
	}


	/** Whether the path lies in the directory, `.` standing for the whole root. */
	private static function isInside(string $path, string $directory): bool
	{
		return $directory === '.' || str_starts_with("$path/", "$directory/");
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

		$shape = $this->findConditionShape();
		if ($shape !== null) {
			$config->enable('multi-line-condition', ['shape' => $shape]);
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

		$rules = [];
		if ($this->quotes->opportunities) {
			// quotes have no tolerance, and a value half of the strings disagree with would rewrite them
			$rules[] = sprintf("\tstring-quotes: %s  # %s\n", $this->quotes->findPrevailing() ?? 'keep', $this->quotes->describe());
		}

		$shape = $this->findConditionShape();
		if ($shape !== null) {
			$rules[] = sprintf(
				"\tmulti-line-condition: {shape: %s}  # %s\n",
				is_array($shape) ? '[' . implode(', ', $shape) . ']' : $shape,
				$this->conditions->describe(),
			);
		}

		if ($rules) {
			$sections[] = "rules:\n" . implode('', $rules);
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
		$shape = $this->findConditionShape();
		$rule = $resolved->getRule('dresscode/string-quotes');
		$problem = match (true) {
			$indent !== null && $resolved->indent !== ($indent === 'tab' ? "\t" : str_repeat(' ', (int) $indent)) => "the indentation is not $indent",
			$quotes !== null && ($rule?->options['quotes'] ?? null) !== $quotes => "string-quotes is not $quotes",
			$quotes === null && $this->quotes->opportunities && $rule?->isActive() => 'string-quotes runs although it was kept',
			$shape !== null && ($resolved->getRule('dresscode/multi-line-condition')?->options['shape'] ?? null) !== $shape => 'multi-line-condition has another shape',
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


	/**
	 * The shape the conditions on several lines are written in: the one that reaches the threshold, else the
	 * shapes the code has, the commonest first, when together they do, else keep, since the code writes its
	 * conditions in no shape the rule knows; null when the sample has no such condition.
	 * @return string|list<string>|null
	 */
	private function findConditionShape(): string|array|null
	{
		return $this->conditions->opportunities
			? $this->conditions->findPrevailing() ?? $this->conditions->findTolerated() ?? 'keep'
			: null;
	}


	/** @return int|'tab' */
	private static function toIndent(string $indent): int|string
	{
		return $indent === 'tab' ? 'tab' : (int) $indent;
	}
}
