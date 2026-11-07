<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\{Config, ConfigurationException, Violation};
use DressCode\Config\{DataPackageCatalogue, InactiveReason, PackageDiscovery, ProjectPackages, ResolvedProject, RunnerFactory, Versions};
use DressCode\Domains\Map;
use DressCode\Engine\{Helpers, Runner, RunResult, WorkerPool};
use DressCode\Reporters\CollectingReporter;
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
		/** @var ?\Closure(UpgradeCheck, int): ?WorkerPool  given the check and the count of files, the workers repeating it */
		private ?\Closure $createWorkers = null,
		/** @var ?\Closure(string): void  told what is being measured */
		private ?\Closure $onProgress = null,
	) {
	}


	/**
	 * The targets of an upgrade nobody named a version for: PHP up to the newest version its upgrading data know, and
	 * without `$phpOnly` every library whose data know a version newer than the project allows, PHP alone where none does;
	 * PHP not for a project on PHP 7, whose move to PHP 8 is no upgrade.
	 * @return list<Target>
	 */
	public function proposeTargets(bool $phpOnly = false): array
	{
		$factory = new RunnerFactory;
		$project = $factory->readProjectPackages($this->project->config, $this->project->root);
		$known = PackageDiscovery::discover($project)->findKnownVersions();
		$php = [Target::php($known[PackageDiscovery::Php] ?? Config::DefaultPhpVersion)];
		$today = Versions::findLowestVersion($factory->getPhpTarget($this->project->config, $this->project->root)[0]);
		if ($today !== null && version_compare($today, '8.0', '<')) {
			unset($known[PackageDiscovery::Php]);
			$php = [];
		}

		$targets = [];
		foreach ($phpOnly ? [] : $known as $library => $version) {
			$lowest = $project->findVersion($library);
			if ($lowest === null || version_compare($version, $lowest, '>')) {
				$targets[] = new Target($library, $version);
			}
		}

		return $targets ?: $php;
	}


	/**
	 * @param  list<Target>  $targets
	 * @param  list<string>  $paths  the files and directories measured within the configured paths, those paths where empty
	 * @throws ConfigurationException
	 */
	public function estimate(array $targets, array $paths = []): Estimate
	{
		$versions = Target::collectVersions($targets);
		$this->onProgress?->__invoke('looking for files');
		$discovered = $this->project->discoverPackages($versions);
		$plugins = $discovered->findDataPlugins();
		// the project as it is today, narrowed to nothing, which tells what runs before the raise, and whose runner finds the files
		$todayRun = new UpgradeCheck($this->project->createLayer($plugins, $this->types), [], self::Sets);
		[$todayFactory, $today] = $todayRun->resolve($this->project->config, $this->project->root);
		$runner = $todayFactory->createRunner($today, cache: false);
		$configured = $this->project->config->paths;
		$scope = $paths ? array_map($runner->files->relativize(...), $runner->files->narrowPaths($paths, $configured)) : $configured;
		$found = $this->project->findUpgradeFiles($scope);
		$files = $found->files;

		$this->onProgress?->__invoke('reading the declarations of the namespaces');
		// those of the whole scope, which a call within the paths named may reach
		$names = $this->project->resolveNames($paths ? null : $files);
		$warnings = $names->warning === null ? [] : [$names->warning];
		if (version_compare($today->resolvedConfig->phpVersion, '8.0', '<')) {
			$warnings[] = 'Upgrading to PHP 8.0 is not covered; fixes of PHP 8 constructs stay off for 7.x.';
		}

		$run = new UpgradeCheck(
			$this->project->createLayer(
				$plugins,
				$this->types,
				$versions,
				$names->declarations,
				[self::Unseen => ['classNameString' => 'reported', 'dynamicAccess' => 'reported']],
			),
			[...self::Fixes, self::Unseen, ...$discovered->findReachingPackages()],
			self::Sets,
			$this->project->configFile === null ? $this->project->config : null,
		);
		[$factory, $resolution] = $run->resolve($this->project->config, $this->project->root);
		$this->onProgress?->__invoke('checking ' . count($files) . ' files');
		$collected = new CollectingReporter;
		$result = $factory->createRunner($resolution, cache: false)
			->run($files, false, $collected, $this->createWorkers?->__invoke($run, count($files)));
		[$topics, $unseen] = self::collectTopics($collected->violations, $resolution, $today, $targets);
		$packages = ProjectPackages::read($this->project->root);
		$composer = Composer::forLockOf($packages);

		return new Estimate(
			targets: $targets,
			topics: $topics,
			files: count($files),
			blindSpots: new BlindSpots(
				$unseen[UnseenReferencesRule::ClassNameString] ?? 0,
				$unseen[UnseenReferencesRule::DynamicAccess] ?? 0,
				$this->countOtherFiles($runner, $scope),
				count($found->generated),
			),
			blockers: $composer === null ? [] : $this->findBlockers($composer, $targets),
			missingData: $this->findMissingData($packages),
			knownVersions: $discovered->findKnownVersions(),
			namesMeasured: $names->declarations !== null,
			types: $resolution->resolvedConfig->typeAnalysis !== null,
			warnings: [...array_keys($resolution->warnings), ...$warnings],
			moves: $composer === null || $this->offline ? null : $this->findMoves($composer, $packages, $targets),
			unprocessed: self::collectUnprocessed($result),
		);
	}


	/**
	 * The topics of the places the check found, the jump first and each phase by its count of places, and the places of
	 * what the upgrade cannot see by their decision.
	 * @param  array<string, list<Violation>>  $violations  the path => what the file violates
	 * @param  list<Target>  $targets
	 * @return array{list<Topic>, array<string, int>}
	 */
	private static function collectTopics(array $violations, ResolvedProject $resolution, ResolvedProject $today, array $targets): array
	{
		$sets = array_map(fn(string $set) => array_flip($resolution->expandName($set)->paths), array_combine(self::Sets, self::Sets));
		$topics = $unseen = [];
		foreach ($violations as $path => $list) {
			foreach ($list as $violation) {
				if (str_starts_with($violation->decision, self::Unseen . '.')) {
					$unseen[$violation->decision] = ($unseen[$violation->decision] ?? 0) + 1;
					continue;
				}

				$phase = self::findPhase($violation, $today);
				$library = $violation->source->library ?? self::findLibrary($violation->decision, $resolution);
				$name = $library ?? $violation->decision;
				$key = "$name|$phase->value";
				$topics[$key] ??= [
					'name' => $name,
					'set' => self::findSet($violation, $sets),
					'phase' => $phase,
					// a decision of no library is made by the target, where there is only one
					'constraint' => self::findConstraint(
						$library ?? (count($targets) === 1 ? $targets[0]->package : null),
						$phase,
						$targets,
						$today,
					),
					'files' => [],
					'places' => array_fill_keys(array_column(Fixability::cases(), 'value'), 0),
				];
				$topics[$key]['files'][$path] = true;
				$topics[$key]['places'][Fixability::of($violation)->value]++;
			}
		}

		$topics = array_map(
			fn(array $topic) => new Topic(
				$topic['name'],
				$topic['set'],
				$topic['phase'],
				count($topic['files']),
				$topic['places'],
				$topic['constraint'],
			),
			array_values($topics),
		);
		usort($topics, fn(Topic $a, Topic $b) => [$b->phase === Phase::Jump, $b->countPlaces(), $a->name]
			<=> [$a->phase === Phase::Jump, $a->countPlaces(), $b->name]);
		return [$topics, $unseen];
	}


	/**
	 * What the fixes of a topic of the library are right on: its constraint of today in the bridge, and in the jump the one
	 * the target raises it to.
	 * @param  list<Target>  $targets
	 */
	private static function findConstraint(?string $library, Phase $phase, array $targets, ResolvedProject $today): ?string
	{
		$required = $library === null ? null : $today->projectPackages->required[$library] ?? null;
		$target = array_find($targets, fn(Target $target) => $target->package === $library);
		return match ($phase) {
			Phase::Bridge => $required,
			Phase::Jump => $target === null ? null : Constraints::raiseConstraint(trim((string) $required), $target->version),
		};
	}


	/**
	 * The files the check could not process, by why.
	 * @return array<string, string>
	 */
	private static function collectUnprocessed(RunResult $result): array
	{
		$failed = [];
		foreach ($result->files as $summary) {
			if ($summary->syntaxError !== null || $summary->failure !== null) {
				$failed[$summary->path] = $summary->failure ?? "Syntax error: $summary->syntaxError";
			}
		}

		return $failed;
	}


	/**
	 * The library whose upgrading data alone fill the map of the decision, which a place of a rule that does not say
	 * which entry it reports belongs to; null for a decision no map of such data fills.
	 */
	private static function findLibrary(string $decision, ResolvedProject $resolution): ?string
	{
		$values = $resolution->resolvedConfig->values;
		$value = $values->has($decision) ? $values->get($decision) : null;
		if (!$value?->domain instanceof Map || $value->isKept()) {
			return null;
		}

		$libraries = array_unique(array_map(
			fn(string $key) => $values->getEntryOrigin($decision, $key)?->library,
			array_keys($value->getEntries()),
		));
		return count($libraries) === 1 ? reset($libraries) : null;
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
				if (!$runner->files->excludes($file, $this->project->config->paths)) {
					$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
					$counts[$extension] = ($counts[$extension] ?? 0) + 1;
				}
			}
		}

		ksort($counts);
		return $counts;
	}


	/**
	 * What the lock of the project holds that refuses each target.
	 * @param  list<Target>  $targets
	 * @return list<Blockers>
	 */
	private function findBlockers(Composer $composer, array $targets): array
	{
		$this->onProgress?->__invoke('asking Composer what the lock refuses');
		return array_map($composer->findBlockers(...), $targets);
	}


	/**
	 * What raising the constraints to the targets moves, as the dry run of Composer says, which needs the network.
	 * @param  list<Target>  $targets
	 */
	private function findMoves(Composer $composer, ProjectPackages $packages, array $targets): Moves
	{
		$this->onProgress?->__invoke('asking Composer what the update moves');
		$constraints = new Constraints($packages)->raise($targets);
		return $constraints === [] ? new Moves : $composer->findMoves($constraints);
	}


	/**
	 * The packages of upgrading data about a library the project requires, which neither the project nor DressCode has.
	 * @return array<string, list<string>>  package => the libraries of the project it is about
	 */
	private function findMissingData(ProjectPackages $project): array
	{
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
