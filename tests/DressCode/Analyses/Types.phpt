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
