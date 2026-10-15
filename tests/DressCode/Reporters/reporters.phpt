<?php declare(strict_types=1);

use DressCode\Engine\{FileSummary, RunInfo, RunResult, TypeAnalysisStatus};
use DressCode\{FileResult, Reporter, Risk, Severity, Violation};
use DressCode\Reporters\{AgentReporter, CheckstyleReporter, ConsoleReporter, GithubReporter, JsonReporter, LinesReporter};
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
			new Violation('test/rename', 'Rename $a.', 2, 1, Severity::Error, fingerprint: 'f1', offset: 6, length: 2),
			new Violation('test/report', 'Variable "b" & <c>.', 2, null, Severity::Warning, fingerprint: 'f2', derivedFrom: 'f1'),
			new Violation('test/rename', 'Rename $c.', 3, 1, Severity::Error, fingerprint: 'f3', derivedFrom: 'f1'),
		], ['Rule test/x is faulty: it changed the file without reporting a violation.'], remaining: [
			new Violation('test/report', 'Variable "b" & <c>.', 2, null, Severity::Warning, fingerprint: 'f2', offset: 8, length: 1),
		]),
		new FileResult('src/broken.php', "<?php\n\$a = ;\n", null, syntaxError: 'Unexpected `;`', syntaxErrorLine: 2),
		new FileResult('src/fail.php', "<?php\n", null, failure: 'Rule test/x failed in src/fail.php: boom'),
	];
}


function output(Reporter $reporter, bool $fix): void
{
	$reporter->start(new RunInfo('', $fix, 4));
	$results = results();
	foreach ($results as $result) {
		$reporter->reportFile($result);
	}

	$reporter->finish(new RunResult(array_map(FileSummary::of(...), $results), $fix));
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


/** The expectation with the line endings the reporters write, whatever the checkout gave the heredoc. */
function toLf(string $expected): string
{
	return str_replace("\r\n", "\n", $expected);
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
		  error    2:1  Rename $a.                        test/rename
		                Then also, on line 3: Rename $c.  test/rename
		  warning    2  Variable "b" & <c>.               test/report
		  Rule test/x is faulty: it changed the file without reporting a violation.

		src/broken.php
		  2  Syntax error: Unexpected `;`

		src/fail.php
		  Rule test/x failed in src/fail.php: boom

		FAILED  2 violations, 1 of them following from others, 1 warning, a fix leaves none, 1 syntax error, 1 failed file in 3 of 4 files

		XX, normalize(capture(fn($s) => new ConsoleReporter(plain($s)), fix: false)));
});


test('console: what may go wrong at a risky violation stands below it', function () {
	$stream = memory();
	$reporter = new ConsoleReporter(plain($stream));
	$result = new FileResult('src/a.php', "<?php\n\$a;\n", "<?php\n\$a;\n", [
		new Violation(
			'test/loop',
			'The loop must be written with `array_any()`.',
			2,
			1,
			Severity::Error,
			fingerprint: 'f1',
			risk: Risk::TypeUnknown,
			refused: true,
			because: '`$a` may be an object',
		),
	]);
	$reporter->start(new RunInfo('', false, 1));
	$reporter->reportFile($result);
	rewind($stream);
	Assert::match(<<<'XX'
		src/a.php
		  risky  2:1  The loop must be written with `array_any()`.  test/loop
		              Risky because `$a` may be an object.

		XX, normalize((string) stream_get_contents($stream)));
});


test('console: the refused risky fixes are counted by their risk, with the advice the run allows', function () {
	$refused = fn(string $fingerprint, Risk $risk) => new Violation(
		'test/r',
		'R.',
		2,
		1,
		Severity::Warning,
		fingerprint: $fingerprint,
		risk: $risk,
		refused: true,
	);
	$result = new FileResult('src/a.php', "<?php\n", "<?php\n", [], remaining: [
		$refused('f1', Risk::TypeUnknown),
		$refused('f2', Risk::TypeUnknown),
		$refused('f3', Risk::NameUncertain),
	]);
	foreach ([
		[
			TypeAnalysisStatus::Available,
			false,
			'2 risky fixes wait, the type is unknown: set `typeAnalysis: phpstan`.',
			'list what the namespaces declare under `namespaces`, or set `nameResolution: certain` if they declare nothing',
		],
		[
			TypeAnalysisStatus::Enabled,
			true,
			'2 risky fixes wait, not even the types tell: check them with `fix --ask-risky`.',
			'set `nameResolution: certain`',
		],
		[
			TypeAnalysisStatus::Unavailable,
			false,
			'2 risky fixes wait, the type is unknown: check them with `fix --ask-risky`.',
			'list what the namespaces declare under `namespaces`, or set `nameResolution: certain` if they declare nothing',
		],
	] as [$typeAnalysis, $listed, $advice, $names]) {
		$stream = memory();
		$reporter = new ConsoleReporter(plain($stream));
		$reporter->start(new RunInfo('', false, 1, $typeAnalysis, namespacesListed: $listed));
		$reporter->finish(new RunResult([FileSummary::of($result)], fix: false));
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
		  warning  2  Variable "b" & <c>.  test/report
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
		  2  Syntax error: Unexpected `;`

		src/fail.php
		  Rule test/x failed in src/fail.php: boom

		FAILED  2 violations found, none remaining, 1 warning, 1 syntax error, 1 failed file in 3 of 4 files

		XX, normalize(capture(fn($s) => new ConsoleReporter(plain($s), diff: true), fix: true)));
});


test('console: verdict of a clean run', function () {
	$stream = memory();
	$reporter = new ConsoleReporter(plain($stream));
	$reporter->start(new RunInfo('', false, 1));
	$reporter->finish(new RunResult([FileSummary::of(new FileResult('a.php', '', ''))], fix: false));
	$reporter->start(new RunInfo('', true, 1));
	$reporter->finish(new RunResult([FileSummary::of(new FileResult('a.php', '', ''))], fix: true));
	rewind($stream);
	Assert::same("OK  1 file, up to the dress code\nOK  1 file, up to the dress code\n", stream_get_contents($stream));
});


test('console: a check counts the violations no rule has a fix for, a fix does not', function () {
	$unfixable = new Violation('test/plain', 'Plain.', 2, null, Severity::Error, fingerprint: 'f1', fixable: false);
	$fixable = new Violation('test/rename', 'Rename.', 3, null, Severity::Error, fingerprint: 'f2');
	$result = new FileResult('a.php', "<?php\n", "<?php\n", [$unfixable, $fixable], remaining: [$unfixable]);
	foreach ([false, true] as $fix) {
		$stream = memory();
		$reporter = new ConsoleReporter(plain($stream));
		$reporter->start(new RunInfo('', $fix, 1));
		$reporter->finish(new RunResult([FileSummary::of($result)], $fix));
		rewind($stream);
		$output = (string) stream_get_contents($stream);
		Assert::same(!$fix, str_contains($output, "1 violation has no fix, the rules only report them.\n\n"), $fix ? 'fix' : 'check');
	}
});


test('console: a fix of warnings alone counts every file, not the ones it touched', function () {
	$stream = memory();
	$reporter = new ConsoleReporter(plain($stream));
	$reporter->start(new RunInfo('', true, 3));
	$reporter->finish(new RunResult(array_map(FileSummary::of(...), [
		new FileResult('a.php', "<?php\n\$a;\n", "<?php\n\$b;\n", [
			new Violation('test/rename', 'Rename $a.', 2, 1, Severity::Warning, fingerprint: 'f1'),
		]),
		new FileResult('b.php', "<?php\n", "<?php\n"),
		new FileResult('c.php', "<?php\n", "<?php\n"),
	]), fix: true));
	rewind($stream);
	Assert::same("FIXED  3 files, all up to the dress code\n", stream_get_contents($stream));
});


test('lines: one line for every event, in the shape of a compiler, and nothing for a clean file', function () {
	Assert::same(toLf(<<<'XX'
		src/a.php: warning: Rule test/x is faulty: it changed the file without reporting a violation.
		src/a.php:2:1: error: Rename $a. [test/rename]
		src/a.php:2: warning: Variable "b" & <c>. [test/report]
		src/a.php:3:1: error: Rename $c. [test/rename]
		src/broken.php:2: error: Syntax error: Unexpected `;`
		src/fail.php: failure: Rule test/x failed in src/fail.php: boom

		XX), capture(fn($s) => new LinesReporter($s), fix: false));

	// a fix lists what the fixed text still has, at the column its span starts on
	Assert::same(toLf(<<<'XX'
		src/a.php: warning: Rule test/x is faulty: it changed the file without reporting a violation.
		src/a.php:2:3: warning: Variable "b" & <c>. [test/report]
		src/broken.php:2: error: Syntax error: Unexpected `;`
		src/fail.php: failure: Rule test/x failed in src/fail.php: boom

		XX), capture(fn($s) => new LinesReporter($s), fix: true));
});


test('lines: the path relative to the working directory or absolute, a message on one line, the risk beside the decision', function () {
	$stream = memory();
	$reporter = new LinesReporter($stream, root: 'C:/work/app', cwd: 'C:/work/app/src');
	$reporter->start(new RunInfo('', false, 2));
	$reporter->reportFile(new FileResult('src/Žluťoučký.php', '', '', [
		new Violation(
			'expressions.comparison.loose',
			"The `==` comparison\nmust be written `===`.",
			4,
			12,
			Severity::Warning,
			fingerprint: 'f1',
			risk: Risk::TypeUnknown,
			refused: true,
		),
	]));
	$reporter->reportFile(new FileResult('D:/other/b.php', '', '', [
		new Violation('mistakes.errorSuppression', 'The `@` operator must not be used.', 7, 3, Severity::Error, fingerprint: 'f2'),
	]));
	rewind($stream);
	$output = (string) stream_get_contents($stream);
	Assert::same(toLf(<<<'XX'
		Žluťoučký.php:4:12: warning: The `==` comparison must be written `===`. [expressions.comparison.loose; risk=typeUnknown]
		D:/other/b.php:7:3: error: The `@` operator must not be used. [mistakes.errorSuppression]

		XX), $output);

	// the pattern a problem matcher of VS Code reads it with, which the manual gives
	$pattern = '~^(.+?):(\d+)(?::(\d+))?: (error|warning): (.*?)(?: \[([^\]]+)\])?$~';
	Assert::same(1, preg_match($pattern, explode("\n", $output)[1], $m));
	Assert::same(
		['D:/other/b.php', '7', '3', 'error', 'The `@` operator must not be used.', 'mistakes.errorSuppression'],
		array_slice($m, 1),
	);
	Assert::same(1, preg_match($pattern, 'src/broken.php:2: error: Syntax error: Unexpected `;`', $m));
	Assert::same(['src/broken.php', '2', '', 'error', 'Syntax error: Unexpected `;`'], array_slice($m, 1));
});


test('an empty scope still gets the skeleton of a machine-readable format', function () {
	foreach ([JsonReporter::class, CheckstyleReporter::class, GithubReporter::class] as $class) {
		$stream = memory();
		$reporter = new $class($stream);
		$reporter->start(new RunInfo('', false, 0));
		$reporter->finish(new RunResult([], fix: false));
		rewind($stream);
		Assert::notSame('', (string) stream_get_contents($stream), $class);
	}
});


test('a name in a message that is not UTF-8 is written with a replacement character, the report is not lost', function () {
	$result = new FileResult('a.php', '', '', [
		new Violation('test/name', "The class `\xA9` must be written in PascalCase.", 2, 1, Severity::Error, fingerprint: 'f1'),
	]);
	foreach ([JsonReporter::class, CheckstyleReporter::class] as $class) {
		$stream = memory();
		$reporter = new $class($stream);
		$reporter->start(new RunInfo('', false, 1));
		$reporter->reportFile($result);
		$reporter->finish(new RunResult([FileSummary::of($result)], fix: false));
		rewind($stream);
		Assert::contains("The class `\u{FFFD}` must be written in PascalCase", (string) stream_get_contents($stream), $class);
	}
});


test('github: a warning about the run is an annotation of its own', function () {
	$stream = memory();
	$reporter = new GithubReporter($stream);
	$reporter->start(new RunInfo('', false, 1));
	$reporter->finish(new RunResult([], fix: false, warnings: ['1 entry of the baseline no longer matches a violation']));
	rewind($stream);
	Assert::same(
		"::warning title=dresscode::1 entry of the baseline no longer matches a violation\n0 violations in 1 file\n",
		stream_get_contents($stream),
	);
});


test('console: what follows from a violation is listed under it by message and rule, with the lines', function () {
	$violation = fn(string $rule, int $line, string $fingerprint, ?string $from = null) =>
		new Violation($rule, 'M.', $line, null, Severity::Error, fingerprint: $fingerprint, derivedFrom: $from);
	$stream = memory();
	$reporter = new ConsoleReporter(plain($stream));
	$reporter->start(new RunInfo('', false, 1));
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
		  error   2  M.                                       test/a
		             Then also, on lines 3, 4, 5: M.          test/a
		             Then also, on line 9: M.                 test/b
		  error  10  M.                                       test/a
		             Then also, on 5 lines from 11 to 15: M.  test/a
		  error  16  M.                                       test/a

		XX, normalize(stream_get_contents($stream)));
});


test('console: a file lists twenty violations, the rest counted by decision on one line', function () {
	$violation = fn(string $rule, int $line, string $fingerprint, ?string $from = null) =>
		new Violation($rule, 'M.', $line, null, Severity::Error, fingerprint: $fingerprint, derivedFrom: $from);
	$violations = array_map(fn(int $line) => $violation('test/a', $line, "f$line"), range(1, 21));
	array_push(
		$violations,
		$violation('test/b', 22, 'f22'),
		$violation('test/b', 23, 'f23'),
		$violation('test/c', 24, 'f24'),
		$violation('test/d', 25, 'f25'),
		$violation('test/e', 26, 'f26'),
		$violation('test/a', 27, 'd1', 'f21'),
	);
	$stream = memory();
	$reporter = new ConsoleReporter(plain($stream));
	$reporter->start(new RunInfo('', false, 1));
	$reporter->reportFile(new FileResult('a.php', '', '', $violations));
	rewind($stream);
	$output = normalize((string) stream_get_contents($stream));
	Assert::contains("  error  20  M.  test/a\n  … and 7 more: 2 test/a, 2 test/b, 1 test/c, 2 others\n", $output);
	Assert::notContains(' 21  ', $output);
});


test('console: in color the code of a message is drawn without its backticks and the columns stay aligned', function () {
	$stream = memory();
	$reporter = new ConsoleReporter(new Console($stream, colorDepth: ColorDepth::Ansi256));
	$reporter->start(new RunInfo('', false, 1));
	$reporter->reportFile(new FileResult('a.php', "<?php\n", null, [
		new Violation('test/a', 'Rename `$a`.', 2, 1, Severity::Error, fingerprint: 'f1'),
		new Violation('test/a', 'Rename the variable.', 3, 1, Severity::Error, fingerprint: 'f2'),
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
		  error  2:1  Rename $a.            test/a
		  error  3:1  Rename the variable.  test/a

		XX, normalize(Nette\CommandLine\Ansi::strip($output)));
});


test('console: paths under the working directory are relative to it, the others absolute', function () {
	$stream = memory();
	$reporter = new ConsoleReporter(plain($stream), root: '/project', cwd: '/project/src');
	$reporter->start(new RunInfo('', false, 2));
	$reporter->reportFile(new FileResult('src/a.php', '', '', [
		new Violation('acme.noX', 'No x.', 1, null, Severity::Error, fingerprint: 'f'),
	]));
	$reporter->reportFile(new FileResult('tests/b.php', '', '', [
		new Violation('acme.noY', 'No y.', 1, null, Severity::Error, fingerprint: 'f'),
	]));
	$reporter->reportFile(new FileResult('/elsewhere/c.php', '', '', [
		new Violation('acme.noZ', 'No z.', 1, null, Severity::Error, fingerprint: 'f'),
	]));
	rewind($stream);
	Assert::match(<<<'XX'
		a.php
		  error  1  No x.  acme.noX

		/project/tests/b.php
		  error  1  No y.  acme.noY

		/elsewhere/c.php
		  error  1  No z.  acme.noZ

		XX, normalize(stream_get_contents($stream)));
});


test('console: in a terminal a decision links to its page, elsewhere it stays bare', function () {
	$findUrl = fn(string $path) => $path === 'acme.noY' ? 'https://acme.dev/noY' : null;
	$violations = [
		new Violation('acme.noX', 'No x.', 1, null, Severity::Error, fingerprint: 'f1'),
		new Violation('acme.noY', 'No y.', 2, null, Severity::Error, fingerprint: 'f2'),
	];
	foreach ([true, false] as $terminal) {
		$stream = memory();
		$reporter = new ConsoleReporter(
			new Console($stream, colorDepth: ColorDepth::Ansi256, terminal: $terminal),
			findDecisionUrl: $findUrl,
		);
		$reporter->reportFile(new FileResult('a.php', '', '', $violations));
		rewind($stream);
		$output = (string) stream_get_contents($stream);
		Assert::same($terminal, str_contains($output, "\e]8;;https://acme.dev/noY\e\\acme.noY\e]8;;\e\\"));
		Assert::same(1, substr_count(Nette\CommandLine\Ansi::strip($output), 'acme.noY'));
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
		    "version": 1,
		    "mode": "check",
		    "root": "",
		    "files": [
		        {
		            "path": "src/a.php",
		            "violations": [
		                {
		                    "decision": "test/rename",
		                    "message": "Rename $a.",
		                    "line": 2,
		                    "column": 1,
		                    "endLine": 2,
		                    "endColumn": 3,
		                    "offset": 6,
		                    "length": 2,
		                    "severity": "error",
		                    "fixable": true,
		                    "risk": null,
		                    "refused": false,
		                    "because": null,
		                    "fingerprint": "f1",
		                    "derivedFrom": null
		                },
		                {
		                    "decision": "test/report",
		                    "message": "Variable \"b\" & <c>.",
		                    "line": 2,
		                    "column": null,
		                    "endLine": null,
		                    "endColumn": null,
		                    "offset": null,
		                    "length": null,
		                    "severity": "warning",
		                    "fixable": true,
		                    "risk": null,
		                    "refused": false,
		                    "because": null,
		                    "fingerprint": "f2",
		                    "derivedFrom": "f1"
		                },
		                {
		                    "decision": "test/rename",
		                    "message": "Rename $c.",
		                    "line": 3,
		                    "column": 1,
		                    "endLine": null,
		                    "endColumn": null,
		                    "offset": null,
		                    "length": null,
		                    "severity": "error",
		                    "fixable": true,
		                    "risk": null,
		                    "refused": false,
		                    "because": null,
		                    "fingerprint": "f3",
		                    "derivedFrom": "f1"
		                }
		            ],
		            "remaining": [
		                {
		                    "decision": "test/report",
		                    "message": "Variable \"b\" & <c>.",
		                    "line": 2,
		                    "column": null,
		                    "endLine": 2,
		                    "endColumn": 4,
		                    "offset": 8,
		                    "length": 1,
		                    "severity": "warning",
		                    "fixable": true,
		                    "risk": null,
		                    "refused": false,
		                    "because": null,
		                    "fingerprint": "f2",
		                    "derivedFrom": null
		                }
		            ],
		            "warnings": [
		                "Rule test/x is faulty: it changed the file without reporting a violation."
		            ],
		            "syntaxError": null,
		            "failure": null,
		            "changed": true,
		            "written": false
		        },
		        {
		            "path": "src/broken.php",
		            "violations": [],
		            "remaining": [],
		            "warnings": [],
		            "syntaxError": {
		                "message": "Unexpected `;`",
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
		            "syntaxError": null,
		            "failure": {
		                "message": "Rule test/x failed in src/fail.php: boom",
		                "docs": null
		            },
		            "changed": false,
		            "written": false
		        }
		    ],
		    "summary": {
		        "files": 4,
		        "violations": 3,
		        "remaining": 1,
		        "refused": 0,
		        "changedFiles": 1,
		        "syntaxErrors": 1,
		        "failures": 1,
		        "baselined": 0,
		        "exitCode": 2
		    },
		    "warnings": []
		}

		XX, capture(fn($s) => new JsonReporter($s), fix: false));

	$fix = json_decode(capture(fn($s) => new JsonReporter($s), fix: true), associative: true, flags: JSON_THROW_ON_ERROR);
	Assert::same(['version', 'mode', 'root', 'files', 'summary', 'warnings'], array_keys($fix));
	Assert::same('fix', $fix['mode']);
});


test('github: annotations addressed from the checkout, in a fix only what the fixed text still has', function () {
	Assert::match(<<<'XX'
		project/src/a.php:1  dresscode
		::warning file=project/src/a.php,line=1,title=dresscode::Rule test/x is faulty: it changed the file without reporting a violation.
		project/src/a.php:2  test/rename
		::error file=project/src/a.php,line=2,col=1,title=test/rename::Rename $a.
		project/src/a.php:2  test/report
		::warning file=project/src/a.php,line=2,title=test/report::Variable "b" & <c>.
		project/src/a.php:3  test/rename
		::error file=project/src/a.php,line=3,col=1,title=test/rename::Rename $c.
		project/src/broken.php:2  syntax error
		::error file=project/src/broken.php,line=2,title=syntax error::Unexpected `;`
		project/src/fail.php:1  dresscode
		::error file=project/src/fail.php,line=1,title=dresscode::Rule test/x failed in src/fail.php: boom
		3 violations in 4 files

		XX, capture(fn($s) => new GithubReporter($s, root: '/build/project', workspace: '/build'), fix: false));

	Assert::match(<<<'XX'
		project/src/a.php:1  dresscode
		::warning file=project/src/a.php,line=1,title=dresscode::Rule test/x is faulty: it changed the file without reporting a violation.
		project/src/a.php:2  test/report
		::warning file=project/src/a.php,line=2,title=test/report::Variable "b" & <c>.
		project/src/broken.php:2  syntax error
		::error file=project/src/broken.php,line=2,title=syntax error::Unexpected `;`
		project/src/fail.php:1  dresscode
		::error file=project/src/fail.php,line=1,title=dresscode::Rule test/x failed in src/fail.php: boom
		1 violation in 4 files

		XX, capture(fn($s) => new GithubReporter($s, root: '/build/project', workspace: '/build'), fix: true));

	// a file outside the root is absolute already
	$stream = memory();
	$reporter = new GithubReporter($stream, root: '/build/project', workspace: '/build');
	$reporter->start(new RunInfo('', false, 1));
	$reporter->reportFile(new FileResult('/build/other/a.php', '', null, failure: 'boom'));
	rewind($stream);
	Assert::same("other/a.php:1  dresscode\n::error file=other/a.php,line=1,title=dresscode::boom\n", stream_get_contents($stream));
});


test('checkstyle', function () {
	Assert::match(<<<'XX'
		<?xml version="1.0" encoding="UTF-8"?>
		<checkstyle version="1.0">
		  <file name="src/a.php">
		    <error line="1" severity="warning" message="Rule test/x is faulty: it changed the file without reporting a violation." source="dresscode"/>
		    <error line="2" column="1" severity="error" message="Rename $a." source="test/rename"/>
		    <error line="2" severity="warning" message="Variable &quot;b&quot; &amp; &lt;c&gt;." source="test/report"/>
		    <error line="3" column="1" severity="error" message="Rename $c." source="test/rename"/>
		  </file>
		  <file name="src/broken.php">
		    <error line="2" severity="error" message="Unexpected `;`" source="syntax"/>
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
		    <error line="1" severity="warning" message="Rule test/x is faulty: it changed the file without reporting a violation." source="dresscode"/>
		    <error line="2" severity="warning" message="Variable &quot;b&quot; &amp; &lt;c&gt;." source="test/report"/>
		  </file>
		  <file name="src/broken.php">
		    <error line="2" severity="error" message="Unexpected `;`" source="syntax"/>
		  </file>
		  <file name="src/fail.php">
		    <error line="1" severity="error" message="Rule test/x failed in src/fail.php: boom" source="dresscode"/>
		  </file>
		</checkstyle>

		XX, capture(fn($s) => new CheckstyleReporter($s), fix: true));
});


/**
 * @param list<FileResult> $results
 * @param ?Closure(string): array<string, list<string>> $examples
 * @param array<string, string> $givenPaths
 */
function captureAgent(
	array $results,
	bool $fix,
	?Closure $examples = null,
	array $givenPaths = [],
	bool $typesMissing = false,
): string
{
	$stream = memory();
	$reporter = new AgentReporter($stream, givenPaths: $givenPaths, findSuppressionExamples: $examples);
	$reporter->start(new RunInfo('', $fix, count($results), TypeAnalysisStatus::Available, $typesMissing));
	foreach ($results as $result) {
		$reporter->reportFile($result);
	}

	$reporter->finish(new RunResult(array_map(FileSummary::of(...), $results), $fix));
	rewind($stream);
	return (string) stream_get_contents($stream);
}


test('agent: a check lists what it found in sentences, and says nothing of a clean file', function () {
	Assert::same(toLf(<<<'XX'
		Found in `src/a.php`:
		- line 2: rename $a (`test/rename`). Fixing it settles 1 more on line 3.
		- line 2 (warning): variable "b" & <c> (`test/report`).

		DressCode fixes 2 of them itself when run as `fix`.

		DressCode warns about `src/a.php`: rule test/x is faulty: it changed the file without reporting a violation.

		`src/broken.php` has a syntax error on line 2, so DressCode left it as it was: unexpected `;`.

		DressCode failed on `src/fail.php` and left the file as it was: Rule test/x failed in src/fail.php: boom

		XX), captureAgent(results(), fix: false));

	Assert::same('', captureAgent([new FileResult('src/clean.php', "<?php\n", "<?php\n")], fix: true));
});


test('agent: a rule the configuration turns on that did not run without the types is said once, after what was found', function () {
	$output = captureAgent(results(), fix: false, typesMissing: true);
	Assert::true(str_ends_with(
		$output,
		"left the file as it was: Rule test/x failed in src/fail.php: boom\n\n"
		. "The checks that need the types of the code did not run, the configuration having no `typeAnalysis: phpstan`.\n",
	));
	Assert::same(1, substr_count($output, 'the types of the code'));
	Assert::same('', captureAgent([new FileResult('src/clean.php', "<?php\n", "<?php\n")], fix: true, typesMissing: true));
});


test('agent: a fix shows what it wrote as a diff and names the imports it removed', function () {
	$result = new FileResult(
		'src/a.php',
		"<?php\nuse Acme\\Mail as Post;\nuse function Acme\\run;\n\$a;\n",
		"<?php\n\$b;\n",
		remaining: [new Violation('test/report', 'Variable "b".', 2, null, Severity::Error, fingerprint: 'f2', fixable: false)],
		removedImports: [
			['kind' => 'class', 'name' => 'Acme\Mail', 'alias' => 'Post', 'namespace' => ''],
			['kind' => 'function', 'name' => 'Acme\run', 'alias' => null, 'namespace' => ''],
		],
	);
	$result->markWritten();
	Assert::same(toLf(<<<'XX'
		DressCode changed `src/a.php`; the changes below are already in the file:

		- removed the import of `Acme\Mail` as `Post`: nothing used it; if you meant to use it, add the import in the same edit as the use.
		- removed the import of function `Acme\run`: nothing used it; if you meant to use it, add the import in the same edit as the use.

		```diff
		@@ -1,4 +1,2 @@
		 <?php
		-use Acme\Mail as Post;
		-use function Acme\run;
		-$a;
		+$b;
		```

		Left in `src/a.php`, at the lines of the file as it is now:
		- line 2: variable "b" (`test/report`).

		XX), captureAgent([$result], fix: true));

	// a text that differs but was not written is no rewrite
	Assert::same('', captureAgent([new FileResult('src/a.php', "<?php\n\$a;\n", "<?php\n\$b;\n")], fix: true));
});


test('agent: a risky violation says why it was not fixed, and offers the comment the project accepts', function () {
	$violations = [
		new Violation(
			'expressions.comparison.loose',
			'The `==` comparison must be written `===`.',
			4,
			12,
			Severity::Warning,
			fingerprint: 'f1',
			risk: Risk::TypeUnknown,
			refused: true,
		),
		new Violation(
			'file.strictTypes.declaration',
			'Expected `declare(strict_types=1)` at the start of the file.',
			1,
			null,
			Severity::Error,
			fingerprint: 'f2',
			risk: Risk::BehaviorChanges,
			refused: true,
			because: 'PHP then refuses an argument of a scalar type it used to convert',
		),
		new Violation(
			'mistakes.errorSuppression',
			'The `@` operator must not be used.',
			7,
			3,
			Severity::Error,
			fingerprint: 'f3',
			fixable: false,
		),
		new Violation('test/plain', 'Plain.', 8, 3, Severity::Error, fingerprint: 'f4', fixable: false),
	];
	$examples = fn(string $path) => [
		'expressions.comparison.loose' => ['// intentionally =='],
		'mistakes.errorSuppression' => ['// @ <why>'],
	];
	Assert::same(toLf(<<<'XX'
		Left in `src/a.php`, at the lines of the file as it is now:
		- line 4 (warning): the `==` comparison must be written `===` (`expressions.comparison.loose`). Not fixed, because the type of the value is unknown. Change it where you have verified that the intended behaviour stays the same; where the current code is intended and you know why, keep it with a comment on its line, `// intentionally ==`; otherwise tell the user.
		- line 1: expected `declare(strict_types=1)` at the start of the file (`file.strictTypes.declaration`). Not fixed, because PHP then refuses an argument of a scalar type it used to convert. Change it only where the task asks for the behaviour the fix gives; otherwise tell the user.
		- line 7: the `@` operator must not be used (`mistakes.errorSuppression`). Fix it; where the current code is intended and you know why, keep it with a comment on its line, `// @ <why>`, giving the real reason for `<why>`; otherwise tell the user.
		- line 8: plain (`test/plain`).

		XX), captureAgent([new FileResult('src/a.php', '', '', remaining: $violations)], fix: true, examples: $examples));
});


test('agent: the message keeps to its budget and counts what it leaves out', function () {
	// a rewrite too long to show is summed up by the decisions whose rules made it
	$code = '<?php' . str_repeat("\n\$a;\n", AgentReporter::MaxLines);
	$result = new FileResult(
		'src/a.php',
		$code,
		str_replace('$a', '$b', $code),
		mutatedRules: [DressCode\Rules\Literals\StringQuotesRule::class],
	);
	$result->markWritten();
	Assert::same(toLf(<<<'XX'
		DressCode changed `src/a.php`; the changes below are already in the file:

		The changes, in 1 place, are too long to show here: read the file again before you edit it. They come from the rules of `literals.quotes`, an indication rather than an exact account.

		XX), captureAgent([$result], fix: true));

	// what does not fit is counted, and the whole message stays within the budget
	$violations = array_map(
		fn(int $i) => new Violation('test/plain', 'Plain.', $i, null, Severity::Error, fingerprint: "f$i", fixable: false),
		range(1, AgentReporter::MaxLines * 2),
	);
	$output = captureAgent([new FileResult('src/a.php', '', '', remaining: $violations)], fix: true);
	Assert::true(substr_count($output, "\n") <= AgentReporter::MaxLines);
	Assert::match('%A%- line %d%: plain (`test/plain`).%A?%%d% further violations are not listed here.%A?%', $output);
});


test('agent: the diff is fenced off from the sentences, and the file is named as the command line named it', function () {
	$result = new FileResult('src/a.php', "<?php\n// ```\n", "<?php\n// ````\n");
	$result->markWritten();
	Assert::same(toLf(<<<'XX'
		DressCode changed `./src/a.php`; the changes below are already in the file:

		`````diff
		@@ -1,2 +1,2 @@
		 <?php
		-// ```
		+// ````
		`````

		XX), captureAgent([$result], fix: true, givenPaths: ['src/a.php' => './src/a.php']));
});
