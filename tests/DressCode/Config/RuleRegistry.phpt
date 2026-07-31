<?php declare(strict_types=1);

use DressCode\Config\RuleRegistry;
use DressCode\{ConfigurationException, NodeRule, Preset, PresetInfo, Profile, RuleInfo, Stage};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


#[RuleInfo('test/one', Stage::Formatting)]
final class RuleOne extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/one', Stage::Formatting)]
final class RuleOneClone extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


final class NoInfo extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/requiring', Stage::Structure, requires: ['php' => '>=8.4'])]
final class RequiringRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/badVersion', Stage::Structure, requires: ['php' => '8.3'])]
final class BadVersionRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/exactVersion', Stage::Structure, requires: ['php' => '==8.3'])]
final class ExactVersionRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/badPhp', Stage::Structure, requires: ['php' => 'eight'])]
final class BadPhpRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/badName', Stage::Structure, requires: ['ext-mbstring' => '*'])]
final class BadNameRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/kebab-name', Stage::Structure)]
final class KebabNameRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[PresetInfo('test/preset')]
final class TestPreset implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile;
	}
}


test('rules by class and name', function () {
	$registry = new RuleRegistry;
	Assert::same('test/one', $registry->registerRule(RuleOne::class));
	Assert::same(RuleOne::class, $registry->resolveRule('test/one'));
	Assert::same(RuleOne::class, $registry->resolveRule(RuleOne::class));
	Assert::same(RuleOne::class, $registry->rules['test/one']);
	Assert::same($registry->resolveRule('dresscode/orderedImports'), $registry->resolveRule('orderedImports'));
});


test('names of a suppression comment', function () {
	$registry = new RuleRegistry;
	$registry->registerRule(RuleOne::class);
	Assert::same(['test/one'], $registry->resolveNames('test/one'));
	Assert::same(['dresscode/orderedImports'], $registry->resolveNames('orderedImports'));
	Assert::same([], $registry->resolveNames('test/unknown'));
});


test('what a rule requires is php, a Composer constraint', function () {
	Assert::same('8.4', RuleInfo::of(RequiringRule::class)->getMinPhpVersion());
	Assert::null(RuleInfo::of(RuleOne::class)->getMinPhpVersion());

	// a bare version is a single one for Composer, which would turn the rule off for every other release
	Assert::exception(
		fn() => RuleInfo::of(BadVersionRule::class),
		ConfigurationException::class,
		'Class `BadVersionRule`: Rule `test/badVersion` requires `php 8.3`, a single version; a requirement is a range, usually `>=` with the version that brought what the rule writes.',
	);
	Assert::exception(
		fn() => RuleInfo::of(ExactVersionRule::class),
		ConfigurationException::class,
		'Class `ExactVersionRule`: Rule `test/exactVersion` requires `php ==8.3`, a single version; %a%',
	);
	Assert::exception(
		fn() => RuleInfo::of(BadPhpRule::class),
		ConfigurationException::class,
		'Class `BadPhpRule`: Rule `test/badPhp` requires `php eight`, which is no Composer constraint.',
	);
	Assert::exception(
		fn() => RuleInfo::of(BadNameRule::class),
		ConfigurationException::class,
		'Class `BadNameRule`: Rule `test/badName` requires `ext-mbstring`, which is not `php`.',
	);
	Assert::exception(
		fn() => RuleInfo::of(KebabNameRule::class),
		ConfigurationException::class,
		'Class `KebabNameRule`: Rule name `test/kebab-name` is not `vendor/camelCaseName`.',
	);
});
