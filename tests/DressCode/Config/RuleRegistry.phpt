<?php declare(strict_types=1);

use DressCode\Config\RuleRegistry;
use DressCode\ConfigurationException;
use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;
use DressCode\Presets\Per;
use DressCode\Presets\Psr12;
use DressCode\Rule;
use DressCode\RuleInfo;
use DressCode\Stage;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


#[RuleInfo('test/one', Stage::Formatting)]
final class RuleOne extends Rule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/one', Stage::Formatting)]
final class RuleOneClone extends Rule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


final class NoInfo extends Rule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[PresetInfo('test/preset')]
final class TestPreset implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [];
	}


	public function getParents(): array
	{
		return [];
	}
}


test('rules by class and name', function () {
	$registry = new RuleRegistry;
	Assert::same('test/one', $registry->registerRule(RuleOne::class));
	Assert::same(RuleOne::class, $registry->resolveRule('test/one'));
	Assert::same(RuleOne::class, $registry->resolveRule(RuleOne::class));
	Assert::same(RuleOne::class, $registry->getRules()['test/one']);
});


test('names of a suppression comment', function () {
	$registry = new RuleRegistry;
	$registry->registerRule(RuleOne::class);
	Assert::same(['test/one'], $registry->resolveNames('test/one'));
	Assert::same(['dresscode/ordered-imports'], $registry->resolveNames('ordered_imports'));
	Assert::same(['dresscode/ordered-imports'], $registry->resolveNames('SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses'));
	Assert::same([], $registry->resolveNames('test/unknown'));
});


test('errors', function () {
	$registry = new RuleRegistry;
	$registry->registerRule(RuleOne::class);
	Assert::exception(fn() => $registry->resolveRule('test/none'), ConfigurationException::class, "Unknown rule 'test/none'.");
	Assert::exception(
		fn() => $registry->resolveRule('cast_spaces'),
		ConfigurationException::class,
		"Unknown rule 'cast_spaces'. It is covered by dresscode/cast-spacing; run `dresscode import` to translate a configuration of another tool.",
	);
	Assert::exception(fn() => $registry->registerRule(RuleOneClone::class), ConfigurationException::class, "Rule name 'test/one' is used by both RuleOne and RuleOneClone.");
	Assert::exception(fn() => $registry->registerRule(NoInfo::class), ConfigurationException::class, 'Rule NoInfo has no #[RuleInfo] attribute.');
	Assert::exception(fn() => $registry->resolveRule(stdClass::class), ConfigurationException::class, 'Class stdClass is not a rule.');
});


test('presets', function () {
	$registry = new RuleRegistry;
	Assert::same(Per::class, $registry->resolvePreset('dresscode/per'));
	Assert::same(Psr12::class, $registry->resolvePreset('dresscode/psr12'));
	Assert::same(TestPreset::class, $registry->resolvePreset(TestPreset::class));
	Assert::same(TestPreset::class, $registry->resolvePreset('test/preset'));
	Assert::same(['dresscode/per' => Per::class, 'dresscode/psr12' => Psr12::class, 'test/preset' => TestPreset::class], $registry->getPresets());
	Assert::exception(fn() => $registry->resolvePreset('none'), ConfigurationException::class, "Unknown preset 'none'.");
	Assert::exception(fn() => $registry->resolvePreset(stdClass::class), ConfigurationException::class, 'Class stdClass is not a preset.');
});
