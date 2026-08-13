<?php declare(strict_types=1);

use DressCode\Config\RuleRegistry;
use DressCode\NodeRule;
use DressCode\Preset;
use DressCode\PresetInfo;
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
