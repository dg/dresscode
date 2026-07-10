<?php declare(strict_types=1);

use DressCode\Engine\FileResult;
use DressCode\Engine\RunResult;
use DressCode\Reporter;
use DressCode\Reporters\CheckstyleReporter;
use DressCode\Reporters\ConsoleReporter;
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


test('console: check', function () {
	Assert::match(<<<'XX'
		src/a.php
		  2:1     error    Rename $a  [test/rename]  (fixable)
		  2       warning  Variable "b" & <c>  [test/report]  (follow-up)
		          warning  Rule test/x mutated the file without reporting a violation.

		src/broken.php
		  2       error    Syntax error, unexpected ';'

		src/fail.php
		          failure  Rule test/x failed in src/fail.php: boom

		Found 2 violations (1 fixable) in 4 files, 1 file with syntax errors, 1 file failed.

		XX, capture(fn($s) => new ConsoleReporter($s), fix: false));
});


test('console: fix with diff', function () {
	Assert::match(<<<'XX'
		src/a.php
		  2:1     error    Rename $a  [test/rename]  (fixed)
		  2       warning  Variable "b" & <c>  [test/report]  (follow-up)
		          warning  Rule test/x mutated the file without reporting a violation.
		--- src/a.php
		+++ src/a.php
		@@ -1,2 +1,2 @@
		 <?php
		-$a;
		+$b;

		src/broken.php
		  2       error    Syntax error, unexpected ';'

		src/fail.php
		          failure  Rule test/x failed in src/fail.php: boom

		Fixed 1 violation in 1 file, 1 violation remains, 1 file with syntax errors, 1 file failed.

		XX, capture(fn($s) => new ConsoleReporter($s, diff: true), fix: true));
});


test('console: summary of a clean run', function () {
	$stream = memory();
	$reporter = new ConsoleReporter($stream);
	$reporter->start(1, false);
	$reporter->finish(new RunResult([new FileResult('a.php', '', '')], false));
	$reporter->start(1, true);
	$reporter->finish(new RunResult([new FileResult('a.php', '', '')], true));
	rewind($stream);
	Assert::same("No violations found in 1 file.\nNothing to fix in 1 file.\n", stream_get_contents($stream));
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
		        "failures": 1
		    }
		}

		XX, capture(fn($s) => new JsonReporter($s), fix: false));
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
