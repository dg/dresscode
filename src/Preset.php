<?php declare(strict_types=1);

namespace DressCode;


/**
 * A named set of rules with their options; presets compose (parents first) and a child overrides whole options.
 */
interface Preset
{
	/**
	 * Rule name or class → true (enable), false (disable), options, or a factory fn(): Rule for a rule with dependencies.
	 * @return array<string, bool|array<string, mixed>|\Closure(): Rule>
	 */
	public function getRules(PresetContext $context): array;

	/** @return list<class-string<Preset>> */
	public function getParents(): array;
}
