<?php declare(strict_types=1);

/**
 * The catalogue of the built-in rules that declare their decisions: it holds together, every value is described,
 * and every claim of a gap rule is made for a decision of that rule, so that no gap is left that nobody can turn
 * off or explain.
 */

use DressCode\Config\{Catalogue, CorePlugin, DecisionResolver, Layer, LayerKind, PluginRegistry, RuleBuilder};
use DressCode\{Decision, Domain, GapRule, Space, Style};
use DressCode\Domains\{Count, Shapes, Words};
use DressCode\Engine\{Gaps, RulePlan};
use Nette\Neon\Neon;
use PhpSyntax\{Node, Parser, Token, Traverser, Trivia};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$registry = new PluginRegistry;
$rules = $registry->rules;
$catalogue = $registry->getCatalogue();


test('every decision of the tree of the core lies in a section of the core, once, is no fact and is named by some rule', function () use ($catalogue) {
	$paths = [];
	foreach ((new CorePlugin)->getManifest()->decisions as $decision) {
		Assert::contains(explode('.', $decision->path)[0], Catalogue::CoreSections, $decision->path);
		Assert::false($decision->fact, $decision->path);
		Assert::notSame([], $catalogue->getRulesOf($decision->path), $decision->path);
		$paths[] = $decision->path;
	}

	Assert::same(array_unique($paths), $paths);
});


test('an alignment is said in the words of every alignment', function () use ($catalogue) {
	$words = Domain::alignment()->words;
	foreach ($catalogue->getDecisions() as $path => $decision) {
		if (str_ends_with($path, 'Alignment') && $decision->domain instanceof Words) {
			Assert::same(array_intersect_key($words, $decision->domain->words), $decision->domain->words, $path);
		}
	}
});


test('every value of a decision is described, and so is the decision', function () use ($catalogue) {
	foreach ($catalogue->getDecisions() as $path => $decision) {
		Assert::notSame('', $decision->description, $path);
		Assert::false(str_ends_with($decision->description, '.'), "$path: a description ends without a period");
		Assert::true((bool) preg_match('~^[A-Z`]~', $decision->description), "$path: a description is capitalized");
		Assert::false(
			(bool) preg_match('~^(For|As|And|In place of|(Add|Align|Declare|Keep|Make|Put|Remove|Replace|Report|Return|Spread|Turn|Write)s)\b~', $decision->description),
			"$path: a description says what the thing is, neither an action nor a fragment",
		);
		$domain = $decision->domain;
		$meanings = match (true) {
			$domain instanceof Words => $domain->words,
			$domain instanceof Shapes => array_map(fn(array $shape) => $shape[1], $domain->shapes),
			$domain instanceof Count => $domain->words,
			default => [],
		};
		foreach ($meanings as $value => $meaning) {
			Assert::notSame('', $meaning, "$path: `$value` says what it means");
		}
	}
});


test('every claim of a gap rule is made for a decision of that rule', function () use ($catalogue, $rules) {
	$sink = new class implements Gaps\Sink {
		/** @var list<?string> */
		public array $decided = [];


		public function takeLine(Gaps\DecidedClaim $claim, ?Token $previous, Token $token, ?Space $space, bool $broken): void
		{
			$this->decided[] = $claim->claim->decisionLine ?? $claim->claim->decision;
		}


		public function takeSpace(Gaps\DecidedClaim $claim, Token $previous, Token $token, string $found): void
		{
			$this->decided[] = $claim->claim->decision;
		}


		public function takeBlankLines(Gaps\DecidedClaim $claim, Token $token, array $range, int $from, int $found, ?Trivia $below): void
		{
			$this->decided[] = $below === null ? $claim->claim->decision : $claim->claim->decisionBelowComment ?? $claim->claim->decision;
		}
	};

	foreach ($rules as $class) {
		if (!is_subclass_of($class, GapRule::class)) {
			continue;
		}

		$dir = __DIR__ . '/fixtures/' . lcfirst(substr((string) strrchr($class, '\\'), 1, -4));
		$one = Catalogue::fromRules([$class]);
		$resolver = new DecisionResolver($one);
		$resolved = $resolver->resolve(is_file("$dir/values.neon") ? [[new Layer(LayerKind::Caller), (array) Neon::decodeFile("$dir/values.neon")]] : []);
		$rule = RuleBuilder::buildFromDecisions($resolver, $resolved, $resolver->createValues($resolved))[0] ?? throw new LogicException("$class does not run with the values of its fixtures.");

		$gaps = new Gaps\Resolver(new RulePlan([$rule])->claims);
		$sink->decided = [];
		foreach (glob("$dir/*.code") ?: [] as $file) {
			$code = (string) file_get_contents($file);
			$gaps->beginPass(new Style(maxLineLength: preg_match('~^// lineLength (\d+)~m', $code, $m) ? (int) $m[1] : null), $sink, fn() => null);
			Traverser::traverse(new Parser()->parse($code), fn(Node|Token $node) => $node instanceof Token ? $gaps->enterToken($node) : $gaps->enterNode($node));
		}

		$owned = $catalogue->getDecisionsOf($class);
		$requirements = array_filter($owned, fn(Decision $decision) => $decision->isRequirement());
		Assert::notSame([], $sink->decided, "$class decides some gap of its fixtures");
		foreach (array_unique($sink->decided) as $decision) {
			Assert::true(
				$decision === null ? count($requirements) === 1 : isset($owned[$decision]),
				"$class claims a gap for " . ($decision ?? 'no decision') . ', which is not one of its own',
			);
		}
	}
});
