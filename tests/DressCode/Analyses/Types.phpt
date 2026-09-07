<?php declare(strict_types=1);

use DressCode\{Analyses, Tristate};
use PhpSyntax\Nodes\Expression\{ClassConstantFetchNode, VariableNode};
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\{Parser, Printer};
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
	[, $order] = array_values(array_filter($file->find(VariableNode::class), fn(VariableNode $node) => $node->getFirstToken()?->text === '$order'));
	Assert::same(Tristate::Yes, $types->isOfType($order, 'Acme\Shop\Order'));
	Assert::same(Tristate::No, $types->isOfType($order, 'string'));
	$sum = $file->find(PhpSyntax\Nodes\Expression\BinaryOpNode::class)[0];
	Assert::same(Tristate::Yes, $types->isOfType($sum, '2'));
	[, , $myConstant] = $file->find(ClassConstantFetchNode::class);
	Assert::same(Tristate::Yes, $types->isOfType($myConstant, "'paid'"));
	Assert::same(Tristate::Yes, $types->isOfType($myConstant, 'non-empty-string'));

	// a node inserted after the pass began has no type
	$inserted = (new Parser)->parseExpression('$order');
	$sum->left->replaceWith($inserted);
	Assert::same(Tristate::Maybe, $types->isOfType($inserted, 'Acme\Shop\Order'));
	Assert::same(Tristate::Yes, $types->isOfType($sum, '2'));
});
