<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\{Config, ConfigurationException};
use DressCode\Config\{PackageDiscovery, ResolvedProject, RunnerFactory, Versions};
use DressCode\Console\{CommandContext, Markup};
use DressCode\Engine\Helpers;
use DressCode\Upgrade\{Project, UpgradeFiles};
use Nette\CommandLine\ParseResult;
use function count;


/**
 * The run of `upgrade today` over the code of a project that only moves to the lowest versions its constraints allow, after
 * raising them: `fix` with the set `deprecations`, the packages of upgrading data and the types of the code, in the style
 * the project keeps.
 * @internal
 */
final readonly class TodayUpgrade
{
	/** the command line that runs it */
	public const Command = 'upgrade today';


	public function __construct(
		private CommandContext $context,
		/** also the set `modernizations` */
		private bool $modernize,
		/** @var list<string>  those of `--only`, which the parent gives a worker too */
		private array $only,
	) {
	}


	/**
	 * The project of the run: its configuration, or the scope `init` would write where it has none, with the sets of the
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
		$project = Project::fromConfig($config, $root, $file, $commandLine);
		$config = $project->config;
		$discovered = $project->discoverPackages();
		$sets = $this->modernize ? ['deprecations', 'modernizations'] : ['deprecations'];
		$names = $project->resolveNames();
		$layer = $project->createLayer($discovered->findDataPlugins(), $names->declarations);
		$only = $this->only;
		if ($file !== null && !$only) {
			$only = [...$project->measureSatisfiedRequirements(), ...$sets, ...$discovered->findReachingPackages()];
		}

		$factory = new RunnerFactory;
		$resolution = $factory->resolve($config, $root, $layer, $only ?: null, laid: $sets);
		return [$factory, $names->warning === null ? $resolution : $resolution->withWarning($names->warning), $file];
	}


	/**
	 * What a worker gets on its command line beyond that of `check`: the sets, and what the parent narrowed the run to,
	 * unless `--only` did, which goes over as it is.
	 * @return list<string>
	 */
	public function getWorkerOptions(ResolvedProject $resolution): array
	{
		return [
			...$this->modernize ? ['--modernize'] : [],
			...$this->only ? [] : array_map(fn(string $name) => "--only=$name", $resolution->only ?? []),
		];
	}


	/**
	 * What the run upgrades by: the packages of upgrading data, those installed newer than the lowest version the
	 * project allows, whose declarations then say nothing of what that one deprecates, and those it allows in any version,
	 * whose data and declarations say nothing at all.
	 */
	public function writePreface(ResolvedProject $resolution): void
	{
		$out = $this->context->out;
		$project = $resolution->projectPackages;
		$packages = array_diff(
			array_unique(array_map(fn(array $data) => $data[0]->package, $resolution->upgradingData)),
			[PackageDiscovery::Php],
		);
		$codes = fn(array $packages) => implode(', ', array_map(fn(string $package) => "`$package`", $packages));
		$out->writeLine(
			Markup::formatLine($out, 'Upgrading', $codes($packages) ?: 'PHP alone, no installed library has upgrading data'),
		);

		$newer = [];
		foreach ($project->findNewerInstalled() as $package => [$installed, $lowest]) {
			$newer[] = "`$package` " . Versions::formatVersion($installed) . " installed, $lowest required";
		}

		if ($newer !== []) {
			$out->writeLine(Markup::formatLine(
				$out,
				'Lowest',
				implode(', ', $newer),
				'; `composer update --prefer-lowest` lets the upgrade see what the lowest versions deprecate',
			));
		}

		$unbounded = array_filter($packages, $project->isUnbounded(...));
		if ($unbounded !== []) {
			$out->writeLine(Markup::formatLine(
				$out,
				'Unbounded',
				$codes($unbounded),
				': not required with a lower bound, so neither the upgrading data nor the deprecations say anything',
			));
		}
	}


	/**
	 * The files the run goes through, without those a generator wrote.
	 * @param  list<string>  $files  relative to the root, or absolute when outside it
	 * @return list<string>
	 */
	public function leaveOutGenerated(array $files, string $root, bool $preface): array
	{
		$found = UpgradeFiles::fromScope($root, $files);
		if ($preface && $found->generated !== []) {
			$out = $this->context->out;
			$out->writeLine(Markup::formatLine($out, 'Left out', Helpers::formatCount(count($found->generated), 'generated file')));
		}

		return $found->files;
	}
}
