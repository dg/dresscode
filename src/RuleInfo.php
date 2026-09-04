<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;


/**
 * What the engine and the resolver know of a rule: its name (vendor/slug), which is its identity, the stage it
 * runs in and what it needs of the project. What a rule of another tool means here is not part of it; that lives
 * in DressCode\Interop, where it can carry the options too.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class RuleInfo
{
	public function __construct(
		public string $name,
		public Stage $stage,
		public string $description = '',
		/** what the rule gives a project beyond the looks of the code; null for a rule a standard chooses or the project names */
		public ?Group $group = null,
		/** the rule changes the text of comments; otherwise a changed or lost comment is a bug */
		public bool $modifiesComments = false,
		/**
		 * what the rule needs of the project, named the way the require of composer.json names it: `php` for the version
		 * the code targets and a package by its name, each with `>=` and the version that brought what the rule writes,
		 * or a package with `*` for being there at all; `['php' => '>=8.4', 'acme/mailer' => '>=3.3']`
		 * @var array<string, string>
		 */
		public array $requires = [],
		/** the rule makes no sense without the types of the code (Analyses\Types), so it does not run where the configuration gives none */
		public bool $requiresTypes = false,
		/** the option a bare value written for the rule fills, for a rule that is one decision */
		public ?string $decision = null,
		/** every fix of the rule may change what the code does, because the code cannot tell a safe occurrence */
		public bool $risky = false,
	) {
		foreach ($requires as $requirement => $constraint) {
			if (!preg_match('~^(php|[a-z0-9_.-]+/[a-z0-9_.-]+)$~D', $requirement)) {
				throw new \InvalidArgumentException("Rule $name requires '$requirement', which is neither php nor a package.");
			} elseif (!preg_match('~^>=\d+(\.\d+)*$~D', $constraint) && ($constraint !== '*' || $requirement === 'php')) {
				throw new \InvalidArgumentException(
					"Rule $name requires $requirement '$constraint'; a requirement is '>=' with the version that brought what the rule writes"
					. ($requirement === 'php' ? '.' : ", or '*'."),
				);
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
				?? throw new ConfigurationException("Rule $class has no #[RuleInfo] attribute.");
		} catch (\InvalidArgumentException $e) {
			throw new ConfigurationException("Class $class: {$e->getMessage()}", previous: $e);
		}
	}


	/** The lowest version of PHP the rule needs, "8.4"; null for a rule every target has. */
	public function getMinPhpVersion(): ?string
	{
		return isset($this->requires['php']) ? substr($this->requires['php'], 2) : null;
	}


	/**
	 * The packages the rule needs, each with the lowest version of it, or null where any version does.
	 * @return array<string, ?string>
	 */
	public function getRequiredPackages(): array
	{
		$packages = $this->requires;
		unset($packages['php']);
		return array_map(fn(string $constraint) => $constraint === '*' ? null : substr($constraint, 2), $packages);
	}
}
