<?php declare(strict_types=1);

use DressCode\{FileResult, Severity, Violation};
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('a result survives the way to another process and back', function () {
	$violation = new Violation('test/a', 'A', 2, 3, Severity::Warning, fingerprint: '1234', risky: true, refused: true, derivedFrom: 'f0');
	$result = new FileResult('a.php', "<?php\n\$a;\n", "<?php\n\$b;\n", [$violation], ['w'], passes: 2, baselined: ['99'], remaining: [$violation]);
	$result->written = true;

	$data = json_decode(json_encode($result->toArray(), JSON_THROW_ON_ERROR), associative: true);
	Assert::type('array', $data);
	Assert::equal($result, FileResult::fromArray($data, $result->code));
});
