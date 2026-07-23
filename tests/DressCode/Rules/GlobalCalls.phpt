<?php declare(strict_types=1);

use DressCode\Analyses\Registry;
use DressCode\Engine\{Fingerprints, Suppression};
use DressCode\{RuleContext, Style};
use DressCode\Rules\GlobalCalls;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Parser;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** @return array{FunctionCallNode, RuleContext}  the first call in the code and the context of its file */
function prepare(string $code): array
{
	$file = (new Parser)->parse("<?php\n$code");
	$context = new RuleContext(
		$file,
		'a.php',
		new Style,
		'8.4',
		new Registry,
		Suppression::fromFile($file, fn() => []),
		new Fingerprints([]),
	);
	$calls = $file->find(FunctionCallNode::class);
	Assert::true(isset($calls[0]));
	return [$calls[0], $context];
}


$names = ['strlen' => true];


test('findFunction: a call of a function of the map, whatever the case', function () use ($names) {
	[$call, $context] = prepare('STRLEN($x);');
	Assert::same('strlen', GlobalCalls::findFunction($call, $names, $context));

	[$call, $context] = prepare('\strlen($x);');
	Assert::same('strlen', GlobalCalls::findFunction($call, $names, $context));

	[$call, $context] = prepare('strpos($x);');
	Assert::null(GlobalCalls::findFunction($call, $names, $context));
});


test('findFunction: the values of the map are not read, null among them', function () {
	[$call, $context] = prepare('strlen($x);');
	Assert::same('strlen', GlobalCalls::findFunction($call, ['strlen' => null], $context));
	Assert::same('strlen', GlobalCalls::findFunction($call, ['strlen' => false], $context));
});


test('findFunction: a function imported under another name is the one it stands for', function () use ($names) {
	[$call, $context] = prepare("use function strlen as len;\nlen(\$x);");
	Assert::same('strlen', GlobalCalls::findFunction($call, $names, $context));

	[$call, $context] = prepare("use function strpos as strlen;\nstrlen(\$x);");
	Assert::null(GlobalCalls::findFunction($call, $names, $context));

	[$call, $context] = prepare("use function strpos as len;\nlen(\$x);");
	Assert::null(GlobalCalls::findFunction($call, $names, $context));
});


test('findFunction: a function of a namespace is not the global one, a name of the map or not', function () use ($names) {
	[$call, $context] = prepare('\Acme\strlen($x);');
	Assert::null(GlobalCalls::findFunction($call, $names, $context));

	[$call, $context] = prepare("use function Acme\\strlen;\nstrlen(\$x);");
	Assert::null(GlobalCalls::findFunction($call, $names, $context));

	[$call, $context] = prepare('Acme\strlen($x);');
	Assert::null(GlobalCalls::findFunction($call, $names, $context));

	[$call, $context] = prepare('\Acme\other($x);');
	Assert::null(GlobalCalls::findFunction($call, $names, $context));
});


test('findFunction: a keyword written as a name is never a function, even where the map names it', function () {
	[$call, $context] = prepare('readonly($x);');
	Assert::null(GlobalCalls::findFunction($call, ['readonly' => true], $context));

	// `exit` and `isset` are not calls at all
	$file = (new Parser)->parse("<?php\nexit(\$x);\nisset(\$x);");
	Assert::same([], $file->find(FunctionCallNode::class));
});


test('findUncertainty: an unqualified call in a namespace may call a function of the namespace', function () {
	[$call, $context] = prepare("namespace Acme;\nstrlen(\$x);");
	Assert::same('the namespace may declare `strlen()`', GlobalCalls::findUncertainty($call, $context));

	[$call, $context] = prepare("namespace Acme;\nSTRLEN(\$x);");
	Assert::same('the namespace may declare `strlen()`', GlobalCalls::findUncertainty($call, $context));
});


test('findUncertainty: a qualified call, a call in the global namespace and a function imported are certain', function () {
	[$call, $context] = prepare("namespace Acme;\n\\strlen(\$x);");
	Assert::null(GlobalCalls::findUncertainty($call, $context));

	[$call, $context] = prepare('strlen($x);');
	Assert::null(GlobalCalls::findUncertainty($call, $context));

	[$call, $context] = prepare("namespace Acme;\nAcme\\strlen(\$x);");
	Assert::null(GlobalCalls::findUncertainty($call, $context));

	[$call, $context] = prepare("namespace Acme;\nuse function strlen;\nstrlen(\$x);");
	Assert::null(GlobalCalls::findUncertainty($call, $context));
});
