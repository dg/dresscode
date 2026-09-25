<?php declare(strict_types=1);

use DressCode\Config\PluginRegistry;
use DressCode\{ConfigurationException, NodeRule, RuleInfo, Stage};
use Tester\{Assert, FileMock};

require __DIR__ . '/../../bootstrap.php';


#[RuleInfo(Stage::Formatting)]
final class RuleOne extends NodeRule
{
	use ProjectDecision;

	public function getVisitedNodes(): array
	{
		return [];
	}
}


final class NoInfo extends NodeRule
{
	public function getVisitedNodes(): array
	{
		return [];
	}
}


#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.4', 'acme/lib' => '>=3.3 <5.0', 'acme/other' => '*'])]
final class RequiringRule extends NodeRule
{
	public function getVisitedNodes(): array
	{
		return [];
	}
}


#[RuleInfo(Stage::Structure, requires: ['acme/lib' => '3.3'])]
final class BadVersionRule extends NodeRule
{
	public function getVisitedNodes(): array
	{
		return [];
	}
}


#[RuleInfo(Stage::Structure, requires: ['acme/lib' => '==3.3'])]
final class ExactVersionRule extends NodeRule
{
	public function getVisitedNodes(): array
	{
		return [];
	}
}


#[RuleInfo(Stage::Structure, requires: ['acme/lib' => 'dev-master'])]
final class BranchRule extends NodeRule
{
	public function getVisitedNodes(): array
	{
		return [];
	}
}


#[RuleInfo(Stage::Structure, requires: ['php' => 'eight'])]
final class BadPhpRule extends NodeRule
{
	public function getVisitedNodes(): array
	{
		return [];
	}
}


#[RuleInfo(Stage::Structure, requires: ['ext-mbstring' => '*'])]
final class BadNameRule extends NodeRule
{
	public function getVisitedNodes(): array
	{
		return [];
	}
}


test('a rule is known by its class, once however often it is registered', function () {
	$registry = new PluginRegistry;
	$count = count($registry->rules);
	$registry->registerRule(RuleOne::class);
	$registry->registerRule(RuleOne::class);
	Assert::same($count + 1, count($registry->rules));
	Assert::same(RuleOne::class, $registry->rules[$count]);
	Assert::same(RuleOne::class, $registry->registerRuleOrResolvePreset(RuleOne::class)->rule);
	Assert::same('dresscode/nette', $registry->registerRuleOrResolvePreset('nette')->preset);
});


test('names of a suppression comment: a decision or a section for itself, a rule for its decisions', function () {
	$registry = new PluginRegistry;
	$registry->registerRule(RuleOne::class);
	Assert::same(['project.ruleOne'], $registry->expandSuppressedName(RuleOne::class));
	Assert::same(['imports.order'], $registry->expandSuppressedName('imports.order'));
	Assert::same(['imports'], $registry->expandSuppressedName('imports'));
	Assert::same([], $registry->expandSuppressedName('importOrder'));
	Assert::same([], $registry->expandSuppressedName('test/unknown'));
	Assert::same([], $registry->expandSuppressedName('imports.ord'));
});


test('a rule a suppression comment names is asked for its decisions, never registered', function () {
	$registry = new PluginRegistry;
	$rules = $registry->rules;
	Assert::same(['project.ruleOne'], $registry->expandSuppressedName(RuleOne::class));
	Assert::same($rules, $registry->rules);
	Assert::null($registry->getCatalogue()->find('project.ruleOne'));
});


test('errors', function () {
	$registry = new PluginRegistry;
	$registry->registerRule(RuleOne::class);
	Assert::exception(fn() => $registry->registerRuleOrResolvePreset('quite/different'), ConfigurationException::class, 'Unknown decision, preset or rule `quite/different`.');
	Assert::exception(fn() => $registry->registerRuleOrResolvePreset('nete'), ConfigurationException::class, 'Unknown decision, preset or rule `nete`. Did you mean `nette`?');
	Assert::exception(fn() => $registry->resolvePreset('dresscode/nete'), ConfigurationException::class, 'Unknown preset `dresscode/nete`. Did you mean `dresscode/nette`?');
	Assert::exception(
		fn() => $registry->registerRuleOrResolvePreset('importOrder'),
		ConfigurationException::class,
		'Unknown decision, preset or rule `importOrder`.%a?%',
	);
	Assert::exception(fn() => RuleInfo::of(NoInfo::class), ConfigurationException::class, 'Rule `NoInfo` has no `#[RuleInfo]` attribute.');
	// @phpstan-ignore argument.type (a class that is no rule is what the check refuses)
	Assert::exception(fn() => $registry->registerRule(stdClass::class), ConfigurationException::class, 'Class `stdClass` is not a rule.');
	$registry->registerRule(NoInfo::class);
	Assert::exception(fn() => $registry->getCatalogue(), ConfigurationException::class, 'Rule `NoInfo` has no `#[RuleInfo]` attribute.');
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
		'Class `BadVersionRule`: A rule requires `acme/lib 3.3`, a single version; a requirement is a range, usually `>=` with the version that brought what the rule writes.',
	);
	Assert::exception(
		fn() => RuleInfo::of(ExactVersionRule::class),
		ConfigurationException::class,
		'Class `ExactVersionRule`: A rule requires `acme/lib ==3.3`, a single version; %a%',
	);
	Assert::exception(
		fn() => RuleInfo::of(BranchRule::class),
		ConfigurationException::class,
		'Class `BranchRule`: A rule requires `acme/lib dev-master`, a single version; %a%',
	);
	Assert::exception(
		fn() => RuleInfo::of(BadPhpRule::class),
		ConfigurationException::class,
		'Class `BadPhpRule`: A rule requires `php eight`, which is no Composer constraint.',
	);
	Assert::exception(
		fn() => RuleInfo::of(BadNameRule::class),
		ConfigurationException::class,
		'Class `BadNameRule`: A rule requires `ext-mbstring`, which is neither `php` nor a package.',
	);
});


test('presets', function () {
	$registry = new PluginRegistry;
	$file = FileMock::create("decisions:\n", 'neon');
	$registry->registerPreset('test/preset', $file);
	Assert::same('dresscode/perCs', $registry->resolvePreset('dresscode/perCs'));
	Assert::same('dresscode/perCs', $registry->resolvePreset('perCs'));
	Assert::same('test/preset', $registry->resolvePreset('test/preset'));
	Assert::same([
		'dresscode/perCs', 'dresscode/psr12', 'dresscode/nette', 'dresscode/symfony', 'dresscode/cleanup',
		'dresscode/compilerOptimizations', 'dresscode/correctness', 'dresscode/deprecations', 'dresscode/modernizations', 'dresscode/types',
		'test/preset',
	], array_keys($registry->presets));
	Assert::match('%a%/src/Presets/perCs.neon', $registry->presets['dresscode/perCs']);
	Assert::exception(fn() => $registry->resolvePreset('none'), ConfigurationException::class, 'Unknown preset `none`.');
	Assert::exception(fn() => $registry->resolvePreset('u'), ConfigurationException::class, 'Unknown preset `u`.');
	Assert::exception(fn() => $registry->registerPreset('test/missing', 'none.neon'), ConfigurationException::class, 'Preset `test/missing` names file `none.neon`, which does not exist.');
	Assert::exception(fn() => $registry->registerPreset('preset', $file), ConfigurationException::class, 'Preset name `preset` is not `vendor/name`.');
});
