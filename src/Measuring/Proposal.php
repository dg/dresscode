<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use DressCode\{Analyses, Config, ConfigurationException, Decision, Profile, Rule};
use DressCode\Config\{Composer, ConfigResolver, CorePlugin, Loader, ResolvedConfig, RunnerFactory};
use DressCode\Rules\ControlFlow\MultilineConditionRule;
use DressCode\Rules\Literals\StringQuotesRule;
use DressCode\Rules\Whitespace\IndentationRule;
use Nette\Utils\FileSystem;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{ParseException, Parser, SymbolKind};
use function count, is_array, is_string, sprintf;
use const SORT_FLAG_CASE, SORT_STRING;


/**
 * The configuration `dresscode init` proposes for a project: the scope it finds, the standard it is given,
 * and the decisions that can be measured, each written as a value only where the code already says so.
 * Whatever it does not measure it leaves to the standard, but the types a project with PHPStan has, and it never
 * writes `fixRisky`.
 * @internal
 */
final readonly class Proposal
{
	/**
	 * Where the code of a project usually is, beside what the autoload says: the test suites the autoload-dev
	 * rarely names and the entry points it cannot name at all. A name with a `*` is a pattern of the root.
	 */
	public const Paths = ['src', 'tests', 'test', 'app', 'lib', 'bin', 'cron', 'www*'];

	/** directories of files nobody writes by hand, left out on top of the default exclusions */
	public const GeneratedDirs = ['fixtures', 'Fixtures', 'expected'];

	/** the indentation units measured, as the configuration writes them */
	private const Indents = ['tab', '4', '2'];


	private function __construct(
		/** @var list<string> */
		public array $presets,
		/** the presets were given, not the default */
		public bool $presetsGiven,
		/** @var list<string> */
		public array $paths,
		/** @var list<string> */
		public array $fileExtensions,
		/** @var list<string> */
		public array $excludePaths,
		/** the files of the scope */
		public int $total,
		public Measurement $indent,
		public Measurement $quotes,
		/** the shape of the conditions on several lines */
		public Measurement $conditions,
		/** @var list<string>  fully qualified names of the functions the namespaces of the scope declare */
		public array $namespacedFunctions,
		/** @var list<string>  fully qualified names of the constants the namespaces of the scope declare */
		public array $namespacedConstants,
		/** @var list<string>  the files of the scope that do not parse, whose declarations are therefore unknown */
		public array $unparsed,
		/** the project has phpstan/phpstan, so the rules can get the types of the code */
		public bool $phpstanInstalled,
		/** the files everything above was measured on */
		public FileSample $sample,
		private Survey $survey,
	) {
	}


	/**
	 * @param  ?list<string>  $presets  the standard, named as the configuration names it; null for the default
	 * @throws ConfigurationException
	 */
	public static function measure(string $root, ?array $presets = null): self
	{
		$paths = self::findPaths($root);
		$factory = new RunnerFactory;
		$scope = $factory->createRunner($factory->resolve(new Config(fileExtensions: ['php', 'phpt']), $root), cache: false);
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
		$files = array_values(array_filter($files, fn(string $file) => in_array(pathinfo($file, PATHINFO_EXTENSION), $extensions, true)));
		$sample = FileSample::pick($root, $files);
		$survey = new Survey($root, $sample->files, new Config(excludePaths: $excludePaths, fileExtensions: $extensions));

		$indents = [];
		foreach (self::Indents as $indent) {
			$indents[$indent] = new Profile(decisions: ['indentation' => ['unit' => self::toUnit($indent)]]);
		}

		[$functions, $constants, $unparsed] = self::collectNamespacedDeclarations($root, $files);
		$installed = self::findInstalledPackages($root);
		return new self(
			$presets ?? [CorePlugin::Standards[0]],
			$presets !== null,
			$paths,
			$extensions,
			$excludePaths,
			count($files),
			$survey->measureFiles(IndentationRule::class, $indents),
			$survey->measurePlaces(StringQuotesRule::class, [
				'single' => new Profile(decisions: ['literals' => ['quotes' => 'single']]),
				'double' => new Profile(decisions: ['literals' => ['quotes' => 'double']]),
			], 'strings'),
			$survey->measurePlaces(
				MultilineConditionRule::class,
				[
					'perLine' => new Profile(decisions: ['multiline' => ['shape' => ['condition' => 'perLine']]]),
					'compact' => new Profile(decisions: ['multiline' => ['shape' => ['condition' => 'compact']]]),
				],
				'conditions',
				new Profile(decisions: ['multiline' => ['shape' => ['condition' => ['perLine', 'compact']]]]),
			),
			$functions,
			$constants,
			$unparsed,
			isset($installed['phpstan/phpstan']),
			$sample,
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
		$paths = Composer::detectAutoloadPaths(Composer::findFile($root), $root);
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
				if ($other !== $path && ($other === '.' || str_starts_with("$path/", "$other/"))) {
					continue 2;
				}
			}

			$scope[] = $path;
		}

		sort($scope, SORT_STRING);
		return $scope ?: ['.'];
	}


	/**
	 * The functions and constants the namespaces of the scope declare, read from every file and not from the sample,
	 * because a declaration left out would be taken as global where nothing reports it. Every file naming a namespace
	 * or `define()` is parsed, since a declaration can stand anywhere a statement can; one that does not parse is listed,
	 * its declarations unknown.
	 * @param  list<string>  $files
	 * @return array{list<string>, list<string>, list<string>}  functions, constants and the files that do not parse
	 */
	private static function collectNamespacedDeclarations(string $root, array $files): array
	{
		$parser = new Parser;
		$names = [SymbolKind::Function->name => [], SymbolKind::Constant->name => []];
		$unparsed = [];
		foreach ($files as $file) {
			$code = @file_get_contents(RunnerFactory::toAbsolutePath($file, $root)); // @ file may have been deleted meanwhile
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
				$names[$declaration->kind->name][ConfigResolver::toSymbolKey($declaration->kind, $declaration->name)] ??= $declaration->name;
			}
		}

		$lists = [];
		foreach ([SymbolKind::Function, SymbolKind::Constant] as $kind) {
			$list = array_values($names[$kind->name]);
			sort($list, SORT_STRING | SORT_FLAG_CASE);
			$lists[] = $list;
		}

		return [$lists[0], $lists[1], $unparsed];
	}


	/**
	 * The packages the project has, told by the lock file beside its composer.json or, without one, by what Composer
	 * installed.
	 * @return array<string, true>
	 */
	private static function findInstalledPackages(string $root): array
	{
		$composer = Composer::findFile($root);
		$installed = [];
		foreach ([$composer === null ? null : dirname($composer) . '/composer.lock', "$root/vendor/composer/installed.json"] as $file) {
			$data = Composer::read($file);
			if ($data === null) {
				continue;
			}

			$packages = array_is_list($data) ? $data : [...(array) ($data['packages'] ?? []), ...(array) ($data['packages-dev'] ?? [])];
			foreach ($packages as $package) {
				if (is_array($package) && isset($package['name'])) {
					$installed[(string) $package['name']] = true;
				}
			}

			break;
		}

		return $installed;
	}


	/**
	 * The configuration as data, the same the text says; with a standard of its own what that standard would
	 * come to over the same decisions, which is what a price is measured on.
	 * @param  ?list<string>  $presets
	 */
	public function toConfig(?array $presets = null): Config
	{
		$indent = $this->indent->findPrevailing();
		$quotes = $this->quotes->findPrevailing();
		$shape = $this->findConditionShape();
		return new Config(
			use: $presets ?? $this->presets,
			namespaces: ['functions' => $this->namespacedFunctions, 'constants' => $this->namespacedConstants],
			nameResolution: 'certain',
			typeAnalysis: $this->phpstanInstalled ? 'phpstan' : null,
			paths: $this->paths,
			excludePaths: $this->excludePaths,
			fileExtensions: $this->fileExtensions,
			decisions: array_filter([
				'indentation' => $indent === null ? null : ['unit' => self::toUnit($indent)],
				'multiline' => $shape === null ? null : ['shape' => ['condition' => $shape]],
				'literals' => $this->quotes->opportunities ? ['quotes' => $quotes ?? 'keep'] : null,
			]),
		);
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
				$this->countSampled(),
				$this->total,
			),
			self::writeUse(
				$this->presets,
				$this->presetsGiven ? '' : '  # not chosen by measure; the other complete standards are ' . self::describeOthers(),
			),
		];

		$lists = '';
		foreach (['functions' => $this->namespacedFunctions, 'constants' => $this->namespacedConstants] as $key => $names) {
			$lists .= $names ? "\t$key:\n" . implode('', array_map(fn(string $name) => "\t\t- $name\n", $names)) : '';
		}

		$sections[] = match (true) {
			$this->unparsed !== [] => "# {$this->describeUnparsed()}, so the namespaces may declare more than is listed here\n"
				. "# and an unqualified name in a namespace is not resolved for certain; once every file parses, init writes\n"
				. "# nameResolution: certain\n",
			$lists === '' => "# the namespaces of the scope declare no function and no constant, so an unqualified name in a namespace\n"
				. "# is resolved for certain; one declared later is reported until it is listed\nnameResolution: certain\n",
			default => "# the namespaces of the scope declare these functions and constants and no others, so an unqualified name\n"
				. "# in a namespace is resolved for certain; one declared later is reported until it is listed\nnameResolution: certain\n",
		} . ($lists === '' ? '' : "namespaces:\n$lists");

		if ($this->phpstanInstalled) {
			$sections[] = "# phpstan/phpstan is installed, so the rules get the types of the code: a fix the type of a value decides\n"
				. "# is made where the types tell it is safe, and the rules that need the types run\ntypeAnalysis: phpstan\n";
		}

		$sections[] = "paths:\n" . implode('', array_map(fn(string $path) => "\t- $path\n", $this->paths));
		if ($this->excludePaths) {
			$sections[] = "excludePaths:\n" . implode('', array_map(fn(string $path) => "\t- $path\n", $this->excludePaths));
		}

		if ($this->fileExtensions !== ['php']) {
			$sections[] = "fileExtensions:\n" . implode('', array_map(fn(string $ext) => "\t- $ext\n", $this->fileExtensions));
		}

		$indent = $this->indent->findPrevailing();
		if ($indent !== null) {
			$sections[] = self::writeDecision(IndentationRule::class, 'indentation.unit', $indent === 'tab' ? 'tab' : "'" . self::toUnit($indent) . "'", $this->indent->describe());
		} elseif ($this->indent->opportunities) {
			$sections[] = sprintf("# indentation.unit: %s; no value reaches %d%%, so the standard decides\n", $this->indent->describe(), 100 * Measurement::Threshold);
		}

		$shape = $this->findConditionShape();
		if ($shape !== null) {
			$sections[] = self::writeDecision(
				MultilineConditionRule::class,
				'multiline.shape.condition',
				is_array($shape) ? '[' . implode(', ', $shape) . ']' : $shape,
				$this->conditions->describe(),
			);
		}

		if ($this->quotes->opportunities) {
			// quotes have no tolerance, and a value half of the strings disagree with would rewrite them
			$sections[] = self::writeDecision(StringQuotesRule::class, 'literals.quotes', $this->quotes->findPrevailing() ?? 'keep', $this->quotes->describe());
		}

		return implode("\n", $sections);
	}


	/**
	 * The key of a decision under its section, what the catalogue says of it above and what was measured beside it.
	 * @param  class-string<Rule>  $rule
	 */
	private static function writeDecision(string $rule, string $path, string $value, string $measured): string
	{
		$decision = array_find(Config\Catalogue::collectDecisions($rule), fn(Decision $decision) => $decision->path === $path)
			?? throw new \LogicException("Rule `$rule` does not declare `$path`.");
		$links = explode('.', $path);
		$key = array_pop($links);
		$out = '';
		foreach ($links as $depth => $link) {
			$out .= str_repeat("\t", $depth) . "$link:\n";
		}

		// a line never breaks inside code
		$comment = $decision->description . '. Values: ' . $decision->describeValues();
		$comment = preg_replace_callback('~`[^`]*`~', fn(array $m) => str_replace(' ', "\0", $m[0]), $comment);
		$comment = str_replace("\0", ' ', wordwrap($comment, 110, "\n"));
		$indent = str_repeat("\t", count($links));
		return $out . preg_replace('~^~m', "$indent# ", $comment) . "\n$indent$key: $value  # $measured\n";
	}


	/**
	 * The key `use`, on one line for a single entry, the comment following the standard.
	 * @param  list<string>  $entries
	 */
	private static function writeUse(array $entries, string $comment): string
	{
		return count($entries) === 1
			? "use: $entries[0]$comment\n"
			: "use:\n" . implode('', array_map(fn(string $entry, int $i) => "\t- $entry" . ($i === 0 ? $comment : '') . "\n", $entries, array_keys($entries)));
	}


	/** What the namespaces of the scope declare, for the report of init. */
	public function describeNamespaces(): string
	{
		$counts = fn(int $count, string $noun) => match ($count) {
			0 => "no $noun",
			1 => "1 $noun",
			default => "$count {$noun}s",
		};
		return $counts(count($this->namespacedFunctions), 'function') . ' and ' . $counts(count($this->namespacedConstants), 'constant')
			. ' declared, ' . ($this->unparsed !== [] ? 'not resolved for certain, ' . $this->describeUnparsed() : 'resolved for certain');
	}


	/** The files that do not parse, the first of them by name. */
	private function describeUnparsed(): string
	{
		$others = count($this->unparsed) - 1;
		return $this->unparsed[0] . match ($others) {
			0 => ' does not parse',
			1 => ' and 1 other file do not parse',
			default => " and $others other files do not parse",
		};
	}


	/**
	 * How many files of the sample the proposal would change, and how many a rule fails in.
	 * @return array{int, int}
	 */
	public function countChanged(): array
	{
		return $this->survey->countChanged($this->toConfig());
	}


	/**
	 * What each complete standard would cost over the same decisions, the cheapest first: the one number about
	 * the standards that measures a consequence and not a likeness, which is why it is told and no standard is
	 * chosen by it. A price comes with the files a rule of the standard fails in.
	 * @return array<string, array{int, int}>
	 * @throws ConfigurationException
	 */
	public function countChangedByStandard(): array
	{
		$prices = [];
		foreach (CorePlugin::Standards as $standard) {
			$prices[$standard] = $this->survey->countChanged($this->toConfig([$standard]));
		}

		asort($prices);
		return $prices;
	}


	public function countSampled(): int
	{
		return count($this->sample->files);
	}


	/**
	 * Resolves the configuration the proposal writes the way a run reads it and checks it against what was measured,
	 * before it is anywhere a run could read it.
	 * @throws \LogicException
	 * @throws ConfigurationException
	 */
	public function verify(string $root): void
	{
		$temp = sys_get_temp_dir() . '/dresscode-init-' . uniqid() . '.neon';
		FileSystem::write($temp, $this->toNeon());
		try {
			$this->checkResolution((new RunnerFactory)->resolve(Loader::loadFile($temp), $root)->resolvedConfig);
		} finally {
			@unlink($temp); // @ file may have been deleted meanwhile
		}
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
		$quotesValue = ($resolved->decisions['literals.quotes'] ?? null)?->value;
		$conditionValue = ($resolved->decisions['multiline.shape.condition'] ?? null)?->value;
		$problem = match (true) {
			$indent !== null && $resolved->indent !== ($indent === 'tab' ? "\t" : str_repeat(' ', (int) $indent)) => "the indentation is not `$indent`",
			$quotes !== null && ($quotesValue === null || $quotesValue->isKept() || $quotesValue->getWord() !== $quotes) => "`literals.quotes` is not `$quotes`",
			$quotes === null && $this->quotes->opportunities && $quotesValue?->isKept() === false => '`literals.quotes` is not `keep`',
			$shape !== null && $conditionValue?->toData() !== (is_string($shape) && $shape !== 'keep' ? [$shape] : $shape) => '`multiline.shape.condition` has another shape',
			($resolved->nameResolution === 'certain') !== ($this->unparsed === []) => 'the name resolution is not what the files that parse allow',
			array_diff($this->namespacedFunctions, array_keys($resolved->namespacedFunctions)) !== [] => 'a function the namespaces declare is not listed',
			array_diff($this->namespacedConstants, array_keys($resolved->namespacedConstants)) !== [] => 'a constant the namespaces declare is not listed',
			// a DressCode without PHPStan beside it goes without the types the project can give
			$this->phpstanInstalled !== ($resolved->typeAnalysis === 'phpstan') && Analyses\PhpStan::isAvailable() => 'the types are not what the installed packages allow',
			default => null,
		};
		if ($problem !== null) {
			throw new \LogicException("The configuration `dresscode init` wrote does not say what it measured: $problem.");
		}
	}


	/** What the file says of the complete standards beside the one it writes by itself: "psr12, nette and symfony". */
	private static function describeOthers(): string
	{
		$others = array_slice(CorePlugin::Standards, 1);
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


	/** The unit of the indentation as the decision writes it, `tab` or `4 spaces`. */
	private static function toUnit(string $indent): string
	{
		return $indent === 'tab' ? 'tab' : "$indent spaces";
	}
}
