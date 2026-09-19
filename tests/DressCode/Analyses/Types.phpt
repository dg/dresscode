<?php declare(strict_types=1);

use DressCode\Analyses;
use DressCode\Analyses\Deprecation;
use DressCode\Analyses\MemberKind;
use PHPStan\Type\VerbosityLevel;
use PhpSyntax\Nodes\Expression\ClassConstantFetchNode;
use PhpSyntax\Nodes\Expression\MethodCallNode;
use PhpSyntax\Nodes\Expression\StaticMethodCallNode;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use PhpSyntax\Parser;
use PhpSyntax\Printer;
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

		use Nette\Forms\Form;

		class MyForm extends Form
		{
			public function check(): void
			{
				$this->invalidateControl();
				$x = self::FILLED;
			}
		}

		function test(Form $form, MyForm $my, $unknown): void
		{
			$a = $form::FILLED;
			$b = MyForm::Filled;
			$form?->redrawControl();
			Form::make();
			$unknown::FILLED;
			$form::{'FILLED'};
			[1, 2][0] + strlen('a');
		}
		PHP);
	$types = analyse($file);

	[$selfConstant, $formConstant, $myConstant, $unknownConstant, $dynamicName] = $file->find(ClassConstantFetchNode::class);
	[$thisCall, $nullsafeCall] = $file->find(MethodCallNode::class);
	[$staticCall] = $file->find(StaticMethodCallNode::class);
	$calleeOf = fn(ExpressionNode $node) => $types->findCallee($node) ?? throw new LogicException('No callee.');

	$callee = $calleeOf($thisCall);
	Assert::same([MemberKind::Method, 'invalidateControl', 'Nette\Forms\Form'], [$callee->kind, $callee->name, $callee->declaringClass]);
	Assert::same('Method Nette\Forms\Form::invalidateControl()', $callee->describe());
	Assert::equal(new Deprecation('use redrawControl()', null, 'redrawControl', replacementIsCall: true), $types->getDeprecation($callee));

	// the constant is decided by the class that declares it, whatever reaches it
	foreach ([$selfConstant, $formConstant] as $access) {
		$callee = $calleeOf($access);
		Assert::same([MemberKind::Constant, 'FILLED', 'Nette\Forms\Form'], [$callee->kind, $callee->name, $callee->declaringClass]);
		Assert::equal(new Deprecation('use Form::Filled', 'Form', 'Filled'), $types->getDeprecation($callee));
	}

	$callee = $calleeOf($myConstant);
	Assert::same(['Filled', 'Nette\Forms\Form'], [$callee->name, $callee->declaringClass]);
	Assert::null($types->getDeprecation($callee));
	Assert::same([MemberKind::Method, 'redrawControl'], [$calleeOf($nullsafeCall)->kind, $calleeOf($nullsafeCall)->name]);
	Assert::same([MemberKind::StaticMethod, 'make'], [$calleeOf($staticCall)->kind, $calleeOf($staticCall)->name]);

	// no member without a known class, or with a name that is an expression
	Assert::null($types->findCallee($unknownConstant));
	Assert::null($types->findCallee($dynamicName));
	Assert::null($types->findCallee($file->find(ExpressionStatementNode::class)[0]));

	$describe = fn(?PHPStan\Type\Type $type) => $type?->describe(VerbosityLevel::precise());
	// a parameter is no expression PHPStan visits; the variable read in the body is
	[, $form] = array_values(array_filter($file->find(VariableNode::class), fn(VariableNode $node) => $node->getFirstToken()?->text === '$form'));
	Assert::same('Nette\Forms\Form', $describe($types->getType($form)));
	$sum = $file->find(PhpSyntax\Nodes\Expression\BinaryOpNode::class)[0];
	Assert::same('2', $describe($types->getType($sum)));
	Assert::same("':filled'", $describe($types->getType($myConstant)));

	// a node inserted after the pass began has no type
	$inserted = (new Parser)->parseExpression('$form');
	$sum->left->replaceWith($inserted);
	Assert::null($types->getType($inserted));
	Assert::same('2', $describe($types->getType($sum)));

	Assert::exception(fn() => $types->getDeprecation(new Analyses\Callee(MemberKind::Constant, 'X', 'Y')), InvalidArgumentException::class);

	// the declared spelling of a class the project or PHP declares, whatever the letter case of the question
	Assert::same('Nette\Forms\Form', $types->findClassName('nette\forms\FORM'));
	Assert::same('DateTimeImmutable', $types->findClassName('datetimeimmutable'));
	Assert::null($types->findClassName('Nette\Forms\Missing'));
});


test('the access a node makes is decided by the receiver, whether or not anything declares the member', function () {
	$file = (new Parser)->parse(<<<'PHP'
		<?php
		namespace App;

		use Nette\Loaders\OldLoader;
		use Nette\Loaders\RobotLoader;
		use Nette\Loaders\Unrelated;

		class MyLoader extends RobotLoader
		{
			public function getCacheKey(string ...$parts): array
			{
				self::RetryLimit;
				static::$count;
				return parent::getCacheKey();
			}
		}

		function test(RobotLoader $loader, MyLoader $my, ?RobotLoader $nullable, RobotLoader|Unrelated $union, MyLoader|OldLoader $alike, int $number, $unknown, string $class): void
		{
			$loader->removedMethod(1);
			$my->getCacheKey();
			$nullable?->tempDirectory;
			$union->getCacheKey();
			$alike->getCacheKey();
			$loader->magic;
			new MyLoader('temp');
			$number->getCacheKey();
			$unknown->getCacheKey();
			$loader->{'getCacheKey'}();
			$my->getCacheKey(...);
			new $class;
			new class extends RobotLoader {
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
	Assert::same([MemberKind::Method, 'removedMethod', ['Nette\Loaders\RobotLoader'], false], $accessOf($removed));
	Assert::null($types->findCallee($removed));

	// a member the child overrides is an access of the child, which the map of the parent is asked about
	Assert::same([MemberKind::Method, 'getCacheKey', ['App\MyLoader'], true], $accessOf($overridden));
	Assert::same('App\MyLoader', $types->findCallee($overridden)?->declaringClass);

	Assert::same([MemberKind::Constant, 'RetryLimit', ['App\MyLoader'], true], $accessOf($constant));
	Assert::same([MemberKind::StaticProperty, 'count', ['App\MyLoader'], true], $accessOf($staticProperty));
	Assert::same([MemberKind::StaticMethod, 'getCacheKey', ['Nette\Loaders\RobotLoader'], true], $accessOf($parentCall));
	Assert::same([MemberKind::Property, 'tempDirectory', ['Nette\Loaders\RobotLoader'], true], $accessOf($property));
	Assert::same([MemberKind::Property, 'magic', ['Nette\Loaders\RobotLoader'], false], $accessOf($magicProperty));
	Assert::same([MemberKind::Constructor, '__construct', ['App\MyLoader'], true], $accessOf($new));
	Assert::same('Constructor Nette\Loaders\RobotLoader::__construct()', $types->findCallee($new)?->describe());

	// the constructor runs as the class that declares it, which a child without its own does not
	$constructor = $types->findConstructorAccess($new);
	Assert::same([MemberKind::Constructor, '__construct', ['Nette\Loaders\RobotLoader'], true], $constructor === null ? null : [$constructor->kind, $constructor->name, $constructor->classes, $constructor->declared]);
	Assert::null($types->findConstructorAccess($parentCall)); // parent::getCacheKey() is no constructor

	// a first-class callable reaches the member as a call does
	Assert::same([MemberKind::Method, 'getCacheKey', ['App\MyLoader'], true], $accessOf($callable));
	Assert::same('App\MyLoader', $types->findCallee($callable)?->declaringClass);

	// every class of a union is there for the asker to refuse the one it does not know
	Assert::same([MemberKind::Method, 'getCacheKey', ['Nette\Loaders\RobotLoader', 'Nette\Loaders\Unrelated'], true], $accessOf($ofUnion));

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
	Assert::same(['App\MyLoader', 'Nette\Loaders\OldLoader'], $types->findAccess($ofAlike)?->classes);
	Assert::same($parametersOf($overridden), $parametersOf($ofAlike)); // and these two alike
	Assert::null($parametersOf($constant));

	// a parameter is no expression PHPStan visits; the variable read in the body is
	[, $union] = array_values(array_filter($file->find(VariableNode::class), fn(VariableNode $node) => $node->getFirstToken()?->text === '$union'));
	Assert::same(['Nette\Loaders\RobotLoader', 'Nette\Loaders\Unrelated'], $types->findClasses($union));
	[, $number] = array_values(array_filter($file->find(VariableNode::class), fn(VariableNode $node) => $node->getFirstToken()?->text === '$number'));
	Assert::same([], $types->findClasses($number));

	Assert::true($types->isSubtype('App\MyLoader', 'nette\loaders\ROBOTLOADER'));
	Assert::true($types->isSubtype('App\MyLoader', 'Nette\Loaders\Loader'));
	Assert::true($types->isSubtype('Nette\Loaders\RobotLoader', 'Nette\Loaders\RobotLoader'));
	Assert::false($types->isSubtype('Nette\Loaders\RobotLoader', 'App\MyLoader'));
	Assert::false($types->isSubtype('Nette\Loaders\Unrelated', 'Nette\Loaders\RobotLoader'));
	Assert::true($types->isSubtype('Nette\Removed', 'nette\removed'));
	Assert::false($types->isSubtype('App\MyLoader', 'Nette\Removed'));
	Assert::true($types->isSubtype('App\MyLoader', 'nette\loaders\caching')); // the trait of the parent
	Assert::false($types->isSubtype('Nette\Loaders\Unrelated', 'Nette\Loaders\Caching'));

	Assert::true($types->hasProperty('App\MyLoader', 'tempDirectory'));
	Assert::false($types->hasProperty('App\MyLoader', 'magic'));
	Assert::false($types->hasProperty('Nette\Removed', 'any'));

	Assert::false($types->isStaticMethod('App\MyLoader', 'GETCACHEKEY'));
	Assert::true($types->isStaticMethod('Nette\Loaders\RobotLoader', 'create'));
	Assert::null($types->isStaticMethod('Nette\Loaders\RobotLoader', 'removedMethod'));
	Assert::null($types->isStaticMethod('Nette\Removed', 'run'));

	[$override, $anonymous] = $file->find(PhpSyntax\Nodes\Member\MethodNode::class);
	Assert::same('App\MyLoader', $types->findDeclaringClass($override));
	Assert::true($types->isSubtype((string) $types->findDeclaringClass($anonymous), 'Nette\Loaders\Loader'));
	Assert::null($types->findDeclaringClass($file));

	Assert::equal(new Deprecation('use Nette\Loaders\RobotLoader', 'Nette\Loaders\RobotLoader'), $types->getClassDeprecation('nette\loaders\oldloader'));
	Assert::null($types->getClassDeprecation('Nette\Loaders\RobotLoader'));
	Assert::null($types->getClassDeprecation('Nette\Removed'));
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


test('a deprecation names its replacement in a shape a tool can read, or it does not', function () {
	Assert::equal(new Deprecation('use Form::Filled', 'Form', 'Filled'), Deprecation::fromDescription('use Form::Filled'));
	Assert::equal(new Deprecation('use \Nette\Forms\Form::Filled instead.', 'Nette\Forms\Form', 'Filled'), Deprecation::fromDescription('use \Nette\Forms\Form::Filled instead.'));
	Assert::equal(new Deprecation('use redrawControl()', null, 'redrawControl', true), Deprecation::fromDescription('use redrawControl()'));
	Assert::equal(new Deprecation('Use $items', null, '$items'), Deprecation::fromDescription('Use $items'));
	Assert::equal(new Deprecation('use something else'), Deprecation::fromDescription('use something else'));
	Assert::equal(new Deprecation('since 3.2'), Deprecation::fromDescription('since 3.2'));
	Assert::equal(new Deprecation(''), Deprecation::fromDescription(''));
});
