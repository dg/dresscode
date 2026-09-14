<?php declare(strict_types=1);

use DressCode\Analyses;
use DressCode\Analyses\{Access, MemberKind};
use DressCode\Rules\Upgrading\MemberPattern;
use PhpSyntax\Parser;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** The types of a file that declares App\MyStorage, a child of Acme\Cache\FileStorage from the stubs of the analysis. */
function createTypes(): Analyses\Types
{
	$dir = sys_get_temp_dir() . '/dresscode-tests/member-pattern';
	@mkdir($dir, recursive: true);
	$code = "<?php\nnamespace App;\nclass MyStorage extends \\Acme\\Cache\\FileStorage {}\n";
	$path = "$dir/MyStorage.php";
	file_put_contents($path, $code);
	$stubs = __DIR__ . '/../Analyses/fixtures/types/stubs';
	return new Analyses\Types((new Parser)->parse($code), $path, new Analyses\PhpStan($stubs, [$stubs, $path], "$dir/cache"));
}


test('a key is read the way an upgrading guide writes a member', function () {
	$read = function (string $key): array {
		$pattern = MemberPattern::fromKey($key);
		$vars = array_replace(get_object_vars($pattern), ['arguments' => $pattern->arguments === null ? null : count($pattern->arguments->items)]);
		unset($vars['instance'], $vars['hook']);
		return $vars;
	};

	Assert::same(['class' => 'Acme\Shop\Order', 'kind' => null, 'name' => 'STATUS_PAID', 'arguments' => null], $read('Acme\Shop\Order::STATUS_PAID'));
	Assert::same(['class' => 'Acme\Shop\Order', 'kind' => MemberKind::Method, 'name' => 'size', 'arguments' => 0], $read('\Acme\Shop\Order::size()'));
	Assert::same(['class' => 'Acme\Shop\Order', 'kind' => MemberKind::Method, 'name' => 'size', 'arguments' => 1], $read('Acme\Shop\Order::size(...$args)'));
	Assert::same(['class' => 'Acme\Shop\Order', 'kind' => MemberKind::Property, 'name' => 'paid', 'arguments' => null], $read(' Acme\Shop\Order::$paid '));
	Assert::same(['class' => 'dibi', 'kind' => MemberKind::Method, 'name' => 'addUpload', 'arguments' => 3], $read('dibi::addUpload( $name, $label, true )'));
	Assert::same(['class' => 'A\Mapper', 'kind' => MemberKind::Constructor, 'name' => '__construct', 'arguments' => 2], $read('A\Mapper::__CONSTRUCT($iterator, (1))'));
	Assert::same(['class' => 'A\Mapper', 'kind' => MemberKind::Constructor, 'name' => '__construct', 'arguments' => null], $read('A\Mapper::__construct'));

	foreach (['STATUS_PAID', 'Order::', 'Order::a-b', 'Order::name(', 'Order::name() ?? 1', 'Order.name', 'A\\\\B::name', 'Order::$$name'] as $key) {
		Assert::exception(fn() => MemberPattern::fromKey($key), InvalidArgumentException::class, "The member '$key' is not written as %a%");
	}

	Assert::exception(fn() => MemberPattern::fromKey('Order::$paid()'), InvalidArgumentException::class, "The member 'Order::\$paid()' is a property and takes no arguments.");
	Assert::exception(fn() => MemberPattern::fromKey('Order::add( ... )'), InvalidArgumentException::class, "The member 'Order::add( ... )' reads as a first-class callable; a call with any arguments is written Order::add(...\$args).");
	Assert::true(MemberPattern::fromKey('Acme\Utils\Html->text()')->instance);
	Assert::same('set', MemberPattern::fromKey('Acme\Shop\Order::$paid::set')->hook);
	Assert::true(MemberPattern::fromKey('Order::$paid::get')->matchesHook('isset'));
	Assert::false(MemberPattern::fromKey('Order::$paid::get')->matchesHook('set'));
	Assert::true(MemberPattern::fromKey('Order::$paid::set')->matchesHook('unset'));
	Assert::true(MemberPattern::fromKey('Order::$paid')->matchesHook('set'));
	Assert::exception(fn() => MemberPattern::fromKey('Order::paid::get'), InvalidArgumentException::class, "The member 'Order::paid::get' names a hook, which only a property has, Class::\$name::get.");
	foreach (['Html->text', 'Html->$text', 'Html->__construct()'] as $key) {
		Assert::exception(fn() => MemberPattern::fromKey($key), InvalidArgumentException::class, "The member '$key' is %a%");
	}
	Assert::exception(fn() => MemberPattern::fromKey('Order::add($name, run())'), InvalidArgumentException::class, "The member 'Order::add(\$name, run())' cannot be read: 'run()' is no placeholder, %a%");
	Assert::same('addupload', MemberPattern::fromKey('Order::addUpload()')->getLookupName());

	// a method is replaced as a whole by a key that takes any arguments
	Assert::true(MemberPattern::fromKey('Order::add')->takesAnyArguments());
	Assert::true(MemberPattern::fromKey('Order::add(...$args)')->takesAnyArguments());
	Assert::false(MemberPattern::fromKey('Order::add()')->takesAnyArguments());
	Assert::false(MemberPattern::fromKey('Order::add($name, ...)')->takesAnyArguments());
});


test('an access is of the member when its kind fits, its name agrees and every class of its receiver is the class or its subtype', function () {
	$types = createTypes();
	$matches = fn(string $key, MemberKind $kind, string $name, string ...$classes) => MemberPattern::fromKey($key)
		->matches(new Access($kind, $name, array_values($classes ?: ['App\MyStorage']), declared: true), $types);

	// a bare name is a constant or a method, a method in any letter case
	Assert::true($matches('Acme\Cache\FileStorage::RetryLimit', MemberKind::Constant, 'RetryLimit'));
	Assert::false($matches('Acme\Cache\FileStorage::RetryLimit', MemberKind::Constant, 'RETRYLIMIT'));
	Assert::true($matches('Acme\Cache\FileStorage::ALLOW', MemberKind::Constant, 'ALLOW'));
	Assert::false($matches('Acme\Cache\FileStorage::ALLOW', MemberKind::Method, 'allow')); // a name in capitals is a constant
	Assert::true($matches('Acme\Cache\FileStorage::getCacheKey', MemberKind::Method, 'GETCACHEKEY'));
	Assert::true($matches('Acme\Cache\FileStorage::getCacheKey', MemberKind::StaticMethod, 'getCacheKey'));
	Assert::false($matches('Acme\Cache\FileStorage::getCacheKey', MemberKind::Property, 'getCacheKey'));

	// parentheses say a method, a dollar a property, whether static or not
	Assert::false($matches('Acme\Cache\FileStorage::RetryLimit()', MemberKind::Constant, 'RetryLimit'));
	Assert::true($matches('Acme\Cache\FileStorage::getCacheKey($part)', MemberKind::StaticMethod, 'getCacheKey'));
	Assert::true($matches('Acme\Cache\FileStorage::$count', MemberKind::StaticProperty, 'count'));
	Assert::true($matches('Acme\Cache\FileStorage::$count', MemberKind::Property, 'count'));
	Assert::false($matches('Acme\Cache\FileStorage::$count', MemberKind::Property, 'Count'));
	Assert::false($matches('Acme\Cache\FileStorage::$count', MemberKind::Constant, 'count'));

	// a property the class does not declare is magic, and one a child declares under its name is another one
	$property = fn(bool $declared) => MemberPattern::fromKey('Acme\Cache\FileStorage::$magic')
		->matches(new Access(MemberKind::Property, 'magic', ['App\MyStorage'], declared: $declared), $types);
	Assert::true($property(false));
	Assert::false($property(true));

	// an instantiation is no call of parent::__construct(), and one of a child creates another class
	Assert::true($matches('Acme\Cache\FileStorage::__construct($dir)', MemberKind::Constructor, '__construct', 'Acme\Cache\FileStorage'));
	Assert::false($matches('Acme\Cache\FileStorage::__construct($dir)', MemberKind::Constructor, '__construct'));
	Assert::false($matches('Acme\Cache\FileStorage::__construct($dir)', MemberKind::StaticMethod, '__construct'));
	Assert::false($matches('Acme\Cache\FileStorage::getCacheKey', MemberKind::Constructor, '__construct'));

	// the receiver
	Assert::true($matches('acme\cache\STORAGE::getCacheKey', MemberKind::Method, 'getCacheKey'));
	Assert::true($matches('Acme\Cache\FileStorage::getCacheKey', MemberKind::Method, 'getCacheKey', 'Acme\Cache\FileStorage', 'Acme\Cache\OldStorage'));
	Assert::false($matches('Acme\Cache\FileStorage::getCacheKey', MemberKind::Method, 'getCacheKey', 'App\MyStorage', 'Acme\Cache\Unrelated'));
	Assert::false($matches('App\MyStorage::getCacheKey', MemberKind::Method, 'getCacheKey', 'Acme\Cache\FileStorage'));
	Assert::true($matches('Acme\Removed::run', MemberKind::Method, 'run', 'Acme\Removed'));
	Assert::true($matches('Acme\Cache\Caching::run', MemberKind::Method, 'run')); // a trait of the parent

	// a method that is not static is called with :: only where the class has it and not static, parent::name()
	Assert::true($matches('Acme\Cache\FileStorage->create()', MemberKind::Method, 'create'));
	Assert::false($matches('Acme\Cache\FileStorage->create()', MemberKind::StaticMethod, 'create'));
	Assert::true($matches('Acme\Cache\FileStorage->getCacheKey()', MemberKind::StaticMethod, 'getCacheKey'));
	Assert::false($matches('Acme\Cache\FileStorage->removed()', MemberKind::StaticMethod, 'removed'));
	Assert::true($matches('Acme\Cache\FileStorage::create()', MemberKind::StaticMethod, 'create'));
});


test('a method declaration is of the member when a subtype of its class declares it', function () {
	$types = createTypes();
	$matches = fn(string $key, string $class, string $method) => MemberPattern::fromKey($key)->matchesDeclaration($class, $method, $types);

	Assert::true($matches('Acme\Cache\FileStorage::getCacheKey', 'App\MyStorage', 'getcachekey'));
	Assert::true($matches('Acme\Cache\Storage::getCacheKey($part)', 'Acme\Cache\OldStorage', 'getCacheKey'));
	Assert::false($matches('Acme\Cache\FileStorage::getCacheKey', 'App\MyStorage', 'getCacheKeys'));
	Assert::false($matches('Acme\Cache\FileStorage::getCacheKey', 'Acme\Cache\Unrelated', 'getCacheKey'));

	// the class of the member declares the member itself, which overrides nothing
	Assert::false($matches('Acme\Cache\FileStorage::getCacheKey', 'acme\cache\filestorage', 'getCacheKey'));

	Assert::false($matches('Acme\Cache\FileStorage::$getCacheKey', 'App\MyStorage', 'getCacheKey'));
	Assert::false($matches('Acme\Cache\FileStorage::__construct', 'App\MyStorage', '__construct'));
});
