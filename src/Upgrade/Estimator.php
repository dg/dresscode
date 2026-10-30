<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\{Config, ConfigurationException, Violation};
use DressCode\Config\{DataPackageCatalogue, InactiveReason, PackageDiscovery, ProjectPackages, ResolvedProject, RunnerFactory};
use DressCode\Engine\{Helpers, Runner, RunResult, WorkerPool};
use DressCode\Measuring\{FileSample, NamespaceDeclarations};
use DressCode\Reporters\NullReporter;
use DressCode\Rules\Upgrading\UnseenReferencesRule;
use Nette\Utils\Finder;
use function count;


/**
 * What moving the code of a project to the targets costs, changing nothing: one check over the decisions the upgrade
 * fixes by, the sets of `Sets` and the upgrading data of the packages, the targets laid before the data are cut for
 * them. Every place is told by its topic, by what the run can do about it and by its phase: an entry of upgrading data
 * is of the bridge where the lowest version the project allows today reaches the version its fix is right from, any
 * other place where its decision runs for the project as it is today.
 * @internal
 */
final readonly class Estimator
{
	/** the sets an upgrade fixes by, a decision of two of them belonging to the first */
	public const Sets = ['deprecations', 'modernizations', 'cleanup'];

	/** what of them the check runs: of `cleanup` only the code for the versions given up, never the rest of its hygiene */
	private const Fixes = ['deprecations', 'modernizations', 'hygiene.uselessVersionCheck'];

	/** the decisions of what an upgrade cannot see, which the estimate counts apart from the topics */
	private const Unseen = 'upgrading.unseen';

	/** the extensions of the files of a project that hold what an upgrade may have to change too, none of them PHP */
	private const OtherExtensions = ['latte', 'twig', 'neon', 'yaml', 'yml', 'xml'];


	public function __construct(
		private Project $project,
		/** the check takes the types of the code from PHPStan, which the caller knows is installed */
		private bool $types = false,
		/** the catalogue of Packagist is taken as the cache remembers it */
		private bool $offline = false,
		/** @var ?\Closure(EstimateRun, int): ?WorkerPool  given the check and the count of files, the workers repeating it */
		private ?\Closure $createWorkers = null,
		/** @var ?\Closure(string): void  told what is being measured */
		private ?\Closure $onProgress = null,
	) {
	}


	/**
	 * The targets of an upgrade nobody named a version for: PHP up to the newest version its upgrading data know, and
	 * without `$phpOnly` every library whose data know a version newer than the project allows, PHP alone where none does.
	 * @return list<Target>
	 */
	public function proposeTargets(bool $phpOnly = false): array
	{
		$project = new RunnerFactory()->readProjectPackages($this->project->config, $this->project->root);
		$known = PackageDiscovery::discover($project)->findKnownVersions();
		$php = Target::php($known[PackageDiscovery::Php] ?? Config::DefaultPhpVersion);
		$targets = [];
		foreach ($phpOnly ? [] : $known as $library => $version) {
			$lowest = $project->findVersion($library);
			if ($lowest === null || version_compare($version, $lowest, '>')) {
				$targets[] = new Target($library, $version);
			}
		}

		return $targets ?: [$php];
	}


	/**
	 * @param  list<Target>  $targets
	 * @param  list<string>  $paths  the files and directories measured within the configured paths, those paths where empty
	 * @throws ConfigurationException
	 */
	public function estimate(array $targets, array $paths = []): Estimate
	{
		$versions = array_column(array_map(fn(Target $target) => [$target->package, $target->version], $targets), 1, 0);
		$this->onProgress?->__invoke('looking for files');
		$discovered = PackageDiscovery::discover(
			new RunnerFactory()->readProjectPackages(
				$this->project->config,
				$this->project->root,
				commandLine: new Config(targets: $versions),
			),
		);
		$plugins = $discovered->findDataPlugins();
		// the project as it is today, narrowed to nothing, which tells what runs before the raise, and whose runner finds the files
		$todayRun = new EstimateRun($this->project->createLayer($plugins, types: false), []);
		[$todayFactory, $today] = $todayRun->resolve($this->project->config, $this->project->root);
		$runner = $todayFactory->createRunner($today, cache: false);
		$scope = $paths ? $runner->narrowPaths($paths, $this->project->config->paths) : $this->project->config->paths;
		$files = $runner->findFiles($scope, configured: $this->project->config->paths);
		$generated = array_filter(
			$files,
			fn(string $file) => FileSample::isGenerated(Helpers::toAbsolutePath($file, $this->project->root)),
		);
		$files = array_values(array_diff($files, $generated));

		$declarations = null;
		$warnings = [];
		if ($this->project->config->nameResolution === null) {
			$this->onProgress?->__invoke('reading the declarations of the namespaces');
			$declarations = NamespaceDeclarations::collect($this->project->root, $files);
			if ($declarations->unparsed !== []) {
				$warnings[] = 'The names are not resolved for certain: ' . count($declarations->unparsed) . ' files do not parse.';
				$declarations = null;
			}
		}

		$run = new EstimateRun(
			$this->project->createLayer(
				$plugins,
				$this->types,
				$versions,
				$declarations,
				[self::Unseen => ['classNameString' => 'reported', 'dynamicAccess' => 'reported']],
			),
			[...self::Fixes, self::Unseen, ...$discovered->findReachingPackages()],
		);
		[$factory, $resolution] = $run->resolve($this->project->config, $this->project->root);
		$this->onProgress?->__invoke('checking ' . count($files) . ' files');
		$result = $factory->createRunner($resolution, cache: false)
			->run($files, false, new NullReporter, $this->createWorkers?->__invoke($run, count($files)));
		[$topics, $unseen] = self::collectTopics($result, $resolution, $today);

		return new Estimate(
			$targets,
			$topics,
			count($files),
			new BlindSpots(
				$unseen[UnseenReferencesRule::ClassNameString] ?? 0,
				$unseen[UnseenReferencesRule::DynamicAccess] ?? 0,
				$this->countOtherFiles($runner, $scope),
				count($generated),
			),
			$this->findBlockers($targets),
			$this->findMissingData(),
			$discovered->findKnownVersions(),
			$declarations !== null,
			$resolution->resolvedConfig->typeAnalysis !== null,
			[...array_keys($resolution->warnings), ...$warnings],
		);
	}


	/**
	 * The topics of the places the check found, the jump first and each phase by its count of places, and the places of
	 * what the upgrade cannot see by their decision.
	 * @return array{list<Topic>, array<string, int>}
	 */
	private static function collectTopics(RunResult $result, ResolvedProject $resolution, ResolvedProject $today): array
	{
		$sets = array_map(fn(string $set) => array_flip($resolution->expandName($set)->paths), array_combine(self::Sets, self::Sets));
		$topics = $unseen = [];
		foreach ($result->files as $summary) {
			foreach ($summary->violations as $violation) {
				if (str_starts_with($violation->decision, self::Unseen . '.')) {
					$unseen[$violation->decision] = ($unseen[$violation->decision] ?? 0) + 1;
					continue;
				}

				$phase = self::findPhase($violation, $today);
				$name = $violation->source->library ?? $violation->decision;
				$key = "$name|$phase->value";
				$topics[$key] ??= [
					'name' => $name,
					'set' => self::findSet($violation, $sets),
					'phase' => $phase,
					'files' => [],
					'places' => array_fill_keys(array_column(Fixability::cases(), 'value'), 0),
				];
				$topics[$key]['files'][$summary->path] = true;
				$topics[$key]['places'][Fixability::of($violation)->value]++;
			}
		}

		$topics = array_map(
			fn(array $topic) => new Topic($topic['name'], $topic['set'], $topic['phase'], count($topic['files']), $topic['places']),
			array_values($topics),
		);
		usort($topics, fn(Topic $a, Topic $b) => [$b->phase === Phase::Jump, $b->countPlaces(), $a->name]
			<=> [$a->phase === Phase::Jump, $a->countPlaces(), $b->name]);
		return [$topics, $unseen];
	}


	/**
	 * The phase of a place: an entry of upgrading data by the version its fix is right from against the lowest version the
	 * project allows today, any other place by whether its decision runs for the project as it is today, which one the
	 * data of a newer version turn on does not.
	 */
	private static function findPhase(Violation $violation, ResolvedProject $today): Phase
	{
		$source = $violation->source;
		if ($source?->library !== null && $source->since !== null) {
			$lowest = $today->projectPackages->findVersion($source->library);
			return $lowest !== null && version_compare($lowest, $source->from ?? $source->since, '>=') ? Phase::Bridge : Phase::Jump;
		}

		$decision = $today->resolvedConfig->decisions[$violation->decision] ?? null;
		return $decision === null || ($decision->inactive !== null && $decision->inactive !== InactiveReason::OnlyOverride)
			? Phase::Jump
			: Phase::Bridge;
	}


	/**
	 * The set of a place: the first whose decisions its decision is one of, and `deprecations`, which lays them, for an
	 * entry of upgrading data.
	 * @param  array<string, array<string, int>>  $sets  set => its decisions as keys
	 */
	private static function findSet(Violation $violation, array $sets): string
	{
		return $violation->source === null
			? array_find_key($sets, fn(array $paths) => isset($paths[$violation->decision])) ?? self::Sets[0]
			: self::Sets[0];
	}


	/**
	 * The files of the scope no rule reads that an upgrade may have to change as well, a template or a configuration, by
	 * their extension.
	 * @param  list<string>  $scope
	 * @return array<string, int>  extension => count
	 */
	private function countOtherFiles(Runner $runner, array $scope): array
	{
		$masks = array_values(array_map(
			fn(string $extension) => "*.$extension",
			array_diff(self::OtherExtensions, $this->project->config->fileExtensions),
		));
		$counts = [];
		foreach ($scope as $path) {
			$path = Helpers::toAbsolutePath($path, $this->project->root);
			foreach (is_dir($path) ? Finder::findFiles($masks)->from($path) : [] as $file) {
				$file = Helpers::canonicalizePath($file->getPathname());
				if (!$runner->excludes($file, $this->project->config->paths)) {
					$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
					$counts[$extension] = ($counts[$extension] ?? 0) + 1;
				}
			}
		}

		ksort($counts);
		return $counts;
	}


	/**
	 * What the lock of the project holds that refuses each target; nothing where the project has no lock.
	 * @param  list<Target>  $targets
	 * @return list<Blockers>
	 */
	private function findBlockers(array $targets): array
	{
		$project = ProjectPackages::read($this->project->root);
		if ($project->rootPath === null || !is_file("$project->rootPath/composer.lock")) {
			return [];
		}

		$this->onProgress?->__invoke('asking Composer what the lock refuses');
		$composer = new Composer($project->rootPath, $project->rootName);
		return array_map($composer->findBlockers(...), $targets);
	}


	/**
	 * The packages of upgrading data about a library the project requires, which neither the project nor DressCode has.
	 * @return array<string, list<string>>  package => the libraries of the project it is about
	 */
	private function findMissingData(): array
	{
		$project = ProjectPackages::read($this->project->root);
		$catalogue = DataPackageCatalogue::load($this->project->getCacheDir(), $this->offline);
		$missing = [];
		foreach (PackageDiscovery::findMissingDataPackages($project, $catalogue) as $package) {
			$missing[$package] = array_values(array_filter(
				array_keys($project->required),
				fn(string $library) => PackageDiscovery::isAbout($catalogue[$package], $library),
			));
		}

		return $missing;
	}
}
