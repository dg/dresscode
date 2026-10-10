<?php declare(strict_types=1);

use DressCode\Analyses\IndentationPlan;
use DressCode\Config\{Catalogue, CorePlugin};
use DressCode\{ConfigurationException, Decision, DecisionKind, Domain, ImportStyle, NodeRule, RuleInfo, Stage};
use DressCode\Domains\{Count, Names, Shapes};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


abstract class TestRule extends NodeRule
{
	public function getVisitedNodes(): array
	{
		return [];
	}
}


#[RuleInfo(Stage::Formatting)]
final class CallRule extends TestRule
{
	public static function getDecisions(): array
	{
		return [new Decision('spacing.call', new Shapes(['compact' => ['foo()', 'none'], 'spaced' => ['foo ()', 'one']]), 'The space before the parenthesis')];
	}
}


#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.1'])]
final class TrailingIfRule extends TestRule
{
	public static function getDecisions(): array
	{
		return [
			new Decision('controlFlow.trailingIf', Domain::state(), 'An `if` ending a body becomes a guard', ['A loop is no body here']),
			new Decision('controlFlow.trailingIfMinStatements', new Count(1), 'The statements its body has at least', kind: DecisionKind::Parameter, default: 2),
		];
	}
}


#[RuleInfo(Stage::Structure)]
final class GuardRule extends TestRule
{
	public static function getDecisions(): array
	{
		return [new Decision('namespaces.functions', new Names, 'The functions the namespaces declare', kind: DecisionKind::Fact, default: [])];
	}
}


#[RuleInfo(Stage::Structure)]
final class PresenterRule extends TestRule
{
	public static function getDecisions(): array
	{
		return [new Decision('acme.presenterTemplates', Domain::state('required'), 'A presenter has its template')];
	}
}


#[RuleInfo(Stage::Structure)]
final class NoDbRule extends TestRule
{
	public static function getDecisions(): array
	{
		return [new Decision('project.noDbInPresenter', Domain::state(), 'A presenter does not query the database')];
	}
}


#[RuleInfo(Stage::Structure)]
final class SilentRule extends TestRule
{
}


#[RuleInfo(Stage::Formatting, decisions: ['file.lineEnding'])]
final class LineEndingRule extends TestRule
{
}


#[RuleInfo(Stage::Formatting, decisions: ['indentation.unit'])]
final class IndentationRule extends TestRule
{
}


#[RuleInfo(Stage::Formatting, decisions: ['indentation.unit'])]
final class TemplateIndentationRule extends TestRule
{
	public static function getDecisions(): array
	{
		return [new Decision('acme.templateIndentation', Domain::state('required'), 'The indentation of a template')];
	}
}


#[RuleInfo(Stage::Structure, decisions: ['acme.templates'])]
final class TemplatesRule extends TestRule
{
}


#[RuleInfo(Stage::Structure, decisions: ['acme.templates'])]
final class LayoutsRule extends TestRule
{
	public static function getDecisions(): array
	{
		return [new Decision('acme.layouts', Domain::state('required'), 'A presenter has its layout')];
	}
}


#[RuleInfo(Stage::Formatting, decisions: ['spacing.nothing'])]
final class UnknownNameRule extends TestRule
{
}


#[RuleInfo(Stage::Formatting, decisions: ['indentation.unit'], reads: ['file.lineEnding'])]
final class LineEndingReaderRule extends TestRule
{
}


#[RuleInfo(Stage::Formatting, decisions: ['indentation.unit'], reads: ['spacing.nothing'])]
final class UnknownReadRule extends TestRule
{
}


#[RuleInfo(Stage::Formatting, decisions: ['indentation.unit'], reads: ['indentation.unit'])]
final class EnforcingReaderRule extends TestRule
{
}


#[RuleInfo(Stage::Structure, typesRequired: true)]
final class UndeclaredTypesRule extends TestRule
{
}


// @phpstan-ignore argument.type (a class nothing declares, which the attribute refuses)
#[RuleInfo(Stage::Structure, analyses: ['Acme\Nowhere'])]
final class UnknownAnalysisRule extends TestRule
{
}


#[RuleInfo(Stage::Formatting)]
final class TreeDeclaringRule extends TestRule
{
	public static function getDecisions(): array
	{
		return [new Decision('file.lineEnding', new Shapes(['compact' => ['foo()', '']]), 'Again')];
	}
}


#[RuleInfo(Stage::Formatting)]
final class SpacingAgainRule extends TestRule
{
	public static function getDecisions(): array
	{
		return [new Decision('spacing.call', new Shapes(['compact' => ['foo()', '']]), 'Again')];
	}
}


#[RuleInfo(Stage::Formatting)]
final class StructureRule extends TestRule
{
	public static function getDecisions(): array
	{
		return [new Decision('spacing.call.inner', new Shapes(['compact' => ['foo()', '']]), 'Beneath')];
	}
}


test('the catalogue knows every decision, its rules, and runs the rules in the order of the registration', function () {
	$catalogue = new Catalogue([TrailingIfRule::class, CallRule::class, GuardRule::class], ['acme' => [PresenterRule::class]], [NoDbRule::class]);
	Assert::same('spacing.call', $catalogue->find('spacing.call')?->path);
	Assert::null($catalogue->find('spacing.comma.around'));
	Assert::same([TrailingIfRule::class], $catalogue->getRulesOf('controlFlow.trailingIfMinStatements'));
	Assert::same([], $catalogue->getRulesOf('spacing.comma.around'));
	Assert::same(['controlFlow.trailingIf', 'controlFlow.trailingIfMinStatements'], array_keys($catalogue->getDecisionsUnder('controlFlow')));
	Assert::same(['controlFlow.trailingIf'], array_keys($catalogue->getDecisionsUnder('controlFlow.trailingIf')));
	Assert::same([TrailingIfRule::class, CallRule::class, GuardRule::class, PresenterRule::class, NoDbRule::class], $catalogue->getRuleOrder());
	Assert::count(6, $catalogue->getDecisions());
});


test('a path keeps to the sections of its registrant', function () {
	Assert::exception(fn() => new Catalogue([PresenterRule::class]), ConfigurationException::class, 'Rule `PresenterRule` declares `acme.presenterTemplates` outside the sections of the core.');
	Assert::exception(fn() => new Catalogue([], ['nette' => [PresenterRule::class]]), ConfigurationException::class, 'Rule `PresenterRule` declares `acme.presenterTemplates` outside the section `nette` of the plugin of section `nette`.');
	Assert::exception(fn() => new Catalogue([], [], [CallRule::class]), ConfigurationException::class, 'Rule `CallRule` declares `spacing.call` outside the section `project` of the project.');
	Assert::exception(fn() => new Catalogue([], ['types' => [PresenterRule::class]]), ConfigurationException::class, 'Plugin section `types` is a key of the configuration or a section of the core; name the section after the plugin.');
	Assert::exception(fn() => new Catalogue([], ['path' => [PresenterRule::class]]), ConfigurationException::class, 'Plugin section `path` is one letter off the key `paths` of the configuration, which a file would read as a typo of it; name the section after the plugin.');
	Assert::exception(fn() => new Catalogue([], ['typeAnalysis' => []]), ConfigurationException::class);
	Assert::noError(fn() => new Catalogue([], ['php' => []])); // the target version is a key under `targets`
	Assert::noError(fn() => new Catalogue([], ['acme' => [GuardRule::class]])); // a fact is a key of the environment, whoever guards it
});


test('a decision of a tree is shared by the rules naming it, and is in the catalogue only where some rule does', function () {
	$core = (new CorePlugin)->getManifest()->decisions;
	$acme = [
		new Decision('acme.templates', Domain::state('required'), 'A presenter has its template'),
		new Decision('acme.unnamed', Domain::state('required'), 'Nobody names it'),
	];
	$catalogue = new Catalogue(
		[LineEndingRule::class, IndentationRule::class, TrailingIfRule::class],
		['acme' => [TemplateIndentationRule::class, TemplatesRule::class, LayoutsRule::class]],
		[],
		$core,
		['acme' => $acme],
	);
	Assert::same([IndentationRule::class, TemplateIndentationRule::class], $catalogue->getRulesOf('indentation.unit'));
	Assert::same([TemplatesRule::class, LayoutsRule::class], $catalogue->getRulesOf('acme.templates'));
	Assert::same(['acme.templates', 'acme.layouts'], array_keys($catalogue->getDecisionsOf(LayoutsRule::class)));
	Assert::null($catalogue->find('acme.unnamed'));
	Assert::same([LineEndingRule::class], $catalogue->getRulesOf('file.lineEnding'));
	Assert::same(['indentation.unit', 'acme.templateIndentation'], array_keys($catalogue->getDecisionsOf(TemplateIndentationRule::class)));
	Assert::null($catalogue->find('indentation.tabWidth'));
	Assert::same(['file.lineEnding'], array_map(fn(Decision $decision) => $decision->path, Catalogue::collectDecisions(LineEndingRule::class)));

	Assert::exception(fn() => new Catalogue([UnknownNameRule::class], coreDecisions: $core), ConfigurationException::class, 'Rule `UnknownNameRule` names `spacing.nothing`, which no tree declares.');
	Assert::exception(fn() => Catalogue::collectDecisions(UnknownNameRule::class), ConfigurationException::class, 'Rule `UnknownNameRule` names `spacing.nothing`, which no tree declares.');
	Assert::exception(fn() => new Catalogue([TreeDeclaringRule::class], coreDecisions: $core), ConfigurationException::class, 'Rule `TreeDeclaringRule` declares `file.lineEnding`, which a tree declares; the rule names it in `RuleInfo::$decisions`.');
	Assert::exception(fn() => new Catalogue([], pluginDecisions: ['acme' => [new Decision('spacing.call', Domain::state(), 'Elsewhere')]]), ConfigurationException::class, 'The tree of the plugin of section `acme` declares `spacing.call` outside the section `acme`.');
	Assert::exception(fn() => new Catalogue([], pluginDecisions: ['types' => []]), ConfigurationException::class, 'Plugin section `types` is a key of the configuration or a section of the core; name the section after the plugin.');
});


test('a decision of a tree a rule only reads is known to the values without the rule declaring it', function () {
	$core = (new CorePlugin)->getManifest()->decisions;
	$alone = new Catalogue([LineEndingReaderRule::class], coreDecisions: $core);
	// the decisions the style of a run is read from are known whatever rules there are
	$style = array_values(array_diff([...ImportStyle::Decisions, ...IndentationPlan::Decisions], ['indentation.unit']));
	Assert::same(['indentation.unit', 'file.lineEnding', ...$style], array_keys($alone->getDecisions()));
	Assert::same([], $alone->getRulesOf('file.lineEnding'));
	Assert::same([LineEndingReaderRule::class], $alone->getReadersOf('file.lineEnding'));
	Assert::same(['indentation.unit'], array_keys($alone->getDecisionsOf(LineEndingReaderRule::class)));
	Assert::same([LineEndingReaderRule::class], $alone->toArray()['decisions']['file.lineEnding']['readers']);
	Assert::same([], $alone->toArray()['decisions']['file.lineEnding']['rules']);

	// the order of the decisions is that of the rules enforcing them, whoever reads one
	$both = new Catalogue([LineEndingReaderRule::class, LineEndingRule::class], coreDecisions: $core);
	Assert::same(['indentation.unit', 'file.lineEnding', ...$style], array_keys($both->getDecisions()));
	Assert::same([LineEndingRule::class], $both->getRulesOf('file.lineEnding'));

	Assert::exception(fn() => new Catalogue([UnknownReadRule::class], coreDecisions: $core), ConfigurationException::class, 'Rule `UnknownReadRule` names `spacing.nothing`, which no tree declares.');
	Assert::exception(fn() => RuleInfo::of(EnforcingReaderRule::class), ConfigurationException::class, 'Class `EnforcingReaderRule`: A rule both enforces and only reads `indentation.unit`.');
});


test('a rule names the analyses it asks for, the types among them where it requires them', function () {
	Assert::exception(fn() => RuleInfo::of(UndeclaredTypesRule::class), ConfigurationException::class, 'Class `UndeclaredTypesRule`: A rule that requires the types names `DressCode\Analyses\Types` among its analyses.');
	Assert::exception(fn() => RuleInfo::of(UnknownAnalysisRule::class), ConfigurationException::class, 'Class `UnknownAnalysisRule`: A rule asks for analysis `Acme\Nowhere`, which is no class.');
});


test('a rule of a plugin names a tree of the core, of its plugin and of the plugins it builds on, and no other', function () {
	$core = (new CorePlugin)->getManifest()->decisions;
	$acme = ['acme' => [new Decision('acme.templates', Domain::state('required'), 'A presenter has its template')]];
	$other = ['other' => [TemplatesRule::class]];

	$catalogue = new Catalogue([], $other, [], $core, $acme, ['other' => ['acme']]);
	Assert::same([TemplatesRule::class], $catalogue->getRulesOf('acme.templates'));
	Assert::exception(
		fn() => new Catalogue([], $other, [], $core, $acme),
		ConfigurationException::class,
		'Rule `TemplatesRule` names `acme.templates` of the plugin of section `acme`, which the plugin of section `other` does not build on; add that plugin to the `plugins` of its manifest.',
	);
	Assert::exception(
		fn() => new Catalogue([TemplatesRule::class], [], [], $core, $acme),
		ConfigurationException::class,
		'Rule `TemplatesRule` names `acme.templates` of the plugin of section `acme`, which the core does not build on.',
	);
	Assert::noError(fn() => new Catalogue([], [], [TemplatesRule::class], $core, $acme));
});


test('without a catalogue, a rule of a plugin names the tree of the plugin its package names in extra.dresscode', function () {
	$rule = Acme\DressCode\Rules\NoVarDumpRule::class;
	Assert::same(['acme.debugFunctions', 'acme.debugCalls'], array_map(fn(Decision $decision) => $decision->path, Catalogue::collectDecisions($rule)));
	Assert::same(['acme.debugFunctions', 'acme.debugCalls'], array_keys(Catalogue::fromRules([$rule])->getDecisionsOf($rule)));
});


test('a rule has some decision, a decision one declaration, and no path is both a structure and a decision', function () {
	Assert::exception(fn() => new Catalogue([CallRule::class, SpacingAgainRule::class]), ConfigurationException::class, 'Decision `spacing.call` is declared by both `CallRule` and `SpacingAgainRule`; a decision several rules share is declared by a tree and named by each of them.');
	Assert::exception(fn() => new Catalogue([SilentRule::class]), ConfigurationException::class, 'Rule `SilentRule` declares no decision.');
	Assert::exception(fn() => new Catalogue([CallRule::class, CallRule::class]), ConfigurationException::class, 'Rule `CallRule` is registered twice.');
	Assert::exception(fn() => new Catalogue([StructureRule::class, CallRule::class]), ConfigurationException::class, 'Decision `spacing.call` is also a structure holding `spacing.call.inner` (rules `CallRule` and `StructureRule`).');
	Assert::exception(fn() => new Catalogue([stdClass::class]), ConfigurationException::class, 'Class `stdClass` is not a rule.'); // @phpstan-ignore argument.type (the check is the point)
});


test('the export is the decisions as data, versioned', function () {
	$export = new Catalogue([TrailingIfRule::class, CallRule::class])->toArray();
	Assert::same(Catalogue::Version, $export['version']);
	Assert::same([
		'kind' => 'requirement',
		'domain' => ['kind' => 'words', 'words' => ['forbidden' => 'never there'], 'tolerance' => false],
		'keep' => true,
		'description' => 'An `if` ending a body becomes a guard',
		'values' => '`forbidden` (never there); `keep`',
		'notes' => ['A loop is no body here'],
		'default' => null,
		'rules' => [TrailingIfRule::class => ['php' => '>=8.1']],
		'readers' => [],
	], $export['decisions']['controlFlow.trailingIf']);
	Assert::same('parameter', $export['decisions']['controlFlow.trailingIfMinStatements']['kind']);
	Assert::false($export['decisions']['controlFlow.trailingIfMinStatements']['keep']);
	Assert::same(2, $export['decisions']['controlFlow.trailingIfMinStatements']['default']);
	Assert::same('compact "foo()" (none); spaced "foo ()" (one); `keep`', $export['decisions']['spacing.call']['values']);
});
