<?php declare(strict_types=1);

namespace DressCode;


/**
 * Identity of a rule: its name (vendor/slug) and the stage it runs in. What a rule of another tool means
 * here is not part of it; that lives in DressCode\Interop, where it can carry the options too.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class RuleInfo
{
	public function __construct(
		public string $name,
		public Stage $stage,
		public string $description = '',
		/** the rule changes the text of comments; otherwise a changed or lost comment is a bug */
		public bool $modifiesComments = false,
		/** the construct the rule enforces exists only from this version of PHP on, "8.4" */
		public ?string $minPhpVersion = null,
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
