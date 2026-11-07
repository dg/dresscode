<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\{Config, ConfigurationException};
use DressCode\Config\{ResolvedProject, RunnerFactory};
use Nette\Utils\FileSystem;


/**
 * A check an upgrade runs over the project: the layer laid over the configuration, the sets laid beneath its own
 * decisions and the names the check is narrowed to, kept in a file a worker of the estimate repeats it from.
 * @internal
 */
final readonly class UpgradeCheck
{
	public function __construct(
		public Config $layer,
		/** @var ?list<string>  the names the check is narrowed to, null for none, an empty list running nothing */
		public ?array $only,
		/** @var list<string>  the sets laid over the presets the configuration uses and beneath its own decisions */
		public array $laid,
		/** the scope of a project without a configuration file, which a worker would not find; null for one with it */
		public ?Config $scope = null,
	) {
	}


	/** @param  array{layer: array<string, mixed>, only: ?list<string>, laid: list<string>, scope: ?array{paths: list<string>, excludePaths: list<string>}}  $data  as `toArray()` gave it */
	private static function fromArray(array $data): self
	{
		return new self(
			new Config(...$data['layer']),
			$data['only'],
			$data['laid'],
			$data['scope'] === null ? null : new Config(...$data['scope']),
		);
	}


	public static function read(string $file): self
	{
		return self::fromArray(json_decode(FileSystem::read($file), associative: true, flags: JSON_THROW_ON_ERROR));
	}


	/**
	 * The project of the check, without the types where the layer asks for none.
	 * @return array{RunnerFactory, ResolvedProject}
	 * @throws ConfigurationException
	 */
	public function resolve(Config $config, string $root): array
	{
		$factory = new RunnerFactory(withoutTypes: $this->layer->typeAnalysis === null);
		return [$factory, $factory->resolve($this->scope ?? $config, $root, $this->layer, $this->only, laid: $this->laid)];
	}


	/** @return array{layer: array<string, mixed>, only: ?list<string>, laid: list<string>, scope: ?array{paths: list<string>, excludePaths: list<string>}} */
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
			'laid' => $this->laid,
			'scope' => $this->scope === null ? null : ['paths' => $this->scope->paths, 'excludePaths' => $this->scope->excludePaths],
		];
	}


	public function write(string $file): void
	{
		FileSystem::write($file, json_encode($this->toArray(), JSON_THROW_ON_ERROR));
	}
}
