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
 * What `dresscode init` knows of a project before it looks at its code: its name, the packages it has, the framework
 * it is or requires, the formatter it has and the version of PHP its code is written for.
 * @internal
 */
final readonly class Project
{
	/** the vendors of the frameworks whose standard a project of theirs takes in a close call */
	private const Frameworks = ['laravel', 'symfony', 'nette'];


	private function __construct(
		public string $root,
		/** as composer.json names it */
		public ?string $name,
		/** @var array<string, true>  the packages the lock file, or what Composer installed, lists */
		private array $packages,
		/** the standard of the framework the project is or requires: `laravel`, `symfony`, `nette`; null for none */
		public ?string $framework,
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
		$packages = self::readPackages($root);
		$required = [(string) $name, ...array_keys((array) ($composer['require'] ?? []))];
		$framework = array_find(
			self::Frameworks,
			fn(string $vendor) => array_any($required, fn(string $package) => str_starts_with($package, "$vendor/")),
		);
		[$phpTarget, $source] = (new RunnerFactory)->getPhpTarget(new Config, $root);
		return new self(
			$root,
			$name,
			$packages,
			$framework ?? (isset($packages['laravel/pint']) || is_file("$root/pint.json") ? 'laravel' : null),
			FormatterConfig::find($root),
			$phpTarget,
			$source,
		);
	}


	public function hasPackage(string $name): bool
	{
		return isset($this->packages[$name]);
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
