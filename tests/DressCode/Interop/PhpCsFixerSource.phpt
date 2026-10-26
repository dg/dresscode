<?php declare(strict_types=1);

use DressCode\Interop\PhpCsFixerSource;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the finder is read from the syntax tree, a chain, a variable or the finder of the config, literals alone', function () {
	$source = PhpCsFixerSource::fromCode(<<<'XX'
		<?php
		use Symfony\Component\Finder\Finder;
		// ->in(__DIR__ . '/commented') is no call
		$rules = ['@PSR12' => true, '@Symfony:risky' => false, 'strict_param' => true];
		$finder = PhpCsFixer\Finder::create()
			->in(__DIR__ . '/src')
			->exclude(['Generated', 'Cache'])
			->notPath('#/Fixtures/#')
			->notName('*.phpt')
			->append([__FILE__]);
		$finder->in('tests');
		$config->getFinder()->in(__DIR__ . '/lib');
		(new Finder)->in($dirs)->filter(fn() => true);
		$config->setRules(getRules());
		return (new PhpCsFixer\Config)->setRules($rules)->setFinder($finder);
		XX, '/project/.php-cs-fixer.dist.php');

	Assert::same(['/project/src', 'tests', '/project/lib'], $source->in);
	Assert::same(['Generated', 'Cache'], $source->exclude);
	Assert::same(['#/Fixtures/#'], $source->notPath);
	Assert::same(['*.phpt'], $source->notName);
	Assert::same(['/project/.php-cs-fixer.dist.php'], $source->append);
	Assert::same(['@PSR12'], $source->sets);
	Assert::same(['2 finders, whose scopes are taken together', 'filter()', 'in($dirs)'], $source->unread);
});


test('what the reading of the finder cannot be sure of is told: a condition, a method it does not know, another class', function () {
	$source = PhpCsFixerSource::fromCode(<<<'XX'
		<?php
		$finder = PhpCsFixer\Finder::create()->in(__DIR__)->ignoreDotFiles(false)->sortByName();
		if (getenv('CI')) {
			$finder->exclude('slow');
		}
		$finder = Acme\Finder::create();
		return (new PhpCsFixer\Config)->setFinder($finder);
		XX, '/project/.php-cs-fixer.php');

	Assert::same(['/project'], $source->in);
	Assert::same([], $source->exclude);
	Assert::same([
		'$finder, which a finder was assigned to and is assigned again',
		'`Acme\Finder`, a class named Finder of another vendor',
		'ignoreDotFiles()',
		'exclude(\'slow\'), which a condition decides',
	], $source->unread);
});


test('code that does not parse is read as nothing', function () {
	Assert::same(['the configuration, which does not parse'], PhpCsFixerSource::fromCode('<?php ->', '/x.php')->unread);
});
