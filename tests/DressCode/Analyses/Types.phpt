<?php declare(strict_types=1);

use DressCode\{Analyses, Tristate};
use DressCode\Analyses\MemberKind;
use PhpSyntax\{Builder, Parser, Printer};
use PhpSyntax\Nodes\Expression\{ClassConstantFetchNode, MethodCallNode, StaticMethodCallNode, VariableNode};
use PhpSyntax\Nodes\{ExpressionNode, FileNode};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** The analysis over the code, with the declarations of fixtures/types/stubs; PHPStan reads the file from the disk. */
function analyse(FileNode $file): Analyses\Types
{
	static $dir;
	$dir ??= createTempDir('types');
	$code = Printer::print($file);
	$path = $dir . '/' . hash('xxh128', $code) . '.php';
	file_put_contents($path, $code);
	$stubs = __DIR__ . '/fixtures/types/stubs';
	return new Analyses\Types($file, $path, new Analyses\PhpStan($stubs, [$stubs, $path], "$dir/cache"));
}


/** Calls and constant accesses over `Acme\Shop\Order` of the stubs, which deprecates a method and a constant. */
function orderSample(): FileNode
{
	return (new Parser)->parse(<<<'PHP'
		<?php
		namespace App;

		use Acme\Shop\Order;

		class MyOrder extends Order
		{
			public function check(): void
			{
				$this->recalc();
				$x = self::STATUS_PAID;
			}
		}

		function test(Order $order, MyOrder $my, $unknown): void
		{
			$a = $order::STATUS_PAID;
			$b = MyOrder::StatusPaid;
			$order?->recalculate();
			Order::make();
			$unknown::STATUS_PAID;
			$order::{'STATUS_PAID'};
			[1, 2][0] + strlen('a');
		}
		PHP);
}


test('whether an expression is of a type, and maybe for a node the pass began without', function () {
	$file = orderSample();
	$types = analyse($file);

	// a parameter is no expression PHPStan visits; the variable read in the body is
	[, $order] = array_values(array_filter($file->find(VariableNode::class), fn(VariableNode $node) => $node->getFirstToken()->text === '$order'));
	Assert::same(Tristate::Yes, $types->isOfType($order, 'Acme\Shop\Order'));
	Assert::same(Tristate::No, $types->isOfType($order, 'string'));
	$sum = $file->find(PhpSyntax\Nodes\Expression\BinaryOpNode::class)[0];
	Assert::same(Tristate::Yes, $types->isOfType($sum, '2'));
	[, , $myConstant] = $file->find(ClassConstantFetchNode::class);
	Assert::same(Tristate::Yes, $types->isOfType($myConstant, "'paid'"));
	Assert::same(Tristate::Yes, $types->isOfType($myConstant, 'non-empty-string'));

	// a node inserted after the pass began has no type
	$inserted = (new Builder)->expression('$order');
	$sum->left->replaceWith($inserted);
	Assert::same(Tristate::Maybe, $types->isOfType($inserted, 'Acme\Shop\Order'));
	Assert::same(Tristate::Yes, $types->isOfType($sum, '2'));
});


test('the member a call or a constant access reaches', function () {
	$file = orderSample();
	$types = analyse($file);

	[$selfConstant, $orderConstant, $myConstant, $unknownConstant, $dynamicName] = $file->find(ClassConstantFetchNode::class);
	[$thisCall, $nullsafeCall] = $file->find(MethodCallNode::class);
	[$staticCall] = $file->find(StaticMethodCallNode::class);
	$memberOf = fn(ExpressionNode $node) => $types->findMember($node) ?? throw new LogicException('No member.');

	$member = $memberOf($thisCall);
	Assert::same([MemberKind::Method, 'recalc', 'Acme\Shop\Order'], [$member->kind, $member->name, $member->declaringClass]);
	Assert::same('Method `Acme\Shop\Order::recalc()`', $member->describe());

	// the constant is decided by the class that declares it, whatever reaches it
	foreach ([$selfConstant, $orderConstant] as $access) {
		$member = $memberOf($access);
		Assert::same([MemberKind::Constant, 'STATUS_PAID', 'Acme\Shop\Order'], [$member->kind, $member->name, $member->declaringClass]);
	}

	$member = $memberOf($myConstant);
	Assert::same(['StatusPaid', 'Acme\Shop\Order'], [$member->name, $member->declaringClass]);
	Assert::same([MemberKind::Method, 'recalculate'], [$memberOf($nullsafeCall)->kind, $memberOf($nullsafeCall)->name]);
	Assert::same([MemberKind::StaticMethod, 'make'], [$memberOf($staticCall)->kind, $memberOf($staticCall)->name]);

	// no member without a known class, or with a name that is an expression
	Assert::null($types->findMember($unknownConstant));
	Assert::null($types->findMember($dynamicName));

	// the declared spelling of a class the project or PHP declares, whatever the letter case of the question
	Assert::same('Acme\Shop\Order', $types->findClassName('acme\shop\ORDER'));
	Assert::same('DateTimeImmutable', $types->findClassName('datetimeimmutable'));
	Assert::null($types->findClassName('Acme\Shop\Missing'));
});


/** Accesses over `Acme\Cache\FileStorage` of the stubs, a child of it, and classes nothing declares. */
function storageSample(): FileNode
{
	return (new Parser)->parse(<<<'PHP'
		<?php
		namespace App;

		use Acme\Cache\OldStorage;
		use Acme\Cache\FileStorage;
		use Acme\Cache\Unrelated;

		class MyStorage extends FileStorage
		{
			public function getCacheKey(string ...$parts): array
			{
				self::RetryLimit;
				static::$count;
				return parent::getCacheKey();
			}
		}

		function test(FileStorage $storage, MyStorage $my, ?FileStorage $nullable, FileStorage|Unrelated $union, MyStorage|OldStorage $alike, int $number, $unknown, string $class): void
		{
			$storage->removedMethod(1);
			$my->getCacheKey();
			$nullable?->tempDirectory;
			$union->getCacheKey();
			$alike->getCacheKey();
			$storage->magic;
			new MyStorage('temp');
			$number->getCacheKey();
			$unknown->getCacheKey();
			$storage->{'getCacheKey'}();
			$my->getCacheKey(...);
			new $class;
			new class extends FileStorage {
				public function run(): void
				{
				}
			};
		}
		PHP);
}


test('the access a node makes is decided by the receiver, whether or not anything declares the member', function () {
	$file = storageSample();
	$types = analyse($file);
	[$removed, $overridden, $ofUnion, $ofAlike, $onNumber, $onUnknown, $dynamicName, $callable] = $file->find(MethodCallNode::class);
	[$constant] = $file->find(ClassConstantFetchNode::class);
	[$staticProperty] = $file->find(PhpSyntax\Nodes\Expression\StaticPropertyFetchNode::class);
	[$parentCall] = $file->find(StaticMethodCallNode::class);
	[$property, $magicProperty] = $file->find(PhpSyntax\Nodes\Expression\PropertyFetchNode::class);
	[$new, $newDynamic, $newAnonymous] = $file->find(PhpSyntax\Nodes\Expression\NewNode::class);
	$accessOf = function (ExpressionNode $node) use ($types): ?array {
		$access = $types->findMemberAccess($node);
		return $access === null ? null : [$access->kind, $access->name, $access->classes, $access->declared];
	};

	// a member no class declares any more has no member, and an access all the same
	Assert::same([MemberKind::Method, 'removedMethod', ['Acme\Cache\FileStorage'], false], $accessOf($removed));
	Assert::null($types->findMember($removed));

	// a member the child overrides is an access of the child, which the map of the parent is asked about
	Assert::same([MemberKind::Method, 'getCacheKey', ['App\MyStorage'], true], $accessOf($overridden));
	Assert::same('App\MyStorage', $types->findMember($overridden)?->declaringClass);

	Assert::same([MemberKind::Constant, 'RetryLimit', ['App\MyStorage'], true], $accessOf($constant));
	Assert::same([MemberKind::StaticProperty, 'count', ['App\MyStorage'], true], $accessOf($staticProperty));
	Assert::same([MemberKind::StaticMethod, 'getCacheKey', ['Acme\Cache\FileStorage'], true], $accessOf($parentCall));
	Assert::same([MemberKind::Property, 'tempDirectory', ['Acme\Cache\FileStorage'], true], $accessOf($property));
	Assert::same([MemberKind::Property, 'magic', ['Acme\Cache\FileStorage'], false], $accessOf($magicProperty));
	Assert::same([MemberKind::Constructor, '__construct', ['App\MyStorage'], true], $accessOf($new));
	Assert::same('Constructor `Acme\Cache\FileStorage::__construct()`', $types->findMember($new)?->describe());

	// the constructor runs as the class that declares it, which a child without its own does not
	$constructor = $types->findConstructorAccess($new);
	Assert::same([MemberKind::Constructor, '__construct', ['Acme\Cache\FileStorage'], true], $constructor === null ? null : [$constructor->kind, $constructor->name, $constructor->classes, $constructor->declared]);
	Assert::null($types->findConstructorAccess($parentCall)); // parent::getCacheKey() is no constructor

	// a first-class callable reaches the member as a call does
	Assert::same([MemberKind::Method, 'getCacheKey', ['App\MyStorage'], true], $accessOf($callable));
	Assert::same('App\MyStorage', $types->findMember($callable)?->declaringClass);

	// every class of a union is there for the asker to refuse the one it does not know
	Assert::same([MemberKind::Method, 'getCacheKey', ['Acme\Cache\FileStorage', 'Acme\Cache\Unrelated'], true], $accessOf($ofUnion));

	// no access without an object to receive it, with a name that is an expression, or of a class that has no name
	foreach ([$onNumber, $onUnknown, $dynamicName, $newDynamic, $newAnonymous] as $node) {
		Assert::null($types->findMemberAccess($node));
	}

	$parametersOf = function (ExpressionNode $node) use ($types): ?array {
		$parameters = $types->findParameters($types->findMemberAccess($node) ?? throw new LogicException('No access.'));
		return $parameters === null ? null : array_map(fn(Analyses\Parameter $parameter) => get_object_vars($parameter), $parameters);
	};
	Assert::same([
		[
			'name' => 'tempDirectory', 'type' => 'string|null', 'optional' => true, 'variadic' => false, 'byReference' => false,
			'default' => 'null',
		],
		['name' => 'autoRebuild', 'type' => 'bool', 'optional' => true, 'variadic' => false, 'byReference' => false, 'default' => 'false'],
	], $parametersOf($new));
	Assert::same([['name' => 'parts', 'type' => 'string', 'optional' => true, 'variadic' => true, 'byReference' => false, 'default' => null]], $parametersOf($overridden));
	Assert::null($parametersOf($removed)); // nothing declares it
	Assert::null($parametersOf($ofUnion)); // the classes of the union declare it differently
	Assert::same(['App\MyStorage', 'Acme\Cache\OldStorage'], $types->findMemberAccess($ofAlike)?->classes);
	Assert::same($parametersOf($overridden), $parametersOf($ofAlike)); // and these two alike
	Assert::null($parametersOf($constant));
	Assert::same($parametersOf($new), array_map(get_object_vars(...), $types->findMethodParameters('Acme\Cache\FileStorage', '__construct') ?? []));
	Assert::null($types->findMethodParameters('Acme\Cache\FileStorage', 'noSuchMethod'));
	Assert::null($types->findMethodParameters('Acme\NoSuchClass', '__construct'));

	// a parameter is no expression PHPStan visits; the variable read in the body is
	[, $union] = array_values(array_filter($file->find(VariableNode::class), fn(VariableNode $node) => $node->getFirstToken()->text === '$union'));
	Assert::same(['Acme\Cache\FileStorage', 'Acme\Cache\Unrelated'], $types->findClasses($union));
	[, $number] = array_values(array_filter($file->find(VariableNode::class), fn(VariableNode $node) => $node->getFirstToken()->text === '$number'));
	Assert::same([], $types->findClasses($number));
});


test('what a class has, a maybe where nothing declares an ancestor of it', function () {
	$types = analyse(storageSample());

	Assert::same(Tristate::Yes, $types->isSubtype('App\MyStorage', 'acme\cache\FILESTORAGE'));
	Assert::same(Tristate::Yes, $types->isSubtype('App\MyStorage', 'Acme\Cache\Storage'));
	Assert::same(Tristate::Yes, $types->isSubtype('Acme\Cache\FileStorage', 'Acme\Cache\FileStorage'));
	Assert::same(Tristate::No, $types->isSubtype('Acme\Cache\FileStorage', 'App\MyStorage'));
	Assert::same(Tristate::No, $types->isSubtype('Acme\Cache\Unrelated', 'Acme\Cache\FileStorage'));
	Assert::same(Tristate::Yes, $types->isSubtype('Acme\Removed', 'acme\removed'));
	Assert::same(Tristate::Maybe, $types->isSubtype('App\MyStorage', 'Acme\Removed'));
	Assert::same(Tristate::Yes, $types->isSubtype('App\MyStorage', 'acme\cache\caching')); // the trait of the parent
	Assert::same(Tristate::No, $types->isSubtype('Acme\Cache\Unrelated', 'Acme\Cache\Caching'));

	Assert::true($types->hasMember('App\MyStorage', MemberKind::Property, 'tempDirectory'));
	Assert::false($types->hasMember('App\MyStorage', MemberKind::Property, 'magic'));
	Assert::false($types->hasMember('Acme\Removed', MemberKind::Property, 'any'));

	Assert::same(Tristate::Yes, $types->isInterface('Acme\Cache\Storage'));
	Assert::same(Tristate::No, $types->isInterface('Acme\Cache\FileStorage'));
	Assert::same(Tristate::Maybe, $types->isInterface('Acme\Removed'));
	Assert::same(Tristate::No, $types->isFinalClass('Acme\Cache\FileStorage'));
	Assert::same(Tristate::Maybe, $types->isFinalClass('Acme\Removed'));
	Assert::same(Tristate::Yes, $types->isAttributeClass('Attribute'));
	Assert::same(Tristate::No, $types->isAttributeClass('Acme\Cache\FileStorage'));
	Assert::same(Tristate::Maybe, $types->isAttributeClass('Acme\Removed'));

	Assert::same(Tristate::No, $types->isStaticMethod('App\MyStorage', 'GETCACHEKEY'));
	Assert::same(Tristate::Yes, $types->isStaticMethod('Acme\Cache\FileStorage', 'create'));
	Assert::same(Tristate::No, $types->isStaticMethod('Acme\Cache\FileStorage', 'removedMethod'));
	Assert::same(Tristate::Maybe, $types->isStaticMethod('Acme\Removed', 'run'));
});


test('a class whose parent the pass renamed has the hierarchy of the text of the pass, while the disk still has the old parent', function () {
	$code = "<?php\nnamespace App;\n\nclass Child extends %s\n{\n\tpublic function run(): string\n\t{\n\t\treturn \$this->greet() . get_class(new class {});\n\t}\n}\n";
	$dir = createTempDir('renamed');
	file_put_contents("$dir/Base.php", "<?php\nnamespace App;\n\nclass NewBase\n{\n\tpublic function greet(): string\n\t{\n\t\treturn '';\n\t}\n}\n");
	$path = "$dir/Child.php";
	file_put_contents($path, sprintf($code, 'OldBase'));
	$phpstan = new Analyses\PhpStan($dir, [$dir], dirname($dir) . '/cache');

	$hierarchyOf = function (string $parent) use ($code, $path, $phpstan): array {
		$file = (new Parser)->parse(sprintf($code, $parent));
		$types = new Analyses\Types($file, $path, $phpstan);
		return [$types->isSubtype('App\Child', 'App\NewBase'), $types->findMemberAccess($file->find(MethodCallNode::class)[0])?->declared];
	};

	Assert::same([Tristate::Maybe, false], $hierarchyOf('OldBase')); // a parent nothing declares hides the rest of the hierarchy
	Assert::same([Tristate::Yes, true], $hierarchyOf('NewBase'));
	Assert::same([Tristate::Maybe, false], $hierarchyOf('OldBase'));
});


test('a member the pass added is one the class has, while the disk still has the class without it', function () {
	$code = "<?php\nnamespace App;\n\nclass Widget\n{\n%s}\n";
	$dir = createTempDir('added');
	$path = "$dir/Widget.php";
	file_put_contents($path, sprintf($code, ''));
	$phpstan = new Analyses\PhpStan($dir, [$dir], dirname($dir) . '/cache');

	$has = function (string $members) use ($code, $path, $phpstan): array {
		$types = new Analyses\Types((new Parser)->parse(sprintf($code, $members)), $path, $phpstan);
		return [
			$types->hasMember('App\Widget', MemberKind::Constructor, '__construct'),
			$types->hasMember('App\Widget', MemberKind::Property, 'size'),
		];
	};

	Assert::same([false, false], $has(''));
	Assert::same([true, true], $has("\tpublic function __construct(\n\t\tprivate int \$size,\n\t) {\n\t}\n"));
});


test('the bootstrap files the configuration of PHPStan names run before the analysis, as an extension needs them', function () {
	$dir = createTempDir('bootstrap');
	file_put_contents("$dir/phpstan.neon", "parameters:\n\tbootstrapFiles:\n\t\t- bootstrap.php\n");
	file_put_contents("$dir/bootstrap.php", "<?php\ndefine('DressCodeTestBootstrap', true);\n");
	file_put_contents("$dir/Widget.php", "<?php\nnamespace App;\n\nclass Widget\n{\n}\n");
	$phpstan = new Analyses\PhpStan($dir, [$dir], dirname($dir) . '/cache');

	Assert::false(defined('DressCodeTestBootstrap'));
	$types = new Analyses\Types((new Parser)->parse((string) file_get_contents("$dir/Widget.php")), "$dir/Widget.php", $phpstan);
	Assert::same('App\Widget', $types->findClassName('app\widget'));
	Assert::true(defined('DressCodeTestBootstrap'));
});
