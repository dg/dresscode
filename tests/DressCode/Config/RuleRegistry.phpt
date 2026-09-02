<?php declare(strict_types=1);

use DressCode\Config\RuleRegistry;
use DressCode\ConfigurationException;
use DressCode\NodeRule;
use DressCode\Preset;
use DressCode\PresetInfo;
use DressCode\Presets;
use DressCode\Presets\Nette;
use DressCode\Presets\Per;
use DressCode\Presets\Psr12;
use DressCode\Presets\Symfony;
use DressCode\Profile;
use DressCode\RuleInfo;
use DressCode\Stage;
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


#[RuleInfo('test/requiring', Stage::Structure, requires: ['php' => '>=8.4', 'acme/lib' => '>=3.3', 'acme/other' => '*'])]
final class RequiringRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/bad-version', Stage::Structure, requires: ['acme/lib' => '^3.3'])]
final class BadVersionRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/bad-php', Stage::Structure, requires: ['php' => '*'])]
final class BadPhpRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/bad-name', Stage::Structure, requires: ['ext-mbstring' => '*'])]
final class BadNameRule extends NodeRule
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
	Assert::same(RuleOne::class, $registry->getRules()['test/one']);
	Assert::same($registry->resolveRule('dresscode/ordered-imports'), $registry->resolveRule('ordered-imports'));
});


test('presets', function () {
	$registry = new RuleRegistry;
	Assert::same(Per::class, $registry->resolvePreset('dresscode/per'));
	Assert::same(Psr12::class, $registry->resolvePreset('dresscode/psr12'));
	Assert::same(Per::class, $registry->resolvePreset('per'));
	Assert::same(TestPreset::class, $registry->resolvePreset(TestPreset::class));
	Assert::same(TestPreset::class, $registry->resolvePreset('test/preset'));
	Assert::same(
		[
			'dresscode/per' => Per::class, 'dresscode/psr12' => Psr12::class, 'dresscode/nette' => Nette::class,
			'dresscode/symfony' => Symfony::class, 'dresscode/nette-style' => Presets\NetteStyle::class,
			'dresscode/symfony-configurator' => Presets\SymfonyConfigurator::class, 'test/preset' => TestPreset::class,
		],
		$registry->getPresets(),
	);
	Assert::exception(fn() => $registry->resolvePreset('none'), ConfigurationException::class, "Unknown preset 'none'.");
	Assert::exception(fn() => $registry->resolvePreset(stdClass::class), ConfigurationException::class, 'Class stdClass is not a preset.');
});


test('what a rule requires is php and packages, each from a version on, and a package may be any version', function () {
	$info = RuleInfo::of(RequiringRule::class);
	Assert::same('8.4', $info->getMinPhpVersion());
	Assert::same(['acme/lib' => '3.3', 'acme/other' => null], $info->getRequiredPackages());
	Assert::null(RuleInfo::of(RuleOne::class)->getMinPhpVersion());
	Assert::same([], RuleInfo::of(RuleOne::class)->getRequiredPackages());

	// an upper bound says nothing about since when the construct exists, so a requirement has none
	Assert::exception(
		fn() => RuleInfo::of(BadVersionRule::class),
		ConfigurationException::class,
		"Rule test/bad-version requires acme/lib '^3.3'; a requirement is '>=' with the version that brought what the rule writes, or '*'. (in BadVersionRule)",
	);
	Assert::exception(
		fn() => RuleInfo::of(BadPhpRule::class),
		ConfigurationException::class,
		"Rule test/bad-php requires php '*'; a requirement is '>=' with the version that brought what the rule writes. (in BadPhpRule)",
	);
	Assert::exception(
		fn() => RuleInfo::of(BadNameRule::class),
		ConfigurationException::class,
		"Rule test/bad-name requires 'ext-mbstring', which is neither php nor a package. (in BadNameRule)",
	);
});
