<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use Composer\Semver\Constraint\Constraint;
use DressCode\Config\Versions;
use function count;


/**
 * What the engine and the resolver know of a rule beside its decisions: the stage it runs in and what it needs of the
 * project. A rule is known by its class; what a user names is its decisions.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class RuleInfo
{
	public function __construct(
		public Stage $stage,
		/**
		 * the rule changes the text of comments; otherwise a changed or lost comment is a bug, whitespace ending a line
		 * of a comment and its line endings being no part of the text
		 */
		public bool $modifiesComments = false,
		/**
		 * what the rule needs of the project, written the way the require of composer.json writes it: `php` for the versions
		 * the code targets, with a Composer constraint every version the project is written for must satisfy, usually `>=`
		 * and the version that brought what the rule writes; `['php' => '>=8.4']`
		 * @var array<string, string>
		 */
		public array $requires = [],
		/** the rule makes no sense without the types of the code, so it does not run where the configuration gives none */
		public bool $typesRequired = false,
		/**
		 * the analyses the rule asks for, those the helpers it calls ask for among them, which a strict run holds it to
		 * @var list<class-string>
		 */
		public array $analyses = [],
		/**
		 * the paths of the decisions of a tree the rule shares with other rules, which turn it on as those it declares do
		 * @var list<string>
		 */
		public array $decisions = [],
		/**
		 * the paths of the decisions of a tree the rule reads and does not enforce, which turn nothing on: the maps of
		 * other rules whose occurrences it leaves to them
		 * @var list<string>
		 */
		public array $reads = [],
	) {
		if (count(array_unique($decisions)) !== count($decisions) || count(array_unique($reads)) !== count($reads)) {
			throw new \InvalidArgumentException('A rule names each decision of a tree once.');
		} elseif ($both = array_intersect($decisions, $reads)) {
			throw new \InvalidArgumentException('A rule both enforces and only reads `' . reset($both) . '`.');
		} elseif (count(array_unique($analyses)) !== count($analyses)) {
			throw new \InvalidArgumentException('A rule names each analysis it asks for once.');
		} elseif ($unknown = array_filter($analyses, fn(string $class) => !class_exists($class))) {
			throw new \InvalidArgumentException('A rule asks for analysis `' . reset($unknown) . '`, which is no class.');
		}

		foreach ($requires as $requirement => $constraint) {
			if ($requirement !== 'php') {
				throw new \InvalidArgumentException("A rule requires `$requirement`, which is not `php`.");
			}

			try {
				$parsed = Versions::parse($constraint);
			} catch (\UnexpectedValueException) {
				throw new \InvalidArgumentException("A rule requires `$requirement $constraint`, which is no Composer constraint.");
			}

			// a single version, as Composer reads a bare one, would turn the rule off for every other release
			if ($parsed instanceof Constraint && $parsed->getOperator() === '==') {
				throw new \InvalidArgumentException("A rule requires `$requirement $constraint`, a single version; a requirement is a range, usually `>=` with the version that brought what the rule writes.");
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


	/**
	 * Throws for an analysis the rule does not name, which a strict run refuses it.
	 * @throws \LogicException
	 * @internal
	 */
	public function checkAnalysis(string $class): void
	{
		if (!in_array($class, $this->analyses, true)) {
			throw new \LogicException("It asks for analysis `$class`, which it does not name in `RuleInfo::\$analyses`.");
		}
	}


	/** The lowest version of PHP the rule needs, `"8.4"`; null where its requirement has no lower bound. */
	public function getMinPhpVersion(): ?string
	{
		return isset($this->requires['php']) ? Versions::findLowestVersion($this->requires['php']) : null;
	}
}
