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


test('a deprecation names its replacement in a shape a tool can read, or it does not', function () {
	Assert::equal(new Deprecation('use Form::Filled', 'Form', 'Filled'), Deprecation::fromDescription('use Form::Filled'));
	Assert::equal(new Deprecation('use \Nette\Forms\Form::Filled instead.', 'Nette\Forms\Form', 'Filled'), Deprecation::fromDescription('use \Nette\Forms\Form::Filled instead.'));
	Assert::equal(new Deprecation('use redrawControl()', null, 'redrawControl', true), Deprecation::fromDescription('use redrawControl()'));
	Assert::equal(new Deprecation('Use $items', null, '$items'), Deprecation::fromDescription('Use $items'));
	Assert::equal(new Deprecation('use something else'), Deprecation::fromDescription('use something else'));
	Assert::equal(new Deprecation('since 3.2'), Deprecation::fromDescription('since 3.2'));
	Assert::equal(new Deprecation(''), Deprecation::fromDescription(''));
});
