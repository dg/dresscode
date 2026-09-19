<?php declare(strict_types=1);

use DressCode\Analyses;
use DressCode\Analyses\Access;
use DressCode\Analyses\MemberKind;
use DressCode\Rules\Classes\MemberPattern;
use PhpSyntax\Parser;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** The types of a file that declares App\MyLoader, a child of Nette\Loaders\RobotLoader from the stubs of the analysis. */
function createTypes(): Analyses\Types
{
	$dir = sys_get_temp_dir() . '/dresscode-tests/member-pattern';
	@mkdir($dir, recursive: true);
	$code = "<?php\nnamespace App;\nclass MyLoader extends \\Nette\\Loaders\\RobotLoader {}\n";
	$path = "$dir/MyLoader.php";
	file_put_contents($path, $code);
	$stubs = __DIR__ . '/../Analyses/fixtures/types/stubs';
	return new Analyses\Types((new Parser)->parse($code), $path, new Analyses\PhpStan($stubs, [$stubs, $path], "$dir/cache"));
}


test('a key is read the way an upgrading guide writes a member', function () {
	$read = fn(string $key) => get_object_vars(MemberPattern::fromKey($key));

	Assert::same(['class' => 'Nette\Forms\Form', 'kind' => null, 'name' => 'FILLED', 'arguments' => null], $read('Nette\Forms\Form::FILLED'));
	Assert::same(['class' => 'Nette\Forms\Form', 'kind' => MemberKind::Method, 'name' => 'size', 'arguments' => null], $read('\Nette\Forms\Form::size()'));
	Assert::same(['class' => 'Nette\Forms\Form', 'kind' => MemberKind::Property, 'name' => 'filled', 'arguments' => null], $read(' Nette\Forms\Form::$filled '));
	Assert::same(['class' => 'dibi', 'kind' => MemberKind::Method, 'name' => 'addUpload', 'arguments' => '$name, $label, true'], $read('dibi::addUpload( $name, $label, true )'));
	Assert::same(['class' => 'A\Mapper', 'kind' => MemberKind::Constructor, 'name' => '__construct', 'arguments' => '$iterator, fn() => (1)'], $read('A\Mapper::__CONSTRUCT($iterator, fn() => (1))'));
	Assert::same(['class' => 'A\Mapper', 'kind' => MemberKind::Constructor, 'name' => '__construct', 'arguments' => null], $read('A\Mapper::__construct'));

	foreach (['FILLED', 'Form::', 'Form::a-b', 'Form::name(', 'Form::name() ?? 1', 'Form->name', 'A\\\\B::name', 'Form::$$name'] as $key) {
		Assert::exception(fn() => MemberPattern::fromKey($key), InvalidArgumentException::class, "The member '$key' is not written as %a%");
	}

	Assert::exception(fn() => MemberPattern::fromKey('Form::$filled()'), InvalidArgumentException::class, "The member 'Form::\$filled()' is a property and takes no arguments.");
	Assert::same('addupload', MemberPattern::fromKey('Form::addUpload()')->getLookupName());
});


test('an access is of the member when its kind fits, its name agrees and every class of its receiver is the class or its subtype', function () {
	$types = createTypes();
	$matches = fn(string $key, MemberKind $kind, string $name, string ...$classes) => MemberPattern::fromKey($key)
		->matches(new Access($kind, $name, array_values($classes ?: ['App\MyLoader']), declared: true), $types);

	// a bare name is a constant or a method, a method in any letter case
	Assert::true($matches('Nette\Loaders\RobotLoader::RetryLimit', MemberKind::Constant, 'RetryLimit'));
	Assert::false($matches('Nette\Loaders\RobotLoader::RetryLimit', MemberKind::Constant, 'RETRYLIMIT'));
	Assert::true($matches('Nette\Loaders\RobotLoader::ALLOW', MemberKind::Constant, 'ALLOW'));
	Assert::false($matches('Nette\Loaders\RobotLoader::ALLOW', MemberKind::Method, 'allow')); // a name in capitals is a constant
	Assert::true($matches('Nette\Loaders\RobotLoader::getCacheKey', MemberKind::Method, 'GETCACHEKEY'));
	Assert::true($matches('Nette\Loaders\RobotLoader::getCacheKey', MemberKind::StaticMethod, 'getCacheKey'));
	Assert::false($matches('Nette\Loaders\RobotLoader::getCacheKey', MemberKind::Property, 'getCacheKey'));

	// parentheses say a method, a dollar a property, whether static or not
	Assert::false($matches('Nette\Loaders\RobotLoader::RetryLimit()', MemberKind::Constant, 'RetryLimit'));
	Assert::true($matches('Nette\Loaders\RobotLoader::getCacheKey($part)', MemberKind::StaticMethod, 'getCacheKey'));
	Assert::true($matches('Nette\Loaders\RobotLoader::$count', MemberKind::StaticProperty, 'count'));
	Assert::true($matches('Nette\Loaders\RobotLoader::$count', MemberKind::Property, 'count'));
	Assert::false($matches('Nette\Loaders\RobotLoader::$count', MemberKind::Property, 'Count'));
	Assert::false($matches('Nette\Loaders\RobotLoader::$count', MemberKind::Constant, 'count'));

	// a property the class does not declare is magic, and one a child declares under its name is another one
	$property = fn(bool $declared) => MemberPattern::fromKey('Nette\Loaders\RobotLoader::$magic')
		->matches(new Access(MemberKind::Property, 'magic', ['App\MyLoader'], declared: $declared), $types);
	Assert::true($property(false));
	Assert::false($property(true));

	// an instantiation is no call of parent::__construct(), and one of a child creates another class
	Assert::true($matches('Nette\Loaders\RobotLoader::__construct($dir)', MemberKind::Constructor, '__construct', 'Nette\Loaders\RobotLoader'));
	Assert::false($matches('Nette\Loaders\RobotLoader::__construct($dir)', MemberKind::Constructor, '__construct'));
	Assert::false($matches('Nette\Loaders\RobotLoader::__construct($dir)', MemberKind::StaticMethod, '__construct'));
	Assert::false($matches('Nette\Loaders\RobotLoader::getCacheKey', MemberKind::Constructor, '__construct'));

	// the receiver
	Assert::true($matches('nette\loaders\LOADER::getCacheKey', MemberKind::Method, 'getCacheKey'));
	Assert::true($matches('Nette\Loaders\RobotLoader::getCacheKey', MemberKind::Method, 'getCacheKey', 'Nette\Loaders\RobotLoader', 'Nette\Loaders\OldLoader'));
	Assert::false($matches('Nette\Loaders\RobotLoader::getCacheKey', MemberKind::Method, 'getCacheKey', 'App\MyLoader', 'Nette\Loaders\Unrelated'));
	Assert::false($matches('App\MyLoader::getCacheKey', MemberKind::Method, 'getCacheKey', 'Nette\Loaders\RobotLoader'));
	Assert::true($matches('Nette\Removed::run', MemberKind::Method, 'run', 'Nette\Removed'));
	Assert::true($matches('Nette\Loaders\Caching::run', MemberKind::Method, 'run')); // a trait of the parent
});


test('a method declaration is of the member when a subtype of its class declares it', function () {
	$types = createTypes();
	$matches = fn(string $key, string $class, string $method) => MemberPattern::fromKey($key)->matchesDeclaration($class, $method, $types);

	Assert::true($matches('Nette\Loaders\RobotLoader::getCacheKey', 'App\MyLoader', 'getcachekey'));
	Assert::true($matches('Nette\Loaders\Loader::getCacheKey($part)', 'Nette\Loaders\OldLoader', 'getCacheKey'));
	Assert::false($matches('Nette\Loaders\RobotLoader::getCacheKey', 'App\MyLoader', 'getCacheKeys'));
	Assert::false($matches('Nette\Loaders\RobotLoader::getCacheKey', 'Nette\Loaders\Unrelated', 'getCacheKey'));

	// the class of the member declares the member itself, which overrides nothing
	Assert::false($matches('Nette\Loaders\RobotLoader::getCacheKey', 'nette\loaders\robotloader', 'getCacheKey'));

	Assert::false($matches('Nette\Loaders\RobotLoader::$getCacheKey', 'App\MyLoader', 'getCacheKey'));
	Assert::false($matches('Nette\Loaders\RobotLoader::__construct', 'App\MyLoader', '__construct'));
});
