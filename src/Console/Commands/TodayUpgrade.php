<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\{Config, ConfigurationException};
use DressCode\Config\{PackageDiscovery, ResolvedProject, RunnerFactory, Versions};
use DressCode\Console\CommandContext;
use DressCode\Upgrade\Project;
use Nette\CommandLine\ParseResult;


/**
 * The run of `upgrade` over the code of a project that only moves to the lowest versions its constraints allow, after
 * raising them: `fix` with the set `deprecations`, the packages of upgrading data and the types of the code, in the style
 * the project keeps.
 * @internal
 */
final readonly class TodayUpgrade
{
	/** the command line that runs it */
	public const Command = 'upgrade --today';


	public function __construct(
		private CommandContext $context,
		/** also the set `modernizations` */
		private bool $modernize,
		/** @var list<string>  those of `--only`, which the parent gives a worker too */
		private array $only,
	) {
	}


	/**
	 * The project of the run: its configuration, or the directory as a whole where it has none, with the sets of the
	 * upgrade laid over the presets it uses and beneath its own decisions and overrides, and the plugins of the packages
	 * of upgrading data with the types of the code and the names resolved for certain over it all, narrowed by `--only`,
	 * or where it has a configuration to the decisions its style keeps and to the packages whose data reach the project.
	 * @return array{RunnerFactory, ResolvedProject, ?string}
	 * @throws ConfigurationException
	 */
	public function resolve(ParseResult $args, ConfigOptions $configuration): array
	{
		['config' => $config, 'root' => $root, 'file' => $file, 'commandLine' => $commandLine]
			= $this->context->loadConfig($args, $configuration, new Config(paths: ['.']));
		$project = new Project($config, $root, $file, $commandLine);
		$factory = new RunnerFactory;
		$discovered = PackageDiscovery::discover($factory->readProjectPackages($config, $root));
		$sets = $this->modernize ? ['deprecations', 'modernizations'] : ['deprecations'];
		$layer = $project->createLayer($discovered->findDataPlugins(), types: true, declarations: $project->collectDeclarations());
		$only = $this->only;
		if ($file !== null && !$only) {
			$only = [...$project->findKeptDecisions(), ...$sets, ...$discovered->findReachingPackages()];
		}

		return [$factory, $factory->resolve($config, $root, $layer, $only ?: null, laid: $sets), $file];
	}


	/**
	 * What a worker gets on its command line beyond that of `check`: the run, the sets, and what the parent narrowed the
	 * run to, unless `--only` did, which goes over as it is.
	 * @return list<string>
	 */
	public function getWorkerOptions(ResolvedProject $resolution): array
	{
		return [
			'--today',
			...$this->modernize ? ['--modernize'] : [],
			...$this->only ? [] : array_map(fn(string $name) => "--only=$name", $resolution->only ?? []),
		];
	}


	/**
	 * What the run upgrades by: the packages of upgrading data, those installed newer than the lowest version the
	 * project allows, whose declarations then say nothing of what that one deprecates, and those it allows in any version,
	 * whose data are reported and never written.
	 */
	public function writePreface(ResolvedProject $resolution): void
	{
		$out = $this->context->out;
		$project = $resolution->projectPackages;
		$packages = array_diff(
			array_unique(array_map(fn(array $data) => $data[0]->package, $resolution->upgradingData)),
			[PackageDiscovery::Php],
		);
		$out->writeLine(
			$out->color('gray', 'Upgrading  ') . (implode(', ', $packages) ?: 'PHP alone, no installed library has upgrading data'),
		);

		$newer = [];
		foreach ($project->findNewerInstalled() as $package => [$installed, $lowest]) {
			$newer[] = "$package " . Versions::formatVersion($installed) . " installed, $lowest required";
		}

		if ($newer !== []) {
			$out->writeLine(
				$out->color('gray', 'Lowest     ')
				. implode(', ', $newer)
				. '; `composer update --prefer-lowest` lets the upgrade see what the lowest versions deprecate',
			);
		}

		$unbounded = array_filter($packages, $project->isUnbounded(...));
		if ($unbounded !== []) {
			$out->writeLine(
				$out->color('gray', 'Reported   ')
				. implode(', ', $unbounded)
				. ': not required with a lower bound, so the upgrading data are reported, never written',
			);
		}
	}
}
