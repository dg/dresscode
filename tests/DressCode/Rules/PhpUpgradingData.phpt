<?php declare(strict_types=1);

use DressCode\Rules\Upgrading\{NoDeprecatedPhpCallsRule, PhpUpgradingData, PhpUpgradingOperation};
use DressCode\Testing\RuleTester;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the upgrading data of PHP are read from a file, the one DressCode ships unless another is given', function () {
	Assert::true(isset(PhpUpgradingData::fromFile()->getEntries()['utf8_encode']));
	Assert::same(PhpUpgradingData::fromFile(), PhpUpgradingData::fromFile()); // read once per process, not by every rule built

	$file = createTempDir('php-upgrading') . '/php.neon';
	file_put_contents($file, "since 8.1:\n\tforbiddenFunctions:\n\t\tacme_retired: deprecated\n\treplacedCalls:\n\t\t'Acme\\Mail\\Mailer::flush()': removed\n");
	$data = PhpUpgradingData::fromFile($file);
	Assert::same(['acme_retired', 'acme\mail\mailer::flush'], array_keys($data->getEntries()));
	Assert::same(PhpUpgradingOperation::Report, $data->getEntries()['acme_retired'][0]->operation);
	Assert::same([['acme\mail\mailer::flush', $data->getEntries()['acme\mail\mailer::flush'][0]]], $data->getMethodEntries('Flush'));
	Assert::same([], $data->getMethodEntries('send'));

	// the rule reads the data it is given
	RuleTester::check(new NoDeprecatedPhpCallsRule($data), "<?php\nacme_retired();\nutf8_encode('a');\n", violations: ['2: Function `acme_retired()` is deprecated since PHP 8.1.']);
});
