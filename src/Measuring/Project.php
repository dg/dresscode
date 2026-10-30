<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use DressCode\Config;
use DressCode\Config\{ComposerJson, PhpVersionSource, RunnerFactory};
use DressCode\Interop\FormatterConfig;
use function is_array, is_string;


/**
 * What `dresscode init` knows of a project before it looks at its code: its name, the packages it requires and has,
 * the formatter it has and the version of PHP its code is written for.
 * @internal
 */
final readonly class Project
{
	private function __construct(
		public string $root,
		/** as composer.json names it */
		public ?string $name,
		/** @var list<string>  the packages composer.json requires, and its own name */
		private array $required,
		/** @var array<string, true>  the packages the lock file, or what Composer installed, lists */
		private array $packages,
		public ?FormatterConfig $formatterConfig,
		/** the versions of PHP the code is written for, as a Composer constraint */
		public string $phpTarget,
		public PhpVersionSource $phpTargetSource,
	) {
	}


	public static function detect(string $root): self
	{
		$composer = ComposerJson::read(ComposerJson::findFile($root)) ?? [];
		$name = is_string($composer['name'] ?? null) ? $composer['name'] : null;
		[$phpTarget, $source] = (new RunnerFactory)->getPhpTarget(new Config, $root);
		return new self(
			$root,
			$name,
			[...($name === null ? [] : [$name]), ...array_map(strval(...), array_keys((array) ($composer['require'] ?? [])))],
			self::readPackages($root),
			FormatterConfig::find($root),
			$phpTarget,
			$source,
		);
	}


	public function hasPackage(string $name): bool
	{
		return isset($this->packages[$name]);
	}


	/** Whether the project uses Laravel: it is or requires a package of Laravel, or Laravel Pint formats it. */
	public function usesLaravel(): bool
	{
		return array_any($this->required, fn(string $package) => str_starts_with($package, 'laravel/'))
			|| $this->hasPackage('laravel/pint')
			|| is_file("$this->root/pint.json");
	}


	/**
	 * The standard of the preset Laravel Pint formats the project with, `laravel` where `pint.json` names none; null for
	 * a project without Pint and for a preset no standard is, `empty` among them.
	 */
	public function findPintStandard(): ?string
	{
		return match (true) {
			is_file("$this->root/pint.json") => FormatterConfig::fromFile($this->root, 'pint.json')->standard,
			$this->hasPackage('laravel/pint') => 'laravel',
			default => null,
		};
	}


	/**
	 * The packages the project has, told by the lock file beside its composer.json or, without one, by what Composer
	 * installed.
	 * @return array<string, true>
	 */
	private static function readPackages(string $root): array
	{
		$composer = ComposerJson::findFile($root);
		foreach ([$composer === null ? null : dirname($composer) . '/composer.lock', "$root/vendor/composer/installed.json"] as $file) {
			$data = ComposerJson::read($file);
			if ($data === null) {
				continue;
			}

			$packages = [];
			$list = array_is_list($data) ? $data : [...(array) ($data['packages'] ?? []), ...(array) ($data['packages-dev'] ?? [])];
			foreach ($list as $package) {
				if (is_array($package) && isset($package['name'])) {
					$packages[(string) $package['name']] = true;
				}
			}

			return $packages;
		}

		return [];
	}
}
