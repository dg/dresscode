<?php declare(strict_types=1);

use DressCode\Config\RuleRegistry;
use DressCode\{ConfigurationException, NodeRule, Preset, PresetInfo, Profile, RuleInfo, Stage};
use DressCode\Presets\{Nette, PerCs, Psr12, Symfony};
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


#[RuleInfo('test/requiring', Stage::Structure, requires: ['php' => '>=8.4', 'acme/lib' => '>=3.3 <5.0', 'acme/other' => '*'])]
final class RequiringRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/badVersion', Stage::Structure, requires: ['acme/lib' => '3.3'])]
final class BadVersionRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/exactVersion', Stage::Structure, requires: ['acme/lib' => '==3.3'])]
final class ExactVersionRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/branch', Stage::Structure, requires: ['acme/lib' => 'dev-master'])]
final class BranchRule extends NodeRule
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
	Assert::same(['dresscode/orderedImports'], $registry->resolveNames('ordered_imports'));
	Assert::same(['dresscode/orderedImports'], $registry->resolveNames('SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses'));
	Assert::same([], $registry->resolveNames('test/unknown'));
});


test('errors', function () {
	$registry = new RuleRegistry;
	$registry->registerRule(RuleOne::class);
	Assert::exception(fn() => $registry->resolveRule('quite/different'), ConfigurationException::class, 'Unknown rule `quite/different`.');
	Assert::exception(fn() => $registry->resolveRule('test/none'), ConfigurationException::class, 'Unknown rule `test/none`. Did you mean `test/one`?');
	Assert::exception(fn() => $registry->resolveRule('indentaton'), ConfigurationException::class, 'Unknown rule `indentaton`. Did you mean `indentation`?');
	Assert::exception(fn() => $registry->resolvePreset('dresscode/nete'), ConfigurationException::class, 'Unknown preset `dresscode/nete`. Did you mean `dresscode/nette`?');
	Assert::exception(
		fn() => $registry->resolveRule('cast_spaces'),
		ConfigurationException::class,
		'Unknown rule `cast_spaces`. It is covered by `dresscode/castSpacing`; `dresscode import` translates a configuration of another tool.',
	);
	Assert::exception(fn() => $registry->registerRule(RuleOneClone::class), ConfigurationException::class, 'Rule name `test/one` is used by both `RuleOne` and `RuleOneClone`.');
	Assert::exception(fn() => $registry->registerRule(NoInfo::class), ConfigurationException::class, 'Rule `NoInfo` has no `#[RuleInfo]` attribute.');
	Assert::exception(fn() => $registry->resolveRule(stdClass::class), ConfigurationException::class, 'Class `stdClass` is not a rule.');
});


test('what a rule requires is php and packages, each a Composer constraint, and a package may be any version', function () {
	$info = RuleInfo::of(RequiringRule::class);
	Assert::same('8.4', $info->getMinPhpVersion());
	Assert::same(['acme/lib' => '>=3.3 <5.0', 'acme/other' => '*'], $info->getRequiredPackages());
	Assert::null(RuleInfo::of(RuleOne::class)->getMinPhpVersion());
	Assert::same([], RuleInfo::of(RuleOne::class)->getRequiredPackages());

	// a bare version is a single one for Composer, which would turn the rule off for every other release
	Assert::exception(
		fn() => RuleInfo::of(BadVersionRule::class),
		ConfigurationException::class,
		'Class `BadVersionRule`: Rule `test/badVersion` requires `acme/lib 3.3`, a single version; a requirement is a range, usually `>=` with the version that brought what the rule writes.',
	);
	Assert::exception(
		fn() => RuleInfo::of(ExactVersionRule::class),
		ConfigurationException::class,
		'Class `ExactVersionRule`: Rule `test/exactVersion` requires `acme/lib ==3.3`, a single version; %a%',
	);
	Assert::exception(
		fn() => RuleInfo::of(BranchRule::class),
		ConfigurationException::class,
		'Class `BranchRule`: Rule `test/branch` requires `acme/lib dev-master`, a single version; %a%',
	);
	Assert::exception(
		fn() => RuleInfo::of(BadPhpRule::class),
		ConfigurationException::class,
		'Class `BadPhpRule`: Rule `test/badPhp` requires `php eight`, which is no Composer constraint.',
	);
	Assert::exception(
		fn() => RuleInfo::of(BadNameRule::class),
		ConfigurationException::class,
		'Class `BadNameRule`: Rule `test/badName` requires `ext-mbstring`, which is neither `php` nor a package.',
	);
	Assert::exception(
		fn() => RuleInfo::of(KebabNameRule::class),
		ConfigurationException::class,
		'Class `KebabNameRule`: Rule name `test/kebab-name` is not `vendor/camelCaseName`.',
	);
});


test('presets', function () {
	$registry = new RuleRegistry;
	Assert::same(PerCs::class, $registry->resolvePreset('dresscode/perCs'));
	Assert::same(Psr12::class, $registry->resolvePreset('dresscode/psr12'));
	Assert::same(PerCs::class, $registry->resolvePreset('perCs'));
	Assert::same(TestPreset::class, $registry->resolvePreset(TestPreset::class));
	Assert::same(TestPreset::class, $registry->resolvePreset('test/preset'));
	Assert::same(
		[
			'dresscode/perCs' => PerCs::class, 'dresscode/psr12' => Psr12::class, 'dresscode/nette' => Nette::class,
			'dresscode/symfony' => Symfony::class, 'test/preset' => TestPreset::class,
		],
		$registry->presets,
	);
	Assert::exception(fn() => $registry->resolvePreset('none'), ConfigurationException::class, 'Unknown preset `none`.');
	Assert::exception(fn() => $registry->resolvePreset(stdClass::class), ConfigurationException::class, 'Class `stdClass` is not a preset.');
});
