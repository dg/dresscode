<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use DressCode\Config;
use DressCode\Config\{ComposerJson, Draft, RunnerFactory};
use DressCode\Engine\{Helpers, ProcessRunner};
use Nette\Utils\Finder;
use function count;


/**
 * The files `dresscode migrate config` writes the configuration for, and those it leaves out with the reason. With git
 * the scope is what the repository tracks under the root; without it, what the autoload and the usual directories hold.
 * Either way the data of tests are left out and whatever the defaults exclude, and with git a file `linguist-generated`
 * marks.
 * @internal
 */
final class Scope
{
	/**
	 * Where the code of a project usually is, beside what the autoload says: the test suites the autoload-dev
	 * rarely names and the entry points it cannot name at all. A name with a `*` is a pattern of the root.
	 */
	public const Paths = ['src', 'tests', 'test', 'app', 'lib', 'bin', 'cron', 'www*'];

	/** the extensions of the code of a project, a test in the format PHPT being left out by its head */
	public const Extensions = ['php', 'phpt'];

	/** directories of the data of tests, which carry the style a test needs; a name stands for a segment at any depth */
	public const TestDataDirs = ['fixtures', 'Fixtures', 'expected'];

	/** how long git may take to answer, in seconds */
	private const GitTimeout = 60;

	/** @var ?array<string, LeftOutReason>  what `listExcludePaths()` gives, until the scope changes */
	private ?array $excluded = null;


	private function __construct(
		/** @var list<string>  sorted, relative to the root */
		public private(set) array $files,
		/** @var array<string, LeftOutReason>  file or directory relative to the root => why it is left out */
		public private(set) array $leftOut,
		/** @var ?list<string>  the paths the scope is written as; null for the entries of the root that hold its files */
		private readonly ?array $paths,
	) {
	}


	/**
	 * The files of the extensions the repository tracks under the root, those the default exclusions name dropped without
	 * a word, and the directories and files git ignores that hold PHP; null where there is no git, the root lies in no
	 * repository or the repository tracks none of its code, as a project in a directory another repository ignores.
	 * @param  list<string>  $extensions
	 */
	public static function fromGit(string $root, array $extensions): ?self
	{
		$tracked = self::runGit($root, ['ls-files', '-z', '--', ...array_map(fn(string $ext) => "*.$ext", $extensions)]);
		if ($tracked === null || $tracked === '') {
			return null;
		}

		$files = array_values(array_filter(
			explode("\0", $tracked),
			fn(string $file) => $file !== ''
				&& !Helpers::matchesAny(Config::DefaultExcludePaths, $file)
				&& is_file("$root/$file"),
		));
		sort($files, SORT_STRING);
		[$files, $leftOut] = self::classifyFiles($root, $files, self::findGeneratedAttribute($root, $files));

		$ignored = self::runGit($root, ['ls-files', '-z', '--others', '--ignored', '--exclude-standard', '--directory']) ?? '';
		foreach (explode("\0", $ignored) as $entry) {
			$entry = rtrim($entry, '/');
			if (
				$entry !== ''
				&& !Helpers::matchesAny(Config::DefaultExcludePaths, $entry)
				&& self::holdsCode("$root/$entry", $extensions)
			) {
				$leftOut[$entry] = LeftOutReason::Ignored;
			}
		}

		return new self($files, $leftOut, null);
	}


	/**
	 * The files of the extensions in the directories the autoload of the project names and in the usual ones beside them,
	 * for a project without git.
	 * @param  list<string>  $extensions
	 */
	public static function fromAutoload(string $root, array $extensions): self
	{
		return self::fromPaths($root, self::findAutoloadPaths($root), $extensions);
	}


	/**
	 * The scope of a project: what git tracks, else what the autoload names.
	 */
	public static function fromProject(string $root): self
	{
		return self::fromGit($root, self::Extensions) ?? self::fromAutoload($root, self::Extensions);
	}


	/**
	 * The files of the extensions under the paths given.
	 * @param  list<string>  $paths
	 * @param  list<string>  $extensions
	 */
	public static function fromPaths(string $root, array $paths, array $extensions): self
	{
		$factory = new RunnerFactory;
		$files = $factory->createFileFinder($factory->resolve(new Config(fileExtensions: $extensions), $root));
		[$files, $leftOut] = self::classifyFiles($root, $files->findFiles($paths), []);
		return new self($files, $leftOut, $paths);
	}


	/**
	 * The directories the autoload of the project names, the usual ones the root has besides them, and the root itself
	 * when neither says anything, with the files the autoload names one by one outside them. A directory that lies in
	 * another one is already in the scope, and one that does not exist would only make the run throw.
	 * @return list<string>
	 */
	private static function findAutoloadPaths(string $root): array
	{
		$composerFile = ComposerJson::findFile($root);
		$paths = ComposerJson::detectAutoloadPaths($composerFile, $root);
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

		$scope = $scope ?: ['.'];
		foreach (ComposerJson::detectAutoloadFiles($composerFile, $root) as $file) {
			if (is_file("$root/$file") && !array_any($scope, fn(string $path) => $path === '.' || str_starts_with($file, "$path/"))) {
				$scope[] = $file;
			}
		}

		sort($scope, SORT_STRING);
		return $scope;
	}


	/**
	 * The files of the scope and those left out of it for being a test in the format PHPT, which is no PHP, the data of
	 * tests or written by a generator.
	 * @param  list<string>  $files
	 * @param  array<string, true>  $generated  the files `.gitattributes` marks as generated
	 * @return array{list<string>, array<string, LeftOutReason>}
	 */
	private static function classifyFiles(string $root, array $files, array $generated): array
	{
		$kept = $leftOut = [];
		foreach ($files as $file) {
			$reason = match (true) {
				str_ends_with($file, '.phpt') && self::isPhptTest("$root/$file") => LeftOutReason::PhptTest,
				array_intersect(self::TestDataDirs, explode('/', $file)) !== [] => LeftOutReason::TestData,
				isset($generated[$file]) => LeftOutReason::Generated,
				default => null,
			};
			if ($reason === null) {
				$kept[] = $file;
			} else {
				$leftOut[$file] = $reason;
			}
		}

		return [$kept, $leftOut];
	}


	/** Whether the head of the file has a line of a section of PHPT, `--FILE--`, which no PHP file starts with. */
	private static function isPhptTest(string $file): bool
	{
		$head = @file_get_contents($file, length: 1024); // @ file may have been deleted meanwhile
		return $head !== false && preg_match('~^--[A-Z][A-Z_]*--\r?$~m', $head) === 1;
	}


	/**
	 * The extensions of the files of the scope, `php` first.
	 * @return list<string>
	 */
	public function listExtensions(): array
	{
		$extensions = array_unique(['php', ...array_map(fn(string $file) => pathinfo($file, PATHINFO_EXTENSION), $this->files)]);
		return array_values($extensions);
	}


	/**
	 * Leaves out the files given, those of them the scope has, for the reason given.
	 * @param  list<string>  $files  relative to the root
	 */
	public function leaveOut(array $files, LeftOutReason $reason): void
	{
		$files = array_intersect($this->files, $files);
		$this->files = array_values(array_diff($this->files, $files));
		$this->leftOut += array_fill_keys($files, $reason);
		$this->excluded = null;
	}


	/**
	 * Writes the scope into the configuration, its paths, what it excludes and its extensions.
	 */
	public function writeInto(Draft $draft): void
	{
		$draft->setScope(
			$this->listPaths(),
			array_map(fn(LeftOutReason $why) => $why->value, $this->listExcludePaths()),
			$this->listExtensions(),
		);
	}


	/**
	 * The paths the configuration names: the entries of the root that hold a file of the scope, directories and files
	 * alike, or what the autoload named.
	 * @return list<string>
	 */
	public function listPaths(): array
	{
		if ($this->paths !== null) {
			return $this->paths;
		}

		$entries = array_values(array_unique(array_map(fn(string $file) => explode('/', $file, 2)[0], $this->files)));
		sort($entries, SORT_STRING);
		return $entries ?: ['.'];
	}


	/**
	 * What the configuration excludes inside its paths, each with the reason: a directory of the data of tests by its
	 * name, which stands for it at any depth, a directory where everything in it is generated, and a file otherwise.
	 * @return array<string, LeftOutReason>  pattern => why
	 */
	public function listExcludePaths(): array
	{
		if ($this->excluded !== null) {
			return $this->excluded;
		}

		// the files below each directory, all and by reason; the ignored entries hold files git does not list
		$files = $byReason = [];
		foreach ([...$this->files, ...array_keys($this->leftOut)] as $file) {
			$reason = $this->leftOut[$file] ?? null;
			for ($dir = dirname($file); $reason !== LeftOutReason::Ignored && $dir !== '.'; $dir = dirname($dir)) {
				$files[$dir] = ($files[$dir] ?? 0) + 1;
				$key = $reason->name ?? '';
				$byReason[$dir][$key] = ($byReason[$dir][$key] ?? 0) + 1;
			}
		}

		$paths = $this->listPaths();
		$extensions = $this->listExtensions();
		$isInside = fn(string $path) => array_any($paths, fn(string $listed) => $listed === '.' || str_starts_with($path, "$listed/"));
		$excluded = [];
		foreach ($this->leftOut as $entry => $reason) {
			// a file of an extension the configuration does not take needs no exclusion
			$extension = pathinfo($entry, PATHINFO_EXTENSION);
			if (
				!($isInside($entry) || in_array($entry, $paths, true))
				|| ($extension !== '' && !in_array($extension, $extensions, true))
			) {
				continue;
			} elseif ($reason === LeftOutReason::TestData) {
				$entry = array_find(explode('/', $entry), fn(string $segment) => in_array($segment, self::TestDataDirs, true)) ?? $entry;
			}

			// a file generated stands for the highest directory inside the paths whose every file is generated
			while (
				$reason === LeftOutReason::Generated
				&& $isInside($dir = dirname($entry))
				&& ($byReason[$dir][$reason->name] ?? 0) === $files[$dir]
			) {
				$entry = $dir;
			}

			$excluded[$entry] = $reason;
		}

		ksort($excluded, SORT_STRING);
		return $this->excluded = $excluded;
	}


	/**
	 * The files `linguist-generated` of the attributes of git marks.
	 * @param  list<string>  $files
	 * @return array<string, true>
	 */
	private static function findGeneratedAttribute(string $root, array $files): array
	{
		$output = self::runGit($root, ['check-attr', '-z', '--stdin', 'linguist-generated'], implode("\0", $files));
		$fields = explode("\0", (string) $output);
		$generated = [];
		for ($i = 0; $i + 2 < count($fields); $i += 3) { // path, attribute, value
			if ($fields[$i + 2] === 'set' || $fields[$i + 2] === 'true') {
				$generated[$fields[$i]] = true;
			}
		}

		return $generated;
	}


	/**
	 * Whether the file is code of the extensions, or the directory holds some below the directories the defaults
	 * exclude.
	 * @param  list<string>  $extensions
	 */
	private static function holdsCode(string $path, array $extensions): bool
	{
		if (is_file($path)) {
			return in_array(pathinfo($path, PATHINFO_EXTENSION), $extensions, true);
		}

		$finder = Finder::findFiles(array_map(fn(string $ext) => "*.$ext", $extensions))
			->from($path)
			->descentFilter(fn(\SplFileInfo $dir) => !Helpers::matchesAny(Config::DefaultExcludePaths, $dir->getFilename()));
		foreach ($finder as $ignored) {
			return true;
		}

		return false;
	}


	/**
	 * What git writes for the command run in the root; null where it fails, the root lying in no repository or git
	 * missing.
	 * @param  list<string>  $args
	 */
	private static function runGit(string $root, array $args, string $input = ''): ?string
	{
		[$exit, $output] = ProcessRunner::run(['git', '-C', $root, ...$args], $root, self::GitTimeout, $input, errors: false);
		return $exit === 0 ? $output : null;
	}
}
