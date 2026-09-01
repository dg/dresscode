<?php declare(strict_types=1);

use DressCode\Engine\FileResult;
use DressCode\Engine\RunResult;
use DressCode\Reporter;
use DressCode\Reporters\CheckstyleReporter;
use DressCode\Reporters\ConsoleReporter;
use DressCode\Reporters\GithubReporter;
use DressCode\Reporters\JsonReporter;
use DressCode\Severity;
use DressCode\Violation;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** @return list<FileResult> */
function results(): array
{
	return [
		new FileResult('src/clean.php', "<?php\n", "<?php\n"),
		new FileResult('src/a.php', "<?php\n\$a;\n", "<?php\n\$b;\n", [
			new Violation('test/rename', 'Rename $a', 2, 1, Severity::Error, fixable: true, followUp: false, fingerprint: 'f1'),
			new Violation('test/report', 'Variable "b" & <c>', 2, null, Severity::Warning, fixable: false, followUp: true, fingerprint: 'f2'),
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
		  warning    2  Variable "b" & <c>  test/report
		  Rule test/x mutated the file without reporting a violation.

		src/broken.php
		  2  Syntax error, unexpected ';'

		src/fail.php
		  Rule test/x failed in src/fail.php: boom

		FAILED  2 violations, 1 of them fixable, 1 file with syntax errors, 1 file with failing rules in 3 of 4 files

		XX, normalize(capture(fn($s) => new ConsoleReporter($s), fix: false)));
});


test('console: fix lists what is left and counts what it fixed', function () {
	Assert::match(<<<'XX'
		src/a.php
		  fixed    2:1  Rename $a           test/rename
		  warning    2  Variable "b" & <c>  test/report
		  Rule test/x mutated the file without reporting a violation.
		--- src/a.php
		+++ src/a.php
		@@ -1,2 +1,2 @@
		 <?php
		-$a;
		+$b;

		src/broken.php
		  2  Syntax error, unexpected ';'

		src/fail.php
		  Rule test/x failed in src/fail.php: boom

		FAILED  1 violation fixed, 1 remaining, 1 file with syntax errors, 1 file with failing rules in 3 of 4 files

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


test('console: paths under the working directory are relative to it, the others absolute', function () {
	$stream = memory();
	$reporter = new ConsoleReporter($stream, root: '/project', cwd: '/project/src');
	$reporter->start(2, false);
	$reporter->reportFile(new FileResult('src/a.php', '', '', [
		new Violation('dresscode/no-x', 'No x', 1, null, Severity::Error, fixable: false, followUp: false, fingerprint: 'f'),
	]));
	$reporter->reportFile(new FileResult('tests/b.php', '', '', [
		new Violation('acme/no-y', 'No y', 1, null, Severity::Error, fixable: false, followUp: false, fingerprint: 'f'),
	]));
	rewind($stream);
	Assert::match(<<<'XX'
		a.php
		  error  1  No x  no-x

		/project/tests/b.php
		  error  1  No y  acme/no-y

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
		                    "followUp": false,
		                    "fingerprint": "f1"
		                },
		                {
		                    "rule": "test/report",
		                    "message": "Variable \"b\" & <c>",
		                    "line": 2,
		                    "column": null,
		                    "severity": "warning",
		                    "fixable": false,
		                    "followUp": true,
		                    "fingerprint": "f2"
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
		        "violations": 2,
		        "fixable": 1,
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
		::error file=project/src/broken.php,line=2,title=syntax error::Syntax error, unexpected ';'
		::error file=project/src/fail.php,line=1,title=dresscode::Rule test/x failed in src/fail.php: boom
		2 violations in 4 files

		XX, capture(fn($s) => new GithubReporter($s, root: '/build/project', workspace: '/build'), fix: false));
});


test('checkstyle', function () {
	Assert::match(<<<'XX'
		<?xml version="1.0" encoding="UTF-8"?>
		<checkstyle version="1.0">
		  <file name="src/a.php">
		    <error line="2" column="1" severity="error" message="Rename $a" source="test/rename"/>
		    <error line="2" severity="warning" message="Variable &quot;b&quot; &amp; &lt;c&gt;" source="test/report"/>
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
