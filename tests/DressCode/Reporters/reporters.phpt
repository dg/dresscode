<?php declare(strict_types=1);

use DressCode\FileResult;
use DressCode\Reporter;
use DressCode\Reporters\CheckstyleReporter;
use DressCode\Reporters\ConsoleReporter;
use DressCode\Reporters\GithubReporter;
use DressCode\Reporters\JsonReporter;
use DressCode\RunResult;
use DressCode\Severity;
use DressCode\Violation;
use Tester\Assert;


require __DIR__ . '/../../bootstrap.php';


/** @return list<FileResult> */
function results(): array
{
	return [
		new FileResult('src/clean.php', "<?php\n", "<?php\n"),
		// the third follows from the first and ends like it; the second follows from it too but is a warning
		new FileResult('src/a.php', "<?php\n\$a;\n\$c;\n", "<?php\n\$b;\n\$d;\n", [
			new Violation('test/rename', 'Rename $a', 2, 1, Severity::Error, fixable: true, fingerprint: 'f1'),
			new Violation('test/report', 'Variable "b" & <c>', 2, null, Severity::Warning, fixable: false, fingerprint: 'f2', derivedFrom: 'f1'),
			new Violation('test/rename', 'Rename $c', 3, 1, Severity::Error, fixable: true, fingerprint: 'f3', derivedFrom: 'f1'),
		], ['Rule test/x mutated the file without reporting a violation.']),
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


test('console: check lists every violation', function () {
	Assert::match(<<<'XX'
		src/a.php
		  error    2:1  Rename $a           test/rename
		                followed by test/rename on line 3
		  warning    2  Variable "b" & <c>  test/report
		  Rule test/x mutated the file without reporting a violation.

		src/broken.php
		  2  Syntax error, unexpected ';'

		src/fail.php
		  Rule test/x failed in src/fail.php: boom

		FAILED  2 violations, 1 of them following from others, 1 warning, 2 of them fixable, 1 file with syntax errors, 1 file with failing rules in 3 of 4 files

		XX, normalize(capture(fn($s) => new ConsoleReporter($s), fix: false)));
});


test('console: fix lists what is left and counts what it fixed', function () {
	Assert::match(<<<'XX'
		src/a.php
		  fixed    2:1  Rename $a           test/rename
		                followed by test/rename on line 3
		  warning    2  Variable "b" & <c>  test/report
		  Rule test/x mutated the file without reporting a violation.
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

		FAILED  2 violations fixed, 1 of them following from others, 1 warning, 1 file with syntax errors, 1 file with failing rules in 3 of 4 files

		XX, normalize(capture(fn($s) => new ConsoleReporter($s, diff: true), fix: true)));
});


test('console: verdict of a clean run', function () {
	$stream = memory();
	$reporter = new ConsoleReporter($stream);
	$reporter->start(1, false);
	$reporter->finish(new RunResult([new FileResult('a.php', '', '')], false));
	$reporter->start(1, true);
	$reporter->finish(new RunResult([new FileResult('a.php', '', '')], true));
	rewind($stream);
	Assert::same("OK  no violations in 1 file\nOK  no violations in 1 file\n", stream_get_contents($stream));
});


test('bare: what is left to the user and which files were rewritten, nothing else', function () {
	Assert::match(<<<'XX'
		src/a.php  rewritten
		  warning  2  Variable "b" & <c>  test/report
		  Rule test/x mutated the file without reporting a violation.

		src/broken.php
		  2  Syntax error, unexpected ';'

		src/fail.php
		  Rule test/x failed in src/fail.php: boom

		XX, normalize(capture(fn($s) => new ConsoleReporter($s, diff: true, bare: true), fix: true)));
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
	$reporter->finish(new RunResult([], false, warnings: ['1 entry of the baseline no longer match a violation']));
	rewind($stream);
	Assert::same(
		"::warning title=dresscode::1 entry of the baseline no longer match a violation\n0 violations in 1 file\n",
		stream_get_contents($stream),
	);
});


test('console: what follows from a violation is described by rule and line', function () {
	$violation = fn(string $rule, int $line, string $fingerprint, ?string $from = null) =>
		new Violation($rule, 'M', $line, null, Severity::Error, fixable: false, fingerprint: $fingerprint, derivedFrom: $from);
	$stream = memory();
	$reporter = new ConsoleReporter($stream);
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


test('console: paths under the working directory are relative to it, the others absolute', function () {
	$stream = memory();
	$reporter = new ConsoleReporter($stream, root: '/project', cwd: '/project/src');
	$reporter->start(2, false);
	$reporter->reportFile(new FileResult('src/a.php', '', '', [
		new Violation('dresscode/no-x', 'No x', 1, null, Severity::Error, fixable: false, fingerprint: 'f'),
	]));
	$reporter->reportFile(new FileResult('tests/b.php', '', '', [
		new Violation('acme/no-y', 'No y', 1, null, Severity::Error, fixable: false, fingerprint: 'f'),
	]));
	$reporter->reportFile(new FileResult('/elsewhere/c.php', '', '', [
		new Violation('acme/no-z', 'No z', 1, null, Severity::Error, fixable: false, fingerprint: 'f'),
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
		                    "fixable": true,
		                    "risky": false,
		                    "fingerprint": "f1",
		                    "derivedFrom": null
		                },
		                {
		                    "rule": "test/report",
		                    "message": "Variable \"b\" & <c>",
		                    "line": 2,
		                    "column": null,
		                    "severity": "warning",
		                    "fixable": false,
		                    "risky": false,
		                    "fingerprint": "f2",
		                    "derivedFrom": "f1"
		                },
		                {
		                    "rule": "test/rename",
		                    "message": "Rename $c",
		                    "line": 3,
		                    "column": 1,
		                    "severity": "error",
		                    "fixable": true,
		                    "risky": false,
		                    "fingerprint": "f3",
		                    "derivedFrom": "f1"
		                }
		            ],
		            "warnings": [
		                "Rule test/x mutated the file without reporting a violation."
		            ],
		            "error": null,
		            "failure": null,
		            "changed": true,
		            "written": false
		        },
		        {
		            "path": "src/broken.php",
		            "violations": [],
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
		        "fixable": 2,
		        "riskyDeferred": 0,
		        "changedFiles": 1,
		        "errors": 1,
		        "failures": 1,
		        "baselined": 0
		    },
		    "warnings": []
		}

		XX, capture(fn($s) => new JsonReporter($s), fix: false));
});


test('github: annotations addressed from the checkout', function () {
	Assert::match(<<<'XX'
		::warning file=project/src/a.php,line=1,title=dresscode::Rule test/x mutated the file without reporting a violation.
		::error file=project/src/a.php,line=2,col=1,title=test/rename::Rename $a
		::warning file=project/src/a.php,line=2,title=test/report::Variable "b" & <c>
		::error file=project/src/a.php,line=3,col=1,title=test/rename::Rename $c
		::error file=project/src/broken.php,line=2,title=syntax error::Syntax error, unexpected ';'
		::error file=project/src/fail.php,line=1,title=dresscode::Rule test/x failed in src/fail.php: boom
		3 violations in 4 files

		XX, capture(fn($s) => new GithubReporter($s, root: '/build/project', workspace: '/build'), fix: false));
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
});
