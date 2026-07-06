<?php declare(strict_types=1);

namespace DressCode;


/**
 * Identity of a rule: its name (vendor/slug), the stage it runs in and its aliases from other tools.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class RuleInfo
{
	/** @param list<string> $aliases  former names and codes of PHP CS Fixer, PHP_CodeSniffer or Slevomat */
	public function __construct(
		public string $name,
		public Stage $stage,
		public array $aliases = [],
		public string $description = '',
		/** the rule changes the text of comments; otherwise a changed or lost comment is a bug */
		public bool $modifiesComments = false,
	) {
	}


	/**
	 * Reads the attribute of a rule class.
	 * @param  Rule|class-string<Rule>  $rule
	 * @throws ConfigurationException  when the class has no RuleInfo
	 */
	public static function of(Rule|string $rule): self
	{
		static $cache = [];
		$class = $rule instanceof Rule ? $rule::class : $rule;
		return $cache[$class] ??= ((new \ReflectionClass($class))->getAttributes(self::class)[0] ?? null)?->newInstance()
			?? throw new ConfigurationException("Rule $class has no #[RuleInfo] attribute.");
	}
}
