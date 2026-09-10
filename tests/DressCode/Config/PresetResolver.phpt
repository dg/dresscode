<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Config\PresetResolver;
use DressCode\Config\RuleRegistry;
use DressCode\ConfigurableRule;
use DressCode\ConfigurationException;
use DressCode\NodeRule;
use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;
use DressCode\Rule;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Tester\Assert;


require __DIR__ . '/../../bootstrap.php';


#[RuleInfo('test/a', Stage::Formatting)]
final class RuleA extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/b', Stage::Formatting)]
final class RuleB extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/c', Stage::Formatting)]
final class RuleC extends NodeRule implements ConfigurableRule
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
final class RuleD extends NodeRule
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


#[RuleInfo('test/future', Stage::Formatting, minPhpVersion: '8.4')]
final class RuleFuture extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[PresetInfo('test/future-preset')]
final class FuturePreset implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [RuleA::class => true, RuleFuture::class => true];
	}


	public function getParents(): array
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
		return version_compare($context->getPhpVersion(), '8.3', '>=')
			? [RuleB::class => false, RuleC::class => ['names' => ['x']]]
			: [RuleB::class => false];
	}


	public function getParents(): array
	{
		return [BasePreset::class];
	}
}


#[PresetInfo('test/styled', indent: 2, eol: 'lf')]
final class StyledPreset implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [];
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
	return new PresetResolver(new RuleRegistry)->resolve($config, new PresetContext($php));
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


test('a rule of a construct the target version has not got is left out', function () {
	$resolver = new PresetResolver(new RuleRegistry);
	$resolve = fn(Config $config, string $php) => names($resolver->resolve($config, new PresetContext($php)));

	Assert::same(['test/a', 'test/future'], $resolve(Config::create()->preset(FuturePreset::class), '8.4'));
	Assert::same(['test/a'], $resolve(Config::create()->preset(FuturePreset::class), '8.3'));
	Assert::same([], $resolver->getWarnings()); // coming from a preset it is business as usual

	Assert::same([], $resolve(Config::create()->enable(RuleFuture::class), '8.3'));
	Assert::same(['Rule test/future needs PHP 8.4, the target is 8.3; skipped.'], $resolver->getWarnings());

	// what the result cache keys on is what really runs, and a rule that does not says why
	$resolved = $resolver->resolveConfig(Config::create()->preset(FuturePreset::class), new PresetContext('8.3'));
	Assert::same(['test/a'], array_keys($resolved->toArray()['rules']));
	$future = $resolved->getRule('test/future');
	Assert::type(DressCode\Config\ResolvedRule::class, $future);
	Assert::same('it needs PHP 8.4 and the target is 8.3', $future->inactive);
	Assert::same('test/future-preset', $future->getSource());
	Assert::same(['8.3', "\t", 'majority'], [$resolved->phpVersion, $resolved->indent, $resolved->eol]);
});


test('a configuration without a preset', function () {
	Assert::same(['test/c', 'test/a'], names(resolve(Config::create()->enable(RuleC::class)->enable(RuleA::class))));
	Assert::same([], resolve(Config::create()));
});


test('the style comes from the configuration, else from the last preset declaring one, else tab and majority', function () {
	$resolver = new PresetResolver(new RuleRegistry);
	Assert::same(["\t", 'majority'], $resolver->resolveStyle(Config::create()));
	Assert::same(["\t", 'majority'], $resolver->resolveStyle(Config::create()->preset(ChildPreset::class)));
	Assert::same(['  ', "\n"], $resolver->resolveStyle(Config::create()->preset(StyledPreset::class)));
	Assert::same(['  ', "\n"], $resolver->resolveStyle(Config::create()->preset(StyledPreset::class)->preset(ChildPreset::class)));
	Assert::same(['    ', "\n"], $resolver->resolveStyle(Config::create()->preset(StyledPreset::class)->style(indent: 4)));
	Assert::same(['  ', "\r\n"], $resolver->resolveStyle(Config::create()->preset(StyledPreset::class)->style(eol: 'crlf')));
	Assert::same(['  ', 'majority'], $resolver->resolveStyle(Config::create()->preset(StyledPreset::class)->style(eol: 'majority')));
	Assert::same(['  ', PHP_EOL], $resolver->resolveStyle(Config::create()->preset(StyledPreset::class)->style(eol: 'platform')));
	Assert::exception(fn() => $resolver->resolveStyle(Config::create()->style(eol: 'unix')), ConfigurationException::class, "The line ending must be 'lf', 'crlf', 'majority' or 'platform'.");
	Assert::exception(fn() => $resolver->resolveStyle(Config::create()->style(indent: 'spaces')), ConfigurationException::class, "The indentation must be a number of spaces or 'tab'.");
});


test('errors', function () {
	Assert::exception(fn() => resolve(Config::create()->enable('test/none')), ConfigurationException::class, "Unknown rule 'test/none'.");
	Assert::exception(fn() => resolve(Config::create()->preset(BrokenPreset::class)), ConfigurationException::class, "Unknown rule 'test/none'. (in preset test/broken)");
	Assert::exception(fn() => resolve(Config::create()->enable(RuleA::class, ['x' => 1])), ConfigurationException::class, 'Rule test/a has no options.');
	Assert::exception(fn() => resolve(Config::create()->enable(RuleC::class, ['max' => 'no'])), ConfigurationException::class, "Invalid options of rule test/c: The item 'max' expects to be int, 'no' given.");
	Assert::exception(fn() => resolve(Config::create()->enable(RuleC::class, ['other' => 1])), ConfigurationException::class, "Invalid options of rule test/c: Unexpected item 'other'.");
	Assert::exception(fn() => resolve(Config::create()->enable(RuleD::class, fn() => new RuleA)), ConfigurationException::class, 'The factory of rule test/d returned RuleA instead of RuleD.');
});
