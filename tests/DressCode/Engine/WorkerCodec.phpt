<?php declare(strict_types=1);

use DressCode\Engine\WorkerCodec;
use DressCode\{FileResult, Risk, Severity, Violation};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$roundTrip = function (FileResult $result): FileResult {
	$data = json_decode(json_encode(WorkerCodec::encode($result), JSON_THROW_ON_ERROR), associative: true);
	Assert::type('array', $data);
	return WorkerCodec::decode($data, $result->code);
};


test('a result survives the way from a worker to the parent and back', function () use ($roundTrip) {
	$violation = new Violation('test/a', 'A.', 2, 3, Severity::Warning, fingerprint: '1234', risk: Risk::TypeUnknown, refused: true, because: 'why', derivedFrom: 'f0');
	$result = new FileResult('a.php', "<?php\n\$a;\n", "<?php\n\$b;\n", [$violation], ['w'], passes: 2, baselined: ['99'], remaining: [$violation]);
	$result->markWritten();
	Assert::equal($result, $roundTrip($result));
});


test('an output that is not valid UTF-8 survives the way byte for byte', function () {
	$code = "<?php\n\$a = \"\xFF\";\n";
	$result = new FileResult('a.php', $code, "<?php\n\$b = \"\xFF\xFE\";\n");
	$json = json_encode(WorkerCodec::encode($result), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
	$decoded = WorkerCodec::decode(json_decode($json, associative: true), $code);
	Assert::same($result->output, $decoded->output);
	Assert::same($code, $decoded->code);
});


test('a path that is not valid UTF-8 survives the way byte for byte', function () use ($roundTrip) {
	$result = new FileResult("\xE9.php", "<?php\n", "<?php\n");
	Assert::equal($result, $roundTrip($result));
});


test('a message that is not valid UTF-8 gets through the transport with the bad bytes substituted', function () {
	$violation = new Violation('test/a', "bad \xFF byte.", 1, null, Severity::Error, fingerprint: 'f1');
	$result = new FileResult('a.php', "<?php\n", "<?php\n", [$violation], remaining: [$violation]);
	$json = json_encode(WorkerCodec::encode($result), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
	$decoded = WorkerCodec::decode(json_decode($json, associative: true), $result->code);
	Assert::same("bad \u{FFFD} byte.", $decoded->violations[0]->message);
	Assert::same("bad \u{FFFD} byte.", $decoded->remaining[0]->message);
});


test('an unchanged output, a syntax error and a result from the cache', function () use ($roundTrip) {
	$clean = new FileResult('a.php', "<?php\n", "<?php\n", cached: true);
	Assert::equal($clean, $roundTrip($clean));

	$broken = new FileResult('b.php', "<?php\n\$a = ;\n", null, syntaxError: 'Unexpected `;`', syntaxErrorLine: 2);
	Assert::equal($broken, $roundTrip($broken));
});
