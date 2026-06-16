<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use Composer\Semver\Constraint\Constraint;
use DressCode\Config\Versions;


/**
 * What the engine and the resolver know of a rule: its name (vendor/slug), which is its identity, the stage it
 * runs in and what it needs of the project.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class RuleInfo
{
	public function __construct(
		public string $name,
		public Stage $stage,
		public string $description = '',
		/** what the rule gives a project beyond the looks of the code, its one intent; none for a rule a standard chooses or the project names */
		public ?RuleGroup $group = null,
		/** the rule changes the text of comments; otherwise a changed or lost comment is a bug */
		public bool $modifiesComments = false,
		/**
		 * what the rule needs of the project, written the way the require of composer.json writes it: `php` for the versions
		 * the code targets, with a Composer constraint every version the project is written for must satisfy, usually `>=`
		 * and the version that brought what the rule writes; `['php' => '>=8.4']`
		 * @var array<string, string>
		 */
		public array $requires = [],
		/** the option a bare value written for the rule fills, for a rule that is one decision */
		public ?string $decision = null,
	) {
		if (!preg_match('~^[a-z0-9_.-]+/[a-z][a-zA-Z0-9]*$~D', $name)) {
			throw new \InvalidArgumentException("Rule name `$name` is not `vendor/camelCaseName`.");
		}

		foreach ($requires as $requirement => $constraint) {
			if ($requirement !== 'php') {
				throw new \InvalidArgumentException("Rule `$name` requires `$requirement`, which is not `php`.");
			}

			try {
				$parsed = Versions::parse($constraint);
			} catch (\UnexpectedValueException) {
				throw new \InvalidArgumentException("Rule `$name` requires `$requirement $constraint`, which is no Composer constraint.");
			}

			// a single version, as Composer reads a bare one, would turn the rule off for every other release
			if ($parsed instanceof Constraint && $parsed->getOperator() === '==') {
				throw new \InvalidArgumentException("Rule `$name` requires `$requirement $constraint`, a single version; a requirement is a range, usually `>=` with the version that brought what the rule writes.");
			}
		}
	}


	/**
	 * Reads the attribute of a rule class.
	 * @param  Rule|class-string<Rule>  $rule
	 * @throws ConfigurationException  when the class has no RuleInfo, or one that says what it cannot
	 */
	public static function of(Rule|string $rule): self
	{
		static $cache = [];
		$class = $rule instanceof Rule ? $rule::class : $rule;
		try {
			return $cache[$class] ??= (new \ReflectionClass($class)->getAttributes(self::class)[0] ?? null)?->newInstance()
				?? throw new ConfigurationException("Rule `$class` has no `#[RuleInfo]` attribute.");
		} catch (\InvalidArgumentException $e) {
			throw new ConfigurationException("Class `$class`: {$e->getMessage()}", previous: $e);
		}
	}


	/** The lowest version of PHP the rule needs, `"8.4"`; null where its requirement has no lower bound. */
	public function getMinPhpVersion(): ?string
	{
		return isset($this->requires['php']) ? Versions::findLowestVersion($this->requires['php']) : null;
	}
}
