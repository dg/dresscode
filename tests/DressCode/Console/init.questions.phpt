<?php declare(strict_types=1);

/**
 * init asks where the measuring left the choice: what a round of questions holds, what an answer does to the
 * next one, and what a run without a terminal takes.
 */

use DressCode\Config\Loader;
use DressCode\Console\InitPrinter;
use DressCode\Measuring\{
	Answer, InProcessProbeRunner, ProbeResult, ProbeRunner, ProjectFacts, Proposal, Question, Questions, Scope, ValueOrigin,
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
			$config = $probes['scope'] ?? [];
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
					$result->changedFiles,
					$result->syntaxErrors,
					$result->failed,
				);
			}

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
	$runner = classBracesCost($root, 1);
	$proposal = Proposal::measure(ProjectFacts::detect($root), Scope::fromAutoload($root, Scope::Extensions), $runner, ['perCs']);
	$proposal->verify();
	$questions = new Questions;
	[$first] = $questions->findNext($proposal);
	Assert::contains('`blankLines.afterOpeningTag: 1`', $first->text);

	Assert::true($proposal->applyAnswers($first->answers[1]));
	$proposal->verify();
	$next = $questions->findNext($proposal);
	Assert::count(1, $next);
	Assert::contains('`braces.position.class: nextLine`', $next[0]->text);

	// the recommended answer changes nothing, so there is nothing to check again and nothing more to ask
	Assert::false($proposal->applyAnswers($next[0]->getRecommended()));
	Assert::same([], $questions->findNext($proposal));
});


test('in a terminal init asks where the measuring left the choice, and Enter takes what --yes takes', function () {
	$asked = createProject('asked', stragglers());
	[$code, $out] = runInit($asked, ['--use', 'perCs'], "\n");
	Assert::same(0, $code);
	Assert::contains("Check `blankLines.afterOpeningTag: 1`? A fix rewrites 3 files, more than the budget allows.\n", $out);
	Assert::contains('e.g. src/f01.php:1  ', $out);
	Assert::contains("  [1] don't check it (recommended)\n  [2] 1 everywhere, a fix rewrites 3 files\n", $out);
	Assert::notContains('keep the style made to measure?', $out); // what the standard as it is rewrites is said, not asked

	$yes = createProject('yes', stragglers());
	[$code, $out] = runInit($yes, ['--use', 'perCs', '--yes'], "2\n");
	Assert::same(0, $code);
	Assert::notContains('[1]', $out);
	Assert::same(file_get_contents("$yes/dresscode.neon"), file_get_contents("$asked/dresscode.neon"));

	// the answer checks the decision, and the configuration is checked again with it
	$answered = createProject('answered', stragglers());
	[$code, $out] = runInit($answered, ['--use', 'perCs'], "2\n");
	Assert::same(0, $code);
	Assert::contains("\nChecked    all 20 files: a fix changes 3 files\n", $out);
	Assert::contains("\tafterOpeningTag: 1   # your answer\n", (string) file_get_contents("$answered/dresscode.neon"));
});


test('a close call of standards is asked first, and the answer measures against the other', function () {
	$root = createProject('close', stragglers());
	[$code, $out] = runInit($root, [], "2\n\n");
	Assert::same(0, $code);
	Assert::match('%A%Your code is as near to %a% as to %a%; which standard should it follow?%A%', $out);
	Assert::notSame('perCs', Loader::loadFile("$root/dresscode.neon")->use[0] ?? null);

	// without a terminal the nearer is taken, and the line says how to measure against the other
	$root = createProject('close-pipe', stragglers());
	[, $out] = runInit($root);
	Assert::match('%A%Your call  `dresscode init --use %a%` measures against %a% instead, where there is no `dresscode.neon` yet%A%', $out);
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
		'`naming.except: [~_$~]` in `dresscode.neon` lets the class names that end with `_` pass now and later, rather than the baseline listing those of today',
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
	Assert::contains('lets the constant names that end with `_` pass', $out);
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
	Assert::contains('`indentation.unit: 4 spaces` in `dresscode.neon` says what `phpcs.xml` says; its fix changes 4 files', $out);
	Assert::contains("\tunit: tab", (string) file_get_contents("$root/dresscode.neon"));

	// the answer that keeps the value of the code writes it as the user's, with what it rests on
	$root = createProject('contradicted-kept', $files);
	[$code, $out] = runInit($root, ['--use', 'perCs'], "1\n");
	Assert::same(0, $code);
	Assert::contains('[1] tab, as your code writes it (recommended)', $out);
	Assert::match("%A%\tunit: tab %s%# your code, all 4 files\n%A?%", (string) file_get_contents("$root/dresscode.neon"));
});


test('for a tool init takes the recommended answers and writes the proposal as data, nothing else', function () {
	$root = createProject('agent', stragglers());
	[$code, $out] = runInit($root, ['--use', 'perCs', '--format', 'json'], "2\n");
	Assert::same(0, $code);
	$data = json_decode($out, associative: true, flags: JSON_THROW_ON_ERROR);
	Assert::same(DressCode\Console\InitPrinter::Version, $data['version']);
	Assert::same(['perCs'], $data['standard']['use']);
	Assert::match('`blankLines.afterOpeningTag: 1` in `dresscode.neon` checks it too%a%', $data['instructions'][0]);
	Assert::same('dresscode.neon', $data['written']);
	Assert::same($data['configuration'], (string) file_get_contents("$root/dresscode.neon"));
	Assert::contains("\tafterOpeningTag: keep", $data['configuration']);
});


test('a question is numbered in its round where the round has more, and a round the answers left says so', function () {
	$printer = new InitPrinter(new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::None));
	$question = new Question('Check it?', [new Answer("don't check it"), new Answer('check it', ['spacing.cast' => 'none'])], '');
	Assert::same("(2/3) Check it?\n  [1] don't check it (recommended)\n  [2] check it\n", $printer->printQuestion($question, 2, 3));
	Assert::same("Check it?\n  [1] don't check it (recommended)\n  [2] check it\n", $printer->printQuestion($question));
	Assert::same("Your answers leave one more question:\n", $printer->printNextRound(1));
	Assert::same("Your answers leave 2 more questions:\n", $printer->printNextRound(2));
});
