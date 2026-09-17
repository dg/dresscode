<?php declare(strict_types=1);

use DressCode\Analyses;
use DressCode\Analyses\{Deprecation, MemberKind};
use PHPStan\Type\VerbosityLevel;
use PhpSyntax\Nodes\Expression\{ClassConstantFetchNode, MethodCallNode, StaticMethodCallNode, VariableNode};
use PhpSyntax\Nodes\{ExpressionNode, FileNode};
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use PhpSyntax\{Parser, Printer};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** The analysis over the code, with the declarations of fixtures/types/stubs; PHPStan reads the file from the disk. */
function analyse(FileNode $file): Analyses\Types
{
	$dir = sys_get_temp_dir() . '/dresscode-tests/types';
	@mkdir($dir, recursive: true);
	$code = Printer::print($file);
	$path = $dir . '/' . hash('xxh128', $code) . '.php';
	file_put_contents($path, $code);
	$stubs = __DIR__ . '/fixtures/types/stubs';
	return new Analyses\Types($file, $path, new Analyses\PhpStan($stubs, [$stubs, $path], "$dir/cache"));
}


test('the type of an expression, the callee an access or a call reaches, and what its declaration says', function () {
	$file = (new Parser)->parse(<<<'PHP'
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
	$types = analyse($file);

	[$selfConstant, $orderConstant, $myConstant, $unknownConstant, $dynamicName] = $file->find(ClassConstantFetchNode::class);
	[$thisCall, $nullsafeCall] = $file->find(MethodCallNode::class);
	[$staticCall] = $file->find(StaticMethodCallNode::class);
	$calleeOf = fn(ExpressionNode $node) => $types->findCallee($node) ?? throw new LogicException('No callee.');

	$callee = $calleeOf($thisCall);
	Assert::same([MemberKind::Method, 'recalc', 'Acme\Shop\Order'], [$callee->kind, $callee->name, $callee->declaringClass]);
	Assert::same('Method Acme\Shop\Order::recalc()', $callee->describe());
	Assert::equal(new Deprecation('use recalculate()', null, 'recalculate', replacementIsCall: true), $types->getDeprecation($callee));

	// the constant is decided by the class that declares it, whatever reaches it
	foreach ([$selfConstant, $orderConstant] as $access) {
		$callee = $calleeOf($access);
		Assert::same([MemberKind::Constant, 'STATUS_PAID', 'Acme\Shop\Order'], [$callee->kind, $callee->name, $callee->declaringClass]);
		Assert::equal(new Deprecation('use Order::StatusPaid', 'Order', 'StatusPaid'), $types->getDeprecation($callee));
	}

	$callee = $calleeOf($myConstant);
	Assert::same(['StatusPaid', 'Acme\Shop\Order'], [$callee->name, $callee->declaringClass]);
	Assert::null($types->getDeprecation($callee));
	Assert::same([MemberKind::Method, 'recalculate'], [$calleeOf($nullsafeCall)->kind, $calleeOf($nullsafeCall)->name]);
	Assert::same([MemberKind::StaticMethod, 'make'], [$calleeOf($staticCall)->kind, $calleeOf($staticCall)->name]);

	// no member without a known class, or with a name that is an expression
	Assert::null($types->findCallee($unknownConstant));
	Assert::null($types->findCallee($dynamicName));
	Assert::null($types->findCallee($file->find(ExpressionStatementNode::class)[0]));

	$describe = fn(?PHPStan\Type\Type $type) => $type?->describe(VerbosityLevel::precise());
	// a parameter is no expression PHPStan visits; the variable read in the body is
	[, $order] = array_values(array_filter($file->find(VariableNode::class), fn(VariableNode $node) => $node->getFirstToken()?->text === '$order'));
	Assert::same('Acme\Shop\Order', $describe($types->getType($order)));
	$sum = $file->find(PhpSyntax\Nodes\Expression\BinaryOpNode::class)[0];
	Assert::same('2', $describe($types->getType($sum)));
	Assert::same("'paid'", $describe($types->getType($myConstant)));

	// a node inserted after the pass began has no type
	$inserted = (new Parser)->parseExpression('$order');
	$sum->left->replaceWith($inserted);
	Assert::null($types->getType($inserted));
	Assert::same('2', $describe($types->getType($sum)));

	Assert::exception(fn() => $types->getDeprecation(new Analyses\Callee(MemberKind::Constant, 'X', 'Y')), InvalidArgumentException::class);

	// the declared spelling of a class the project or PHP declares, whatever the letter case of the question
	Assert::same('Acme\Shop\Order', $types->findClassName('acme\shop\ORDER'));
	Assert::same('DateTimeImmutable', $types->findClassName('datetimeimmutable'));
	Assert::null($types->findClassName('Acme\Shop\Missing'));
});


test('the access a node makes is decided by the receiver, whether or not anything declares the member', function () {
	$file = (new Parser)->parse(<<<'PHP'
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
	$types = analyse($file);
	[$removed, $overridden, $ofUnion, $ofAlike, $onNumber, $onUnknown, $dynamicName, $callable] = $file->find(MethodCallNode::class);
	[$constant] = $file->find(ClassConstantFetchNode::class);
	[$staticProperty] = $file->find(PhpSyntax\Nodes\Expression\StaticPropertyFetchNode::class);
	[$parentCall] = $file->find(StaticMethodCallNode::class);
	[$property, $magicProperty] = $file->find(PhpSyntax\Nodes\Expression\PropertyFetchNode::class);
	[$new, $newDynamic, $newAnonymous] = $file->find(PhpSyntax\Nodes\Expression\NewNode::class);
	$accessOf = function (ExpressionNode $node) use ($types): ?array {
		$access = $types->findAccess($node);
		return $access === null ? null : [$access->kind, $access->name, $access->classes, $access->declared];
	};

	// a member no class declares any more has no callee, and an access all the same
	Assert::same([MemberKind::Method, 'removedMethod', ['Acme\Cache\FileStorage'], false], $accessOf($removed));
	Assert::null($types->findCallee($removed));

	// a member the child overrides is an access of the child, which the map of the parent is asked about
	Assert::same([MemberKind::Method, 'getCacheKey', ['App\MyStorage'], true], $accessOf($overridden));
	Assert::same('App\MyStorage', $types->findCallee($overridden)?->declaringClass);

	Assert::same([MemberKind::Constant, 'RetryLimit', ['App\MyStorage'], true], $accessOf($constant));
	Assert::same([MemberKind::StaticProperty, 'count', ['App\MyStorage'], true], $accessOf($staticProperty));
	Assert::same([MemberKind::StaticMethod, 'getCacheKey', ['Acme\Cache\FileStorage'], true], $accessOf($parentCall));
	Assert::same([MemberKind::Property, 'tempDirectory', ['Acme\Cache\FileStorage'], true], $accessOf($property));
	Assert::same([MemberKind::Property, 'magic', ['Acme\Cache\FileStorage'], false], $accessOf($magicProperty));
	Assert::same([MemberKind::Constructor, '__construct', ['App\MyStorage'], true], $accessOf($new));
	Assert::same('Constructor Acme\Cache\FileStorage::__construct()', $types->findCallee($new)?->describe());

	// the constructor runs as the class that declares it, which a child without its own does not
	$constructor = $types->findConstructorAccess($new);
	Assert::same([MemberKind::Constructor, '__construct', ['Acme\Cache\FileStorage'], true], $constructor === null ? null : [$constructor->kind, $constructor->name, $constructor->classes, $constructor->declared]);
	Assert::null($types->findConstructorAccess($parentCall)); // parent::getCacheKey() is no constructor

	// a first-class callable reaches the member as a call does
	Assert::same([MemberKind::Method, 'getCacheKey', ['App\MyStorage'], true], $accessOf($callable));
	Assert::same('App\MyStorage', $types->findCallee($callable)?->declaringClass);

	// every class of a union is there for the asker to refuse the one it does not know
	Assert::same([MemberKind::Method, 'getCacheKey', ['Acme\Cache\FileStorage', 'Acme\Cache\Unrelated'], true], $accessOf($ofUnion));

	// no access without an object to receive it, with a name that is an expression, or of a class that has no name
	foreach ([$onNumber, $onUnknown, $dynamicName, $newDynamic, $newAnonymous] as $node) {
		Assert::null($types->findAccess($node));
	}

	$parametersOf = function (ExpressionNode $node) use ($types): ?array {
		$parameters = $types->findParameters($types->findAccess($node) ?? throw new LogicException('No access.'));
		return $parameters === null ? null : array_map(fn(Analyses\Parameter $parameter) => get_object_vars($parameter), $parameters);
	};
	Assert::same([
		['name' => 'tempDirectory', 'type' => 'string|null', 'variadic' => false, 'byReference' => false, 'optional' => true],
		['name' => 'autoRebuild', 'type' => 'bool', 'variadic' => false, 'byReference' => false, 'optional' => true],
	], $parametersOf($new));
	Assert::same([['name' => 'parts', 'type' => 'string', 'variadic' => true, 'byReference' => false, 'optional' => true]], $parametersOf($overridden));
	Assert::null($parametersOf($removed)); // nothing declares it
	Assert::null($parametersOf($ofUnion)); // the classes of the union declare it differently
	Assert::same(['App\MyStorage', 'Acme\Cache\OldStorage'], $types->findAccess($ofAlike)?->classes);
	Assert::same($parametersOf($overridden), $parametersOf($ofAlike)); // and these two alike
	Assert::null($parametersOf($constant));

	// a parameter is no expression PHPStan visits; the variable read in the body is
	[, $union] = array_values(array_filter($file->find(VariableNode::class), fn(VariableNode $node) => $node->getFirstToken()?->text === '$union'));
	Assert::same(['Acme\Cache\FileStorage', 'Acme\Cache\Unrelated'], $types->findClasses($union));
	[, $number] = array_values(array_filter($file->find(VariableNode::class), fn(VariableNode $node) => $node->getFirstToken()?->text === '$number'));
	Assert::same([], $types->findClasses($number));

	Assert::true($types->isSubtype('App\MyStorage', 'acme\cache\FILESTORAGE'));
	Assert::true($types->isSubtype('App\MyStorage', 'Acme\Cache\Storage'));
	Assert::true($types->isSubtype('Acme\Cache\FileStorage', 'Acme\Cache\FileStorage'));
	Assert::false($types->isSubtype('Acme\Cache\FileStorage', 'App\MyStorage'));
	Assert::false($types->isSubtype('Acme\Cache\Unrelated', 'Acme\Cache\FileStorage'));
	Assert::true($types->isSubtype('Acme\Removed', 'acme\removed'));
	Assert::false($types->isSubtype('App\MyStorage', 'Acme\Removed'));
	Assert::true($types->isSubtype('App\MyStorage', 'acme\cache\caching')); // the trait of the parent
	Assert::false($types->isSubtype('Acme\Cache\Unrelated', 'Acme\Cache\Caching'));

	Assert::true($types->hasProperty('App\MyStorage', 'tempDirectory'));
	Assert::false($types->hasProperty('App\MyStorage', 'magic'));
	Assert::false($types->hasProperty('Acme\Removed', 'any'));

	Assert::false($types->isStaticMethod('App\MyStorage', 'GETCACHEKEY'));
	Assert::true($types->isStaticMethod('Acme\Cache\FileStorage', 'create'));
	Assert::null($types->isStaticMethod('Acme\Cache\FileStorage', 'removedMethod'));
	Assert::null($types->isStaticMethod('Acme\Removed', 'run'));

	[$override, $anonymous] = $file->find(PhpSyntax\Nodes\Member\MethodNode::class);
	Assert::same('App\MyStorage', $types->findDeclaringClass($override));
	Assert::true($types->isSubtype((string) $types->findDeclaringClass($anonymous), 'Acme\Cache\Storage'));
	Assert::null($types->findDeclaringClass($file));

	Assert::equal(new Deprecation('use Acme\Cache\FileStorage', 'Acme\Cache\FileStorage'), $types->getClassDeprecation('acme\cache\oldstorage'));
	Assert::null($types->getClassDeprecation('Acme\Cache\FileStorage'));
	Assert::null($types->getClassDeprecation('Acme\Removed'));
});


test('a declaration with the signature of the parent declaration', function () {
	$file = (new Parser)->parse(<<<'PHP'
		<?php
		namespace App;

		class Base
		{
			public function same(int $a, string ...$rest): string { return ''; }
			protected function visibility($a) {}
			public function type(int $a) {}
			public function name(int $a) {}
			public function defaultValue(int $a = 1) {}
			public function reference(array &$a) {}
			public function returnType(): string { return ''; }
			public static function staticness() {}
			private function hidden() {}
			public function self(): self { return $this; }
		}

		class Child extends Base
		{
			public function same(int $a, string ...$rest): string { return ''; }
			public function visibility($a) {}
			public function type(string $a) {}
			public function name(int $b) {}
			public function defaultValue(int $a = 2) {}
			public function reference(array $a) {}
			public function returnType(): ?string { return ''; }
			public function staticness() {}
			public function hidden() {}
			public function self(): self { return $this; }
			public function own() {}
		}
		PHP);
	$types = analyse($file);
	$methods = [];
	foreach ($file->find(PhpSyntax\Nodes\Member\MethodNode::class) as $method) {
		if ($method->findAncestor(PhpSyntax\Nodes\Statement\ClassNode::class)?->name->text === 'Child') {
			$methods[$method->name->text] = $types->hasParentSignature($method);
		}
	}

	Assert::same([
		'same' => true,
		'visibility' => false,
		'type' => false,
		'name' => false,
		'defaultValue' => false,
		'reference' => false,
		'returnType' => false,
		'staticness' => false,
		'hidden' => false,
		'self' => false,
		'own' => false,
	], $methods);
});


test('an overriding declaration is read from the text of the pass, while the disk still has the text of the first one', function () {
	$code = "<?php\nnamespace App;\n\nabstract class Base\n{\n\tabstract protected function run(string \$name): int;\n}\n\nclass Child extends Base\n{\n\tprotected function %s\n\t{\n\t\treturn 1;\n\t}\n}\n";
	$dir = sys_get_temp_dir() . '/dresscode-tests/types';
	@mkdir($dir, recursive: true);
	$path = $dir . '/stale-' . getmypid() . '.php';
	file_put_contents($path, sprintf($code, 'run(int $name)'));
	$phpstan = new Analyses\PhpStan($dir, [$path], "$dir/cache");

	$signatureOf = function (string $declaration) use ($code, $path, $phpstan): Analyses\Signature {
		$file = (new Parser)->parse(sprintf($code, $declaration));
		$method = $file->find(PhpSyntax\Nodes\Member\MethodNode::class)[1];
		return new Analyses\Types($file, $path, $phpstan)->findOverriddenSignature($method) ?? throw new LogicException('No signature.');
	};

	$first = $signatureOf('run(int $name)');
	Assert::true($first->returnWidened);
	Assert::true($first->parameters[0]->narrowed);

	$fixed = $signatureOf('run(string $name): int');
	Assert::false($fixed->returnWidened);
	Assert::false($fixed->parameters[0]->narrowed);
	@unlink($path);
});


test('a deprecation names its replacement in a shape a tool can read, or it does not', function () {
	Assert::equal(new Deprecation('use Order::StatusPaid', 'Order', 'StatusPaid'), Deprecation::fromDescription('use Order::StatusPaid'));
	Assert::equal(new Deprecation('use \Acme\Shop\Order::StatusPaid instead.', 'Acme\Shop\Order', 'StatusPaid'), Deprecation::fromDescription('use \Acme\Shop\Order::StatusPaid instead.'));
	Assert::equal(new Deprecation('use recalculate()', null, 'recalculate', true), Deprecation::fromDescription('use recalculate()'));
	Assert::equal(new Deprecation('Use $items', null, '$items'), Deprecation::fromDescription('Use $items'));
	Assert::equal(new Deprecation('use something else'), Deprecation::fromDescription('use something else'));
	Assert::equal(new Deprecation('since 3.2'), Deprecation::fromDescription('since 3.2'));
	Assert::equal(new Deprecation(''), Deprecation::fromDescription(''));

	// the words around the code
	$codes = [
		'since Mailer 6.4, use "enableCompression()" instead.' => 'enableCompression()',
		'since acme/mailer 5.3, use DEFAULT_PORT instead.' => 'DEFAULT_PORT',
		'since Mailer 8.1; use Acme\Mail\Transport instead' => 'Acme\Mail\Transport',
		'since Mailer 6.1, to be removed in 7.0, use {@link SmtpTransport} instead' => 'SmtpTransport',
		'use const RETRY_LIMIT instead' => 'RETRY_LIMIT',
		'use protected const RETRY_LIMIT instead' => 'RETRY_LIMIT',
		'use the {@see QueuedMessage} instead' => 'QueuedMessage',
		'use {@see self::getRecipients()} instead' => 'self::getRecipients()',
		'use `getHeaders()` instead' => 'getHeaders()',
		'use "Acme\Mail\Transport\SendmailTransport" instead' => 'Acme\Mail\Transport\SendmailTransport',
		'use the Foo class instead' => 'Foo',
		'use ->send() instead' => 'send()',
		'use the Queued attribute instead' => null,
		'use the #[Queued] attribute instead' => null,
		"use Mailer's TransportFactory instead" => null,
		'use a middleware instead.' => null,
		'since Mailer 7.3, to be removed in 8.0' => null,
	];
	foreach ($codes as $description => $code) {
		Assert::same($code, Deprecation::findReplacementCode($description), $description);
	}

	Assert::equal(new Deprecation('use {@see self::getRecipients()} instead', null, 'getRecipients', true), Deprecation::fromDescription('use {@see self::getRecipients()} instead'));
	Assert::equal(new Deprecation('use ->send() instead', null, 'send', true), Deprecation::fromDescription('use ->send() instead'));
});
