<?php declare(strict_types=1);

/**
 * The maps of the upgrading rules reach one and the same use, and which of them decides is part of their contract.
 * A pair is run over one file with the types of the code, because that is what tells a rule whose member a use reaches.
 */

use DressCode\{Analyses, Config, ImportStyle, Style, Violation};
use DressCode\Config\RuleBuilder;
use DressCode\Engine\{PassLoop, ReportPolicy, RulePlan};
use DressCode\Rules\Upgrading;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\{Parser, Printer};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/**
 * Runs the rules over the code with the types of the declarations beside it, and returns what they say.
 * @param  array<class-string<DressCode\Rule>, array<string, mixed>|true>  $rules
 * @param  array<string, mixed>  $values  path => value, as a map whose rule the run is narrowed away from
 * @return list<string>  `line: message` each
 */
function upgrade(array $rules, string $code, string $expected, array $values = []): array
{
	// the rules share the values, as in a run, so that a rule leaving a use to another map reads it
	foreach ($rules as $class => $options) {
		$options = $options === true ? [] : $options;
		$values = [...$values, ...defined("$class::Map") ? [$class::Map => $options] : $options];
	}

	$resolved = RuleBuilder::resolveValues(array_keys($rules), $values);
	$instances = RuleBuilder::createRules(array_keys($rules), $resolved);

	$stubs = __DIR__ . '/fixtures/upgrading-interplay.php';
	static $dir;
	$dir ??= createTempDir('upgrading');
	$registry = new Analyses\Registry;
	$registry->register(Analyses\Types::class, function (FileNode $file) use ($stubs, $dir): Analyses\Types {
		// PHPStan reads the declarations of a file from the disk, so the code goes to a file named by its text
		$text = Printer::print($file);
		$path = $dir . '/' . hash('xxh128', $text) . '.php';
		if (!is_file($path)) {
			file_put_contents($path, $text);
		}

		static $phpstan = [];
		$phpstan[$path] ??= new Analyses\PhpStan(dirname($stubs), [$stubs, $path], "$dir/cache");
		return new Analyses\Types($file, $path, $phpstan[$path]);
	});

	$file = new Parser()->parse($code);
	$runner = new PassLoop(new RulePlan($instances), $registry, new ReportPolicy(strict: true));
	$result = $runner->run($file, $code, 'upgrading.php', new Style("\t", "\n", imports: ImportStyle::fromValues($resolved)), Config::DefaultPhpVersion);
	Assert::same($expected, Printer::print($file));
	return array_map(fn(Violation $v) => "$v->line: $v->message", $result->violations);
}


test('a member nothing can be written instead of keeps the class it is reached through', function () {
	// the ban says the member has no place in the class written instead, so the access and the import that carries
	// it stay as they are, while every other reference of the class is rewritten
	$code = "<?php\n\nnamespace App;\n\nuse Old\\Router;\n\nfunction test(): void\n{\n\t\$a = Router::Secured;\n\t\$b = Router::OneWay;\n}\n";
	$expected = "<?php\n\nnamespace App;\n\nuse Old\\Router;\n\nfunction test(): void\n{\n\t\$a = Router::Secured;\n\t\$b = \\Fresh\\Router::OneWay;\n}\n";
	Assert::same([
		'9: Constant `Old\Router::Secured` is forbidden: write the protocol into the mask.',
		'10: Class `Old\Router` is replaced by `Fresh\Router`.',
	], upgrade([
		Upgrading\ReplacedClassesRule::class => ['Old\Router' => 'Fresh\Router'],
		Upgrading\ForbiddenMembersRule::class => ['Old\Router::Secured' => 'write the protocol into the mask'],
	], $code, $expected));
});


test('a doc comment naming a member nothing can be written instead of keeps the class too', function () {
	$code = "<?php\n\nnamespace App;\n\nuse Old\\Router;\n\n/**\n * @see Router::Secured\n * @see Router::OneWay\n */\nfunction test(): void\n{\n}\n";
	$expected = "<?php\n\nnamespace App;\n\nuse Old\\Router;\n\n/**\n * @see Router::Secured\n * @see \\Fresh\\Router::OneWay\n */\nfunction test(): void\n{\n}\n";
	Assert::same([
		'7: Class `Old\Router` is replaced by `Fresh\Router`.',
	], upgrade([
		Upgrading\ReplacedClassesRule::class => ['Old\Router' => 'Fresh\Router'],
		Upgrading\ForbiddenMembersRule::class => ['Old\Router::Secured' => 'write the protocol into the mask'],
	], $code, $expected));
});


test('a member written under another name takes the class written instead with it', function () {
	// replacedMembers writes the name and replacedClasses the class, so the two together give the whole access
	$code = "<?php\n\nnamespace App;\n\nuse Old\\Legacy;\n\nfunction test(): void\n{\n\t\$a = Legacy::OldName;\n}\n";
	$expected = "<?php\n\nnamespace App;\n\nuse Fresh\\Modern;\n\nfunction test(): void\n{\n\t\$a = Modern::NewName;\n}\n";
	Assert::same([
		'5: Class `Old\Legacy` is replaced by `Fresh\Modern`.',
		'9: Class `Old\Legacy` is replaced by `Fresh\Modern`.',
		'9: Constant `Old\Legacy::OldName` is replaced by `Legacy::NewName`.',
	], upgrade([
		Upgrading\ReplacedClassesRule::class => ['Old\Legacy' => 'Fresh\Modern'],
		Upgrading\ReplacedMembersRule::class => ['Old\Legacy::OldName' => 'NewName'],
	], $code, $expected));
});


test('an annotation is read through the import it was written with, whatever renames the class', function () {
	// the import stays for the annotation until it is an attribute, and then goes as any other reference of it; the
	// attribute is written in full while the short name still names the old class, and the renamed import shortens it
	$code = "<?php\n\nnamespace App;\n\nuse Old\\Annotation\\Route;\n\nclass Blog\n{\n\t/** @Route(\"/blog\") */\n\tpublic function list(): void\n\t{\n\t}\n}\n";
	$expected = "<?php\n\nnamespace App;\n\nuse Fresh\\Attribute\\Route;\n\nclass Blog\n{\n\t#[Route('/blog')]\n\tpublic function list(): void\n\t{\n\t}\n}\n";
	Assert::same([
		'5: Class `Old\Annotation\Route` is replaced by `Fresh\Attribute\Route`.',
		'9: Annotation `@Route` is replaced by the attribute `#[Fresh\Attribute\Route]`.',
	], upgrade([
		Upgrading\ReplacedClassesRule::class => ['Old\Annotation\Route' => 'Fresh\Attribute\Route'],
		Upgrading\AttributeForAnnotationRule::class => ['Old\Annotation\*' => 'Fresh\Attribute\*'],
	], $code, $expected));
});


test('a deprecated member a map has is the map\'s, whether or not its rule runs', function () {
	$code = "<?php\n\nnamespace App;\n\nfunction test(\\Old\\Mailer \$mailer): void\n{\n\t\$mailer->post('a');\n}\n";
	$fixed = str_replace('post', 'transmit', $code);
	Assert::same(
		['7: Method `Old\Mailer::post()` is deprecated: use transmit().'],
		upgrade([Upgrading\NoDeprecatedMembersRule::class => true], $code, $fixed),
	);
	Assert::same([], upgrade([Upgrading\NoDeprecatedMembersRule::class => true], $code, $code, [
		Upgrading\ReplacedMembersRule::Map => ['Old\Mailer::post' => 'deliver'],
	]));
});


test('a call a key of replacedCalls binds is that map\'s, whether or not its rule runs', function () {
	// the shape of the arguments of a call is more specific than its name, so replacedMembers renames only the other call
	$code = "<?php\n\nnamespace App;\n\nfunction test(\\Old\\Mailer \$mailer): void\n{\n\t\$mailer->send('a');\n\t\$mailer->send('b', true);\n}\n";
	$members = [Upgrading\ReplacedMembersRule::class => ['Old\Mailer::send' => 'transmit']];
	$calls = ['Old\Mailer::send($to, true)' => 'transmitNow($to)'];
	Assert::same(
		['7: Method `Old\Mailer::send()` is replaced by `Mailer::transmit()`.'],
		upgrade($members, $code, str_replace("send('a')", "transmit('a')", $code), [Upgrading\ReplacedCallsRule::Map => $calls]),
	);
	Assert::same(
		[
			'7: Method `Old\Mailer::send()` is replaced by `Mailer::transmit()`.',
			'8: Method `Old\Mailer::send()` is replaced by `transmitNow($to)`.',
		],
		upgrade(
			$members + [Upgrading\ReplacedCallsRule::class => $calls],
			$code,
			str_replace(["send('a')", "send('b', true)"], ["transmit('a')", "transmitNow('b')"], $code),
		),
	);
});


test('a deprecated class a map of classes has is the map\'s, whether or not its rule runs', function () {
	$code = "<?php\n\nnamespace App;\n\nuse Old\\Bus\\Handler;\n\nclass Orders implements Handler\n{\n}\n";
	Assert::same(
		[
			'5: Class `Old\Bus\Handler` is deprecated: write the attribute AsHandler.',
			'7: Class `Old\Bus\Handler` is deprecated: write the attribute AsHandler.',
		],
		upgrade([Upgrading\NoDeprecatedClassesRule::class => true], $code, $code),
	);
	Assert::same([], upgrade([Upgrading\NoDeprecatedClassesRule::class => true], $code, $code, [
		Upgrading\ForbiddenClassesRule::Map => ['Old\Bus\Handler' => null],
	]));
});
