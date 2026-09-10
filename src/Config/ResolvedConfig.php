<?php declare(strict_types=1);

namespace DressCode\Config;

use function count;


/**
 * What a configuration comes to: the rules in the order they run, each with the options it ends up with and
 * with the layers that set them, the style, the target version of PHP, and the rules that do not run with
 * the reason. One resolution serves the run, the result cache and whoever prints the configuration, so that
 * what the reader is shown is what the rules were given.
 */
final readonly class ResolvedConfig
{
	public function __construct(
		/** @var list<ResolvedRule>  the active ones in the order they run, the rest behind them */
		public array $rules,
		/** the characters of one level of indentation */
		public string $indent,
		/** "\n", "\r\n" or 'majority' */
		public string $eol,
		public string $phpVersion,
		/** @var list<string>  names of the presets, parents first */
		public array $presets,
	) {
	}


	/** @return list<ResolvedRule> */
	public function getActiveRules(): array
	{
		return array_values(array_filter($this->rules, fn(ResolvedRule $rule) => $rule->isActive()));
	}


	public function getRule(string $name): ?ResolvedRule
	{
		foreach ($this->rules as $rule) {
			if ($rule->name === $name) {
				return $rule;
			}
		}

		return null;
	}


	/**
	 * Everything a result depends on besides the file and the version of the package: the active rules with
	 * the options they end up with, the style and the target version. A changed default is a changed value
	 * here, which a description of what the configuration said would miss.
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		$rules = [];
		foreach ($this->getActiveRules() as $rule) {
			$rules[$rule->name] = $rule->factory === null ? $rule->options : 'factory';
		}

		return ['rules' => $rules, 'indent' => $this->indent, 'eol' => $this->eol, 'php' => $this->phpVersion];
	}


	public function countActive(): int
	{
		return count($this->getActiveRules());
	}
}
