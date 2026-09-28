<?php declare(strict_types=1);

use DressCode\{FileResult, Reporter, Risk, RunResult, Severity, Violation};
use DressCode\Reporters\{CheckstyleReporter, ConsoleReporter, GithubReporter, JsonReporter};
use Nette\CommandLine\{ColorDepth, Console};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** @return list<FileResult> */
function results(): array
{
	return [
		new FileResult('src/clean.php', "<?php\n", "<?php\n"),
		// the third follows from the first and ends like it; the second follows from it too but is a warning,
		// and the warning is what the fixed text still has
		new FileResult('src/a.php', "<?php\n\$a;\n\$c;\n", "<?php\n\$b;\n\$d;\n", [
			new Violation('test/rename', 'Rename $a', 2, 1, Severity::Error, fingerprint: 'f1'),
			new Violation('test/report', 'Variable "b" & <c>', 2, null, Severity::Warning, fingerprint: 'f2', derivedFrom: 'f1'),
			new Violation('test/rename', 'Rename $c', 3, 1, Severity::Error, fingerprint: 'f3', derivedFrom: 'f1'),
		], ['Rule test/x is faulty: it changed the file without reporting a violation.'], remaining: [
			new Violation('test/report', 'Variable "b" & <c>', 2, null, Severity::Warning, fingerprint: 'f2'),
		]),
		new FileResult('src/broken.php', "<?php\n\$a = ;\n", null, error: "Syntax error, unexpected ';'", errorLine: 2),
		new FileResult('src/fail.php', "<?php\n", null, failure: 'Rule test/x failed in src/fail.php: boom'),
	];
}


function output(Reporter $reporter, bool $fix): void
{
	$reporter->start(4, $fix);
	$results = results();
	foreach ($results as $result) {
		$reporter->reportFile($result);
	}

	$reporter->finish(new RunResult($results, $fix));
}


/** @return resource */
function memory()
{
	return fopen('php://memory', 'w+') ?: throw new RuntimeException;
}


/** @param resource $stream  a console that writes the report as plain text */
function plain($stream): Console
{
	return new Console($stream, colorDepth: ColorDepth::None);
}


/** @param Closure(resource): Reporter $factory */
function capture(Closure $factory, bool $fix): string
{
	$stream = memory();
	output($factory($stream), $fix);
	rewind($stream);
	return stream_get_contents($stream);
}


/**
 * The console reporter prints native separators and a check mark the console can show;
 * the expectations are written with slashes and a plus.
 */
function normalize(string $output): string
{
	return str_replace([DIRECTORY_SEPARATOR, '✔'], ['/', '+'], $output);
}


test('console: check lists every violation and says what a fix would leave', function () {
	Assert::match(<<<'XX'
		src/a.php
		  error    2:1  Rename $a           test/rename
		                followed by test/rename on line 3
		  warning    2  Variable "b" & <c>  test/report
		  Rule test/x is faulty: it changed the file without reporting a violation.

		src/broken.php
		  2  Syntax error, unexpected ';'

		src/fail.php
		  Rule test/x failed in src/fail.php: boom

		FAILED  2 violations, 1 of them following from others, 1 warning, a fix leaves 1, 1 file with syntax errors, 1 failed file in 3 of 4 files

		XX, normalize(capture(fn($s) => new ConsoleReporter(plain($s)), fix: false)));
});


test('console: what may go wrong at a risky violation stands below it', function () {
	$stream = memory();
	$reporter = new ConsoleReporter(plain($stream));
	$result = new FileResult('src/a.php', "<?php\n\$a;\n", "<?php\n\$a;\n", [
		new Violation('test/loop', 'The loop must be written with `array_any()`', 2, 1, Severity::Error, fingerprint: 'f1', risky: Risk::TypeUnknown, refused: true, because: '`$a` may be an object'),
	]);
	$reporter->start(1, false);
	$reporter->reportFile($result);
	rewind($stream);
	Assert::match(<<<'XX'
		src/a.php
		  error  2:1  The loop must be written with `array_any()`  test/loop
		              `$a` may be an object

		XX, normalize((string) stream_get_contents($stream)));
});


test('console: the refused risky fixes are counted by their risk, with the advice the run allows', function () {
	$refused = fn(string $fingerprint, Risk $risk) => new Violation('test/r', 'R', 2, 1, Severity::Warning, fingerprint: $fingerprint, risky: $risk, refused: true);
	$result = new FileResult('src/a.php', "<?php\n", "<?php\n", [], remaining: [
		$refused('f1', Risk::TypeUnknown),
		$refused('f2', Risk::TypeUnknown),
		$refused('f3', Risk::NameUncertain),
	]);
	foreach ([
		[
			false,
			false,
			'2 risky fixes wait, the type is unknown: set `types: phpstan`.',
			'run `dresscode init`, which lists what the namespaces declare, or set `nameResolution: certain` if they declare nothing',
		],
		[true, true, '2 risky fixes wait, not even the types tell: check them with `fix --review`.', 'set `nameResolution: certain`'],
		[
			null,
			false,
			'2 risky fixes wait, the type is unknown: check them with `fix --review`.',
			'run `dresscode init`, which lists what the namespaces declare, or set `nameResolution: certain` if they declare nothing',
		],
	] as [$types, $listed, $advice, $names]) {
		$stream = memory();
		$reporter = new ConsoleReporter(plain($stream));
		$reporter->start(1, false);
		$reporter->finish(new RunResult([$result], false, types: $types, namespacesListed: $listed));
		rewind($stream);
		Assert::match(<<<XX
			$advice See https://dresscode.run/types#enable
			1 risky fix waits, a name may reach a function of the namespace: $names. See https://dresscode.run/namespaces#name-resolution

			%A%
			XX, (string) stream_get_contents($stream));
	}
});


test('console: fix lists what the fixed text still violates and says which file it rewrote', function () {
	Assert::match(<<<'XX'
		src/a.php  rewritten
		  warning  2  Variable "b" & <c>  test/report
		  Rule test/x is faulty: it changed the file without reporting a violation.
		--- src/a.php
		+++ src/a.php
		@@ -1,3 +1,3 @@
		 <?php
		-$a;
		-$c;
		+$b;
		+$d;

		src/broken.php
		  2  Syntax error, unexpected ';'

		src/fail.php
		  Rule test/x failed in src/fail.php: boom

		FAILED  2 violations found, none remaining, 1 warning, 1 file with syntax errors, 1 failed file in 3 of 4 files

		XX, normalize(capture(fn($s) => new ConsoleReporter(plain($s), diff: true), fix: true)));
});


test('console: verdict of a clean run', function () {
	$stream = memory();
	$reporter = new ConsoleReporter(plain($stream));
	$reporter->start(1, false);
	$reporter->finish(new RunResult([new FileResult('a.php', '', '')], false));
	$reporter->start(1, true);
	$reporter->finish(new RunResult([new FileResult('a.php', '', '')], true));
	rewind($stream);
	Assert::same("OK  1 file, up to the dress code\nOK  1 file, up to the dress code\n", stream_get_contents($stream));
});


test('console: a fix of warnings alone counts every file, not the ones it touched', function () {
	$stream = memory();
	$reporter = new ConsoleReporter(plain($stream));
	$reporter->start(3, true);
	$reporter->finish(new RunResult([
		new FileResult('a.php', "<?php\n\$a;\n", "<?php\n\$b;\n", [
			new Violation('test/rename', 'Rename $a', 2, 1, Severity::Warning, fingerprint: 'f1'),
		]),
		new FileResult('b.php', "<?php\n", "<?php\n"),
		new FileResult('c.php', "<?php\n", "<?php\n"),
	], true));
	rewind($stream);
	Assert::same("FIXED  3 files, all up to the dress code\n", stream_get_contents($stream));
});


test('bare: what is left to the user and which files were rewritten, nothing else', function () {
	Assert::match(<<<'XX'
		src/a.php  rewritten
		  warning  2  Variable "b" & <c>  test/report
		  Rule test/x is faulty: it changed the file without reporting a violation.

		src/broken.php
		  2  Syntax error, unexpected ';'

		src/fail.php
		  Rule test/x failed in src/fail.php: boom

		XX, normalize(capture(fn($s) => new ConsoleReporter(plain($s), diff: true, bare: true), fix: true)));
});


test('an empty scope still gets the skeleton of a machine-readable format', function () {
	foreach ([JsonReporter::class, CheckstyleReporter::class, GithubReporter::class] as $class) {
		$stream = memory();
		$reporter = new $class($stream);
		$reporter->start(0, false);
		$reporter->finish(new RunResult([], false));
		rewind($stream);
		Assert::notSame('', (string) stream_get_contents($stream), $class);
	}
});


test('github: a warning about the run is an annotation of its own', function () {
	$stream = memory();
	$reporter = new GithubReporter($stream);
	$reporter->start(1, false);
	$reporter->finish(new RunResult([], false, warnings: ['1 entry of the baseline no longer matches a violation']));
	rewind($stream);
	Assert::same(
		"::warning title=dresscode::1 entry of the baseline no longer matches a violation\n0 violations in 1 file\n",
		stream_get_contents($stream),
	);
});


test('console: what follows from a violation is described by rule and line', function () {
	$violation = fn(string $rule, int $line, string $fingerprint, ?string $from = null) =>
		new Violation($rule, 'M', $line, null, Severity::Error, fingerprint: $fingerprint, derivedFrom: $from);
	$stream = memory();
	$reporter = new ConsoleReporter(plain($stream));
	$reporter->start(1, false);
	$reporter->reportFile(new FileResult('a.php', '', '', [
		$violation('test/a', 2, 'f1'),
		$violation('test/a', 3, 'd1', 'f1'),
		$violation('test/a', 4, 'd2', 'f1'),
		$violation('test/b', 9, 'd3', 'f1'),
		$violation('test/a', 5, 'd4', 'f1'),
		$violation('test/a', 10, 'f2'),
		$violation('test/a', 11, 'd5', 'f2'),
		$violation('test/a', 12, 'd6', 'f2'),
		$violation('test/a', 13, 'd7', 'f2'),
		$violation('test/a', 14, 'd8', 'f2'),
		$violation('test/a', 15, 'd9', 'f2'),
		$violation('test/a', 16, 'd0', 'missing'),
	]));
	rewind($stream);
	Assert::match(<<<'XX'
		a.php
		  error   2  M  test/a
		             followed by test/a on lines 3, 4, 5 and test/b on line 9
		  error  10  M  test/a
		             followed by test/a on 5 lines from 11 to 15
		  error  16  M  test/a

		XX, normalize(stream_get_contents($stream)));
});


test('console: in color the code of a message is drawn without its backticks and the columns stay aligned', function () {
	$stream = memory();
	$reporter = new ConsoleReporter(new Console($stream, colorDepth: ColorDepth::Ansi256));
	$reporter->start(1, false);
	$reporter->reportFile(new FileResult('a.php', "<?php\n", null, [
		new Violation('test/a', 'Rename `$a`', 2, 1, Severity::Error, fingerprint: 'f1'),
		new Violation('test/a', 'Rename the variable', 3, 1, Severity::Error, fingerprint: 'f2'),
	], failure: "Rules `test/a` and `test/b` do not converge in `a.php`.\n@@ -1 +1 @@\n-\$a = `ls`;\n+\$b = `ls`;\n", failureDocs: 'troubleshooting#no-convergence'));
	rewind($stream);
	$output = (string) stream_get_contents($stream);
	Assert::contains("\e[38;5;117m\$a\e[0m", $output);
	Assert::contains("\e[91m-\$a = `ls`;\n\e[0m", $output); // a line of the diff keeps the backticks of its code
	Assert::match(<<<'XX'
		a.php
		  Rules test/a and test/b do not converge in a.php.
		@@ -1 +1 @@
		-$a = `ls`;
		+$b = `ls`;
		  See https://dresscode.run/troubleshooting#no-convergence
		  error  2:1  Rename $a            test/a
		  error  3:1  Rename the variable  test/a

		XX, normalize(Nette\CommandLine\Ansi::strip($output)));
});


test('console: paths under the working directory are relative to it, the others absolute', function () {
	$stream = memory();
	$reporter = new ConsoleReporter(plain($stream), root: '/project', cwd: '/project/src');
	$reporter->start(2, false);
	$reporter->reportFile(new FileResult('src/a.php', '', '', [
		new Violation('dresscode/no-x', 'No x', 1, null, Severity::Error, fingerprint: 'f'),
	]));
	$reporter->reportFile(new FileResult('tests/b.php', '', '', [
		new Violation('acme/no-y', 'No y', 1, null, Severity::Error, fingerprint: 'f'),
	]));
	$reporter->reportFile(new FileResult('/elsewhere/c.php', '', '', [
		new Violation('acme/no-z', 'No z', 1, null, Severity::Error, fingerprint: 'f'),
	]));
	rewind($stream);
	Assert::match(<<<'XX'
		a.php
		  error  1  No x  no-x

		/project/tests/b.php
		  error  1  No y  acme/no-y

		/elsewhere/c.php
		  error  1  No z  acme/no-z

		XX, normalize(stream_get_contents($stream)));
});


test('console: in a terminal the name of a rule links to its page, elsewhere it stays bare', function () {
	$findUrl = fn(string $rule) => $rule === 'acme/no-y' ? 'https://acme.dev/no-y' : null;
	$violations = [
		new Violation('acme/no-x', 'No x', 1, null, Severity::Error, fingerprint: 'f1'),
		new Violation('acme/no-y', 'No y', 2, null, Severity::Error, fingerprint: 'f2'),
	];
	foreach ([true, false] as $terminal) {
		$stream = memory();
		$reporter = new ConsoleReporter(new Console($stream, colorDepth: ColorDepth::Ansi256, terminal: $terminal), findRuleUrl: $findUrl);
		$reporter->reportFile(new FileResult('a.php', '', '', $violations));
		rewind($stream);
		$output = (string) stream_get_contents($stream);
		Assert::same($terminal, str_contains($output, "\e]8;;https://acme.dev/no-y\e\\acme/no-y\e]8;;\e\\"));
		Assert::same(1, substr_count(Nette\CommandLine\Ansi::strip($output), 'acme/no-y'));
		Assert::notContains('https://', Nette\CommandLine\Ansi::strip($output));
	}
});


test('console: a status drawn over the output is erased before anything is written, a clean file writes nothing', function () {
	putenv('COLUMNS=80');
	$stream = memory();
	$console = new Console($stream, colorDepth: ColorDepth::None, terminal: true);
	$reporter = new ConsoleReporter($console);
	[$clean, $violating] = results();
	$console->setStatus('5/10 running');
	$reporter->reportFile($clean);
	rewind($stream);
	Assert::same("\e[?25l5/10 running\r", (string) stream_get_contents($stream)); // the status is all there is

	$reporter->reportFile($violating);
	rewind($stream);
	Assert::match("\e[?25l5/10 running\r\e[J\e[?25hsrc%a%a.php%A%", (string) stream_get_contents($stream));
});


test('json', function () {
	Assert::match(<<<'XX'
		{
		    "files": [
		        {
		            "path": "src/a.php",
		            "violations": [
		                {
		                    "rule": "test/rename",
		                    "message": "Rename $a",
		                    "line": 2,
		                    "column": 1,
		                    "severity": "error",
		                    "risky": null,
		                    "refused": false,
		                    "because": null,
		                    "fingerprint": "f1",
		                    "derivedFrom": null
		                },
		                {
		                    "rule": "test/report",
		                    "message": "Variable \"b\" & <c>",
		                    "line": 2,
		                    "column": null,
		                    "severity": "warning",
		                    "risky": null,
		                    "refused": false,
		                    "because": null,
		                    "fingerprint": "f2",
		                    "derivedFrom": "f1"
		                },
		                {
		                    "rule": "test/rename",
		                    "message": "Rename $c",
		                    "line": 3,
		                    "column": 1,
		                    "severity": "error",
		                    "risky": null,
		                    "refused": false,
		                    "because": null,
		                    "fingerprint": "f3",
		                    "derivedFrom": "f1"
		                }
		            ],
		            "remaining": [
		                {
		                    "rule": "test/report",
		                    "message": "Variable \"b\" & <c>",
		                    "line": 2,
		                    "column": null,
		                    "severity": "warning",
		                    "risky": null,
		                    "refused": false,
		                    "because": null,
		                    "fingerprint": "f2",
		                    "derivedFrom": null
		                }
		            ],
		            "warnings": [
		                "Rule test/x is faulty: it changed the file without reporting a violation."
		            ],
		            "error": null,
		            "failure": null,
		            "changed": true,
		            "written": false
		        },
		        {
		            "path": "src/broken.php",
		            "violations": [],
		            "remaining": [],
		            "warnings": [],
		            "error": {
		                "message": "Syntax error, unexpected ';'",
		                "line": 2
		            },
		            "failure": null,
		            "changed": false,
		            "written": false
		        },
		        {
		            "path": "src/fail.php",
		            "violations": [],
		            "remaining": [],
		            "warnings": [],
		            "error": null,
		            "failure": "Rule test/x failed in src/fail.php: boom",
		            "changed": false,
		            "written": false
		        }
		    ],
		    "summary": {
		        "files": 4,
		        "violations": 3,
		        "remaining": 1,
		        "riskyDeferred": 0,
		        "changedFiles": 1,
		        "syntaxErrors": 1,
		        "failures": 1,
		        "baselined": 0
		    },
		    "warnings": []
		}

		XX, capture(fn($s) => new JsonReporter($s), fix: false));
});


test('github: annotations addressed from the checkout, in a fix only what the fixed text still has', function () {
	Assert::match(<<<'XX'
		::warning file=project/src/a.php,line=1,title=dresscode::Rule test/x is faulty: it changed the file without reporting a violation.
		::error file=project/src/a.php,line=2,col=1,title=test/rename::Rename $a
		::warning file=project/src/a.php,line=2,title=test/report::Variable "b" & <c>
		::error file=project/src/a.php,line=3,col=1,title=test/rename::Rename $c
		::error file=project/src/broken.php,line=2,title=syntax error::Syntax error, unexpected ';'
		::error file=project/src/fail.php,line=1,title=dresscode::Rule test/x failed in src/fail.php: boom
		3 violations in 4 files

		XX, capture(fn($s) => new GithubReporter($s, root: '/build/project', workspace: '/build'), fix: false));

	Assert::match(<<<'XX'
		::warning file=project/src/a.php,line=1,title=dresscode::Rule test/x is faulty: it changed the file without reporting a violation.
		::warning file=project/src/a.php,line=2,title=test/report::Variable "b" & <c>
		::error file=project/src/broken.php,line=2,title=syntax error::Syntax error, unexpected ';'
		::error file=project/src/fail.php,line=1,title=dresscode::Rule test/x failed in src/fail.php: boom
		1 violation in 4 files

		XX, capture(fn($s) => new GithubReporter($s, root: '/build/project', workspace: '/build'), fix: true));
});


test('checkstyle', function () {
	Assert::match(<<<'XX'
		<?xml version="1.0" encoding="UTF-8"?>
		<checkstyle version="1.0">
		  <file name="src/a.php">
		    <error line="2" column="1" severity="error" message="Rename $a" source="test/rename"/>
		    <error line="2" severity="warning" message="Variable &quot;b&quot; &amp; &lt;c&gt;" source="test/report"/>
		    <error line="3" column="1" severity="error" message="Rename $c" source="test/rename"/>
		  </file>
		  <file name="src/broken.php">
		    <error line="2" severity="error" message="Syntax error, unexpected &apos;;&apos;" source="syntax"/>
		  </file>
		  <file name="src/fail.php">
		    <error line="1" severity="error" message="Rule test/x failed in src/fail.php: boom" source="dresscode"/>
		  </file>
		</checkstyle>

		XX, capture(fn($s) => new CheckstyleReporter($s), fix: false));

	Assert::match(<<<'XX'
		<?xml version="1.0" encoding="UTF-8"?>
		<checkstyle version="1.0">
		  <file name="src/a.php">
		    <error line="2" severity="warning" message="Variable &quot;b&quot; &amp; &lt;c&gt;" source="test/report"/>
		  </file>
		  <file name="src/broken.php">
		    <error line="2" severity="error" message="Syntax error, unexpected &apos;;&apos;" source="syntax"/>
		  </file>
		  <file name="src/fail.php">
		    <error line="1" severity="error" message="Rule test/x failed in src/fail.php: boom" source="dresscode"/>
		  </file>
		</checkstyle>

		XX, capture(fn($s) => new CheckstyleReporter($s), fix: true));
});
