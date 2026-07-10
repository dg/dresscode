<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Config\PresetResolver;
use DressCode\Config\RuleRegistry;
use DressCode\ConfigurableRule;
use DressCode\ConfigurationException;
use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;
use DressCode\Rule;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\PhpVersion;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


#[RuleInfo('test/a', Stage::Formatting)]
final class RuleA extends Rule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/b', Stage::Formatting)]
final class RuleB extends Rule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/c', Stage::Formatting)]
final class RuleC extends Rule implements ConfigurableRule
{
	/** @var array<string, mixed> */
	public array $options = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure(['max' => Expect::int(3), 'names' => Expect::listOf('string')->default(['x'])]);
	}


	public function configure(array $options): void
	{
		$this->options = $options;
	}


	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/d', Stage::Formatting)]
final class RuleD extends Rule
{
	public function __construct(
		public string $dependency,
	) {
	}


	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[PresetInfo('test/base')]
final class BasePreset implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [RuleA::class => true, RuleC::class => ['max' => 5], RuleB::class => true];
	}


	public function getParents(): array
	{
		return [];
	}
}


#[PresetInfo('test/child')]
final class ChildPreset implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return $context->getPhpVersion()->isAtLeast('8.3')
			? [RuleB::class => false, RuleC::class => ['names' => ['x']]]
			: [RuleB::class => false];
	}


	public function getParents(): array
	{
		return [BasePreset::class];
	}
}


#[PresetInfo('test/broken')]
final class BrokenPreset implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return ['test/none' => true];
	}


	public function getParents(): array
	{
		return [];
	}
}


/** @return list<Rule> */
function resolve(Config $config, string $php = '8.3'): array
{
	return (new PresetResolver(new RuleRegistry))->resolve($config, new PresetContext(PhpVersion::fromString($php)));
}


/**
 * @param  list<Rule>  $rules
 * @return list<string>
 */
function names(array $rules): array
{
	return array_map(fn(Rule $rule) => RuleInfo::of($rule)->name, $rules);
}


test('parents first, the child overrides whole entries, order of the first mention', function () {
	$rules = resolve(Config::create()->preset(ChildPreset::class));
	Assert::same(['test/a', 'test/c'], names($rules));
	assert($rules[1] instanceof RuleC);
	Assert::equal(['max' => 3, 'names' => ['x']], $rules[1]->options);

	$rules = resolve(Config::create()->preset(ChildPreset::class), php: '8.2');
	Assert::same(['test/a', 'test/c'], names($rules));
	assert($rules[1] instanceof RuleC);
	Assert::equal(['max' => 5, 'names' => ['x']], $rules[1]->options);
});


test('the configuration overrides the presets', function () {
	$rules = resolve(Config::create()->preset(ChildPreset::class)->enable('test/b')->disable('test/a')->enable(RuleD::class, fn() => new RuleD('dep')));
	Assert::same(['test/c', 'test/b', 'test/d'], names($rules));
	assert($rules[2] instanceof RuleD);
	Assert::same('dep', $rules[2]->dependency);
});


test('a list option replaces its default instead of being merged with it', function () {
	$rules = resolve(Config::create()->enable(RuleC::class, ['names' => ['y']]));
	assert($rules[0] instanceof RuleC);
	Assert::equal(['max' => 3, 'names' => ['y']], $rules[0]->options);
});


test('a configuration without a preset', function () {
	Assert::same(['test/c', 'test/a'], names(resolve(Config::create()->enable(RuleC::class)->enable(RuleA::class))));
	Assert::same([], resolve(Config::create()));
});


test('errors', function () {
	Assert::exception(fn() => resolve(Config::create()->enable('test/none')), ConfigurationException::class, "Unknown rule 'test/none'.");
	Assert::exception(fn() => resolve(Config::create()->preset(BrokenPreset::class)), ConfigurationException::class, "Unknown rule 'test/none'. (in preset test/broken)");
	Assert::exception(fn() => resolve(Config::create()->enable(RuleA::class, ['x' => 1])), ConfigurationException::class, 'Rule test/a has no options.');
	Assert::exception(fn() => resolve(Config::create()->enable(RuleC::class, ['max' => 'no'])), ConfigurationException::class, "Invalid options of rule test/c: The item 'max' expects to be int, 'no' given.");
	Assert::exception(fn() => resolve(Config::create()->enable(RuleC::class, ['other' => 1])), ConfigurationException::class, "Invalid options of rule test/c: Unexpected item 'other'.");
	Assert::exception(fn() => resolve(Config::create()->enable(RuleD::class, fn() => new RuleA)), ConfigurationException::class, 'The factory of rule test/d returned RuleA instead of RuleD.');
});
