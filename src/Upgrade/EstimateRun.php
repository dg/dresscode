<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\{Config, ConfigurationException};
use DressCode\Config\{ResolvedProject, RunnerFactory};


/**
 * The check an estimate runs: the layer laid over the configuration and the names the run is narrowed to, kept as data
 * a worker repeats the check from.
 * @internal
 */
final readonly class EstimateRun
{
	public function __construct(
		public Config $layer,
		/** @var list<string>  the sets, the decisions of what the upgrade cannot see and the packages of upgrading data */
		public array $only,
	) {
	}


	/** @param  array{layer: array<string, mixed>, only: list<string>}  $data  as `toArray()` gave it */
	public static function fromArray(array $data): self
	{
		return new self(new Config(...$data['layer']), $data['only']);
	}


	/**
	 * The project of the check, the sets laid as `upgrade` lays them, without the types where the layer asks for none.
	 * @return array{RunnerFactory, ResolvedProject}
	 * @throws ConfigurationException
	 */
	public function resolve(Config $config, string $root): array
	{
		$factory = new RunnerFactory(withoutTypes: $this->layer->typeAnalysis === null);
		return [$factory, $factory->resolve($config, $root, $this->layer, $this->only, laid: Estimator::Sets)];
	}


	/** @return array{layer: array<string, mixed>, only: list<string>} */
	public function toArray(): array
	{
		$layer = $this->layer;
		return [
			'layer' => [
				'use' => [...$layer->plugins, ...$layer->use],
				'targets' => $layer->targets,
				'namespaces' => $layer->namespaces,
				'nameResolution' => $layer->nameResolution,
				'fixRisky' => $layer->fixRisky,
				'severity' => $layer->severity,
				'typeAnalysis' => $layer->typeAnalysis,
				'decisions' => $layer->decisions,
			],
			'only' => $this->only,
		];
	}
}
