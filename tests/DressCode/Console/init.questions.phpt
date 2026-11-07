<?php declare(strict_types=1);

/**
 * init asks where the measuring left the choice: what a round of questions holds, what an answer does to the
 * next one, and what a run without a terminal takes.
 */

use DressCode\Config\Loader;
use DressCode\Console\InitPrinter;
use DressCode\Measuring\{
	Answer, Finding, InProcessProbeRunner, ProbeResult, ProbeRunner, ProjectFacts, Proposal, Question, Questions, Scope, ValueOrigin,
};
use Nette\CommandLine\{ColorDepth, Console};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';
require __DIR__ . '/init.helpers.php';


$tabbed = "<?php\n\nclass A\n{\n\tpublic function b(): string\n\t{\n\t\treturn 'x' . 'y' . 'z';\n\t}\n}\n";


/**
 * A runner over the project that says the braces of a class, while checked, cost three files of the whole scope where the
 * blank line after the tag has the value given, as a decision may cost more once another one is or is not checked.
 */
function classBracesCost(string $root, mixed $afterOpeningTag): ProbeRunner
{
	return new class (new InProcessProbeRunner($root), $afterOpeningTag) implements ProbeRunner {
		public function __construct(
			private ProbeRunner $inner,
			private mixed $afterOpeningTag,
		) {
		}


		public function run(array $probes): array
		{
			$results = $this->inner->run($probes);
			$config = $probes['scope']->config ?? [];
			if (
				isset($results['scope'])
				&& ($config['blankLines']['afterOpeningTag'] ?? null) === $this->afterOpeningTag
				&& ($config['braces']['position']['class'] ?? null) !== 'keep'
			) {
				$result = $results['scope'];
				$files = ['src/f10.php' => true, 'src/f11.php' => true, 'src/f12.php' => true];
				$results['scope'] = new ProbeResult(
					$result->places,
					['braces.position.class' => $files] + $result->fixable,
					$result->left,
					$result->checkedFiles,
					$result->changedFiles,
					$result->syntaxErrors,
					$result->failed,
				);
			}

			return $results;
		}
	};
}


/**
 * A runner over the project that says each of the decisions, while checked, costs a file of the whole scope of its own.
 * @param  list<string>  $paths
 */
function fileEachCost(string $root, array $paths): ProbeRunner
{
	return new class (new InProcessProbeRunner($root), $paths) implements ProbeRunner {
		public function __construct(
			private ProbeRunner $inner,
			/** @var list<string> */
			private array $paths,
		) {
		}


		public function run(array $probes): array
		{
			$results = $this->inner->run($probes);
			if (!isset($results['scope'])) {
				return $results;
			}

			$config = $probes['scope']->config;
			$fixable = $results['scope']->fixable;
			foreach ($this->paths as $i => $path) {
				$value = array_reduce(
					explode('.', $path),
					fn(mixed $node, string $link) => is_array($node) ? $node[$link] ?? null : null,
					$config,
				);
				if ($value !== 'keep') {
					$fixable[$path] = ["src/cost$i.php" => true];
				}
			}

			$result = $results['scope'];
			$results['scope'] = new ProbeResult(
				$result->places, $fixable, $result->left, $result->checkedFiles,
				$result->changedFiles, $result->syntaxErrors, $result->failed,
			);
			return $results;
		}
	};
}


test('a decision left unchecked that makes another one cost more than the budget leaves that one unchecked too', function () {
	$root = createProject('cascade', stragglers());
	$runner = classBracesCost($root, 'keep');
	$proposal = Proposal::measure(ProjectFacts::detect($root), Scope::fromAutoload($root, Scope::Extensions), $runner, ['perCs']);
	$proposal->verify();
	Assert::same(ValueOrigin::OverBudget, $proposal->values['blankLines.afterOpeningTag']->origin);
	Assert::same(ValueOrigin::OverBudget, $proposal->values['braces.position.class']->origin);
	Assert::same(3, $proposal->values['braces.position.class']->cost);
});


test('an answer that checks a decision may leave another one over the budget, which the next round asks about', function () {
	$root = createProject('rounds', stragglers());
	$runner = classBracesCost($root, 0);
	$proposal = Proposal::measure(ProjectFacts::detect($root), Scope::fromAutoload($root, Scope::Extensions), $runner, ['perCs']);
	$proposal->verify();
	$questions = new Questions;
	[$first] = $questions->findNext($proposal);
	Assert::contains('`blankLines.afterOpeningTag: 1`', $first->text);

	// an answer writing a value the first check did not have, as one taking the value of the formatter does
	Assert::true($proposal->applyAnswers(new Answer('no blank line everywhere', ['blankLines.afterOpeningTag' => 0])));
	$proposal->verify();
	$next = $questions->findNext($proposal);
	Assert::count(1, $next);
	Assert::contains('`braces.position.class: nextLine`', $next[0]->text);

	// the recommended answer changes nothing, so there is nothing to check again and nothing more to ask
	Assert::false($proposal->applyAnswers($next[0]->getRecommended()));
	Assert::same([], $questions->findNext($proposal));
});


test('the decisions over the budget the first round has no room for are asked about together, one answer checking all', function () {
	$paths = [
		'spacing.keyword', 'spacing.cast', 'spacing.reference', 'spacing.spread',
		'spacing.ternary', 'spacing.declare', 'spacing.call', 'casing.keyword',
	];
	$root = createProject('left', stragglers());
	$runner = fileEachCost($root, $paths);
	$proposal = Proposal::measure(ProjectFacts::detect($root), Scope::fromAutoload($root, Scope::Extensions), $runner, ['perCs']);
	$proposal->verify();
	$round = (new Questions)->findNext($proposal);
	Assert::count(6, $round); // the blank line after the tag and four of a file each, then the rest

	$left = $round[5];
	Assert::match('Check the 2 decisions left too?', $left->text);
	Assert::count(2, $left->decisions);
	Assert::match('add %A%`spacing.%a%` to `dresscode.neon` to check them too; their fixes change at most 2 files', $left->instruction);
	$printer = new InitPrinter(new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::None));
	Assert::match(
		"(6/6) Check the 2 decisions left too? Without asking, %a%\n           `%a%`%s%a fix rewrites 1 file\n           `%a%`%s%a fix rewrites 1 file\n  [1] don't check them (recommended)\n  [2] check all of them, a fix rewrites at most 2 files\n",
		$printer->printQuestion($left, 6, 6),
	);

	Assert::true($proposal->applyAnswers($left->answers[1]));
	foreach (array_keys($left->answers[1]->values) as $path) {
		Assert::same(ValueOrigin::Answered, $proposal->values[$path]->origin);
	}
});


test('in a terminal init asks where the measuring left the choice, and Enter takes what --yes takes', function () {
	$asked = createProject('asked', stragglers());
	[$code, $out] = runInit($asked, ['--use', 'perCs'], "\n");
	Assert::same(0, $code);
	// the cost is said by the answer, and what the budget is by the question
	Assert::contains(
		"Check `blankLines.afterOpeningTag: 1`? Without asking, init lets the first fix rewrite at most 2 files (`--budget`).\n",
		$out,
	);
	// the place, without a preview of a blank line, which a diff does not show
	Assert::contains("e.g. src/f01.php:1  Expected 1 blank line before the statement, 0 found.\n  [1] ", $out);
	Assert::contains("  [1] don't check it (recommended)\n  [2] `1` everywhere, a fix rewrites 3 files\n", $out);
	Assert::notContains('keep the style made to measure?', $out); // what the standard as it is rewrites is said, not asked

	$yes = createProject('yes', stragglers());
	[$code, $out] = runInit($yes, ['--use', 'perCs', '--yes'], "2\n");
	Assert::same(0, $code);
	Assert::notContains('[1]', $out);
	Assert::same(file_get_contents("$yes/dresscode.neon"), file_get_contents("$asked/dresscode.neon"));

	// the answer checks the decision, and the configuration is checked again with it; the value is the standard's, so the
	// file need not say it
	$answered = createProject('answered', stragglers());
	[$code, $out] = runInit($answered, ['--use', 'perCs'], "2\n");
	Assert::same(0, $code);
	Assert::contains("\nChecked    all 20 files: with these decisions a fix changes 3 files\n", $out);
	Assert::notContains('afterOpeningTag', (string) file_get_contents("$answered/dresscode.neon"));

	// nor does the report list it among the values written
	$set = createProject('set-standard', stragglers());
	[$code, $out] = runInit($set, ['--use', 'perCs', '--set', 'blankLines.afterOpeningTag=1']);
	Assert::same(0, $code);
	Assert::contains("\nChecked    all 20 files: with these decisions a fix changes 3 files\n", $out);
	Assert::notContains('afterOpeningTag', $out);
});


test('a close call of standards is asked first, and the answer measures against the other', function () {
	$root = createProject('close', stragglers());
	[$code, $out] = runInit($root, [], "2\n\n");
	Assert::same(0, $code);
	Assert::match('%A%Your code is as near to `%a%` as to `%a%`; which standard should it follow?%A%', $out);
	Assert::notSame('perCs', Loader::loadFile("$root/dresscode.neon")->use[0] ?? null);

	// without a terminal the nearer is taken, and the line says how to measure against the other
	$root = createProject('close-pipe', stragglers());
	[, $out] = runInit($root);
	Assert::match(
		'%A%Your call  `dresscode init --use %a%` measures against `%a%` instead, with `dresscode.neon` deleted first%A%',
		$out,
	);
});


test('answers adding to one list in a round add up, neither replacing the other', function () {
	$root = createProject('answers-list', ['composer.json' => '{}', 'src/a.php' => "<?php\n"]);
	$proposal = Proposal::measure(
		ProjectFacts::detect($root),
		Scope::fromAutoload($root, Scope::Extensions),
		new InProcessProbeRunner($root),
		['perCs'],
	);
	$proposal->applyAnswers(new Answer('one', ['naming.except' => ['~a~']]), new Answer('other', ['naming.except' => ['~b~']]));
	Assert::same(['~a~', '~b~'], $proposal->values['naming.except']->value);
});


test('names no rule renames that share a suffix go to the baseline, or are let pass by naming.except', function () {
	$files = ['composer.json' => '{}'];
	foreach (['Cart_', 'Order_', 'Item_', 'Stock_'] as $class) {
		$files["src/$class.php"] = "<?php\n\nclass $class\n{\n}\n";
	}

	$root = createProject('names', $files);
	[$code, $out] = runInit($root, ['--use', 'perCs', '--set', 'naming.class=PascalCase']);
	Assert::same(0, $code);
	Assert::contains(
		'add `naming.except: [~_$~]` to `dresscode.neon` to let the class names that end with `_` pass now and later, rather than the baseline listing those of today',
		$out,
	);
	Assert::notContains('except:', (string) file_get_contents("$root/dresscode.neon"));
	Assert::contains('`dresscode baseline`', $out);

	$root = createProject('names-allowed', $files);
	[$code, $out] = runInit($root, ['--use', 'perCs', '--set', 'naming.class=PascalCase'], "2\n");
	Assert::same(0, $code);
	Assert::match(
		"%A%\texcept: [~_$~] %s%# the 4 class names that end with `_`, which no rule renames\n%A?%",
		(string) file_get_contents("$root/dresscode.neon"),
	);
	Assert::contains('passes already', $out);

	// the name is read from the code the rule reports, which for a constant goes on with its value
	$root = createProject('constant-names', [
		'composer.json' => '{}',
		'src/Limits.php' => "<?php\n\nclass Limits\n{\n\tconst Max_ = 1;\n\tconst Min_ = 2;\n\tconst Avg_ = 3;\n}\n",
	]);
	[$code, $out] = runInit($root, ['--use', 'perCs', '--set', 'naming.constant=UPPER_CASE']);
	Assert::same(0, $code);
	Assert::contains('to let the constant names that end with `_` pass', $out);
});


test('a value the code holds to that the formatter contradicts is a question', function () use ($tabbed) {
	$files = [
		'composer.json' => '{}',
		'phpcs.xml' => '<?xml version="1.0"?><ruleset><rule ref="Generic.WhiteSpace.DisallowTabIndent"/></ruleset>',
	];
	foreach (range(1, 4) as $i) {
		$files["src/C$i.php"] = $tabbed;
	}

	$root = createProject('contradicted', $files);
	[$code, $out] = runInit($root, ['--use', 'perCs']);
	Assert::same(0, $code);
	Assert::contains('add `indentation.unit: 4 spaces` to `dresscode.neon` to say what `phpcs.xml` says; its fix changes 4 files', $out);
	Assert::contains("\tunit: tab", (string) file_get_contents("$root/dresscode.neon"));

	// the answer that keeps the value of the code writes it as the user's, with what it rests on
	$root = createProject('contradicted-kept', $files);
	[$code, $out] = runInit($root, ['--use', 'perCs'], "1\n");
	Assert::same(0, $code);
	Assert::contains('[1] `tab`, as your code writes it (recommended)', $out);
	Assert::match("%A%\tunit: tab %s%# your code, all 4 files\n%A?%", (string) file_get_contents("$root/dresscode.neon"));
});


test('for a tool init takes the recommended answers and writes the proposal as data, nothing else', function () {
	$root = createProject('agent', stragglers());
	[$code, $out] = runInit($root, ['--use', 'perCs', '--format', 'json'], "2\n");
	Assert::same(0, $code);
	$data = json_decode($out, associative: true, flags: JSON_THROW_ON_ERROR);
	Assert::same(DressCode\Console\InitPrinter::Version, $data['version']);
	Assert::same(['perCs'], $data['standard']['use']);
	Assert::match('add `blankLines.afterOpeningTag: 1` to `dresscode.neon` to check it too%a%', $data['instructions'][0]);
	Assert::same('dresscode.neon', $data['written']);
	Assert::same($data['configuration'], (string) file_get_contents("$root/dresscode.neon"));
	Assert::contains("\tafterOpeningTag: keep", $data['configuration']);
});


test('the preview of a fix shows the change nearest the place, between the line above and the line below', function () {
	$code = "<?php\n\nfunction f(): void\n{\n\t\$a = \"x\";\n}\n\n\nfunction g(): void\n{\n\tif (true) {\n\t\t\$b = \"y\";\n\t}\n}\n";
	$root = createProject('preview', [
		'src/a.php' => $code,
		'src/b.php' => "<?php\n\n\$c = 'z';\n",
		'src/c.php' => "<?php\n\n//text\n\$x = 1;\n\n\n\n\nfunction h(): void\n{\n}\n",
	]);
	$proposal = Proposal::measure(
		ProjectFacts::detect($root),
		Scope::fromAutoload($root, Scope::Extensions),
		new InProcessProbeRunner($root),
		['nette'],
	);
	$preview = $proposal->previewFix(['literals.quotes' => 'single'], new Finding('src/a.php', 12, 'M.', fixable: true));
	Assert::same([" \tif (true) {", "-\t\t\$b = \"y\";", "+\t\t\$b = 'y';", " \t}"], $preview);

	// blank lines, indentation and the spaces ending a line a diff does not show, so there is no preview of them; the
	// spaces inside a line it shows
	Assert::same([], $proposal->previewFix(['blankLines.aroundDeclarations' => 1], new Finding('src/c.php', 5, 'M.', fixable: true)));
	Assert::contains(
		'+// text',
		$proposal->previewFix(['spacing.comment.marker' => '// text'], new Finding('src/c.php', 3, 'M.', fixable: true)),
	);

	// the indentation all the lines share is left out
	$printer = new InitPrinter(new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::None));
	$question = new Question('Check it?', [new Answer("don't check it")], '', new Finding('src/a.php', 12, 'M.', fixable: true));
	Assert::same(
		"Check it?\n           e.g. src/a.php:12  M.\n                  if (true) {\n                - \t\$b = \"y\";\n                + \t\$b = 'y';\n                  }\n  [1] don't check it (recommended)\n",
		$printer->printQuestion($question, preview: $preview),
	);
});


test('with colors the printer draws all its code itself, so that no backtick is left for a second drawing', function () {
	$root = createProject('drawn', stragglers());
	$proposal = Proposal::measure(
		ProjectFacts::detect($root),
		Scope::fromAutoload($root, Scope::Extensions),
		new InProcessProbeRunner($root),
		['perCs'],
	);
	$proposal->verify();
	$printer = new InitPrinter(new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::Ansi256));
	$out = $printer->printScope($proposal->project, $proposal->scope)
		. $printer->printMeasurements($proposal)
		. $printer->printVerification($proposal)
		. $printer->printNext($proposal)
		. $printer->printInstructions((new Questions)->findNext($proposal))
		. $printer->printQuestion((new Questions)->findNext($proposal)[0]);
	Assert::contains("\e[", $out);
	Assert::notContains('`', Nette\CommandLine\Ansi::strip($out));
});


test('a question is numbered in its round where the round has more, and a round the answers left says so', function () {
	$printer = new InitPrinter(new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::None));
	$question = new Question('Check it?', [new Answer("don't check it"), new Answer('check it', ['spacing.cast' => 'none'])], '');
	Assert::same("(2/3) Check it?\n  [1] don't check it (recommended)\n  [2] check it\n", $printer->printQuestion($question, 2, 3));
	Assert::same("Check it?\n  [1] don't check it (recommended)\n  [2] check it\n", $printer->printQuestion($question));
	Assert::same("Your answers leave 1 more question:\n", $printer->printNextRound(1));
	Assert::same("Your answers leave 2 more questions:\n", $printer->printNextRound(2));
});
