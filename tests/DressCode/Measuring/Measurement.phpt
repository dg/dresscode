<?php declare(strict_types=1);

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Text;
use DressCode\Measuring\{Classification, InProcessProbeRunner, Measurement};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\VariableNode;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** Wants every variable named after its value, and fails at $boom under the value b. */
#[RuleInfo(Stage::Structure)]
final class MeasuredRule extends NodeRule
{
	private string $value = 'a';


	public static function getDecisions(): array
	{
		return [new Decision('project.variable', new Text, 'The name every variable has')];
	}


	public function configure(Values $values): void
	{
		$this->value = $values->get('project.variable')->getText();
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof VariableNode || !$node->name instanceof Token) {
			return;
		} elseif ($node->name->text === '$boom' && $this->value === 'b') {
			throw new RuntimeException('boom');
		} elseif ($node->name->text !== '$' . $this->value) {
			$context->report($node, "Not \$$this->value.");
		}
	}
}


test('the places of a decision of two values are what the two report together, less those in neither', function () {
	// 6 places agree with a, 3 with b, 1 with neither: a reports 3 + 1, b 6 + 1, the profile of both 1
	$measurement = Measurement::fromReportedPlaces(['a' => 4, 'b' => 7], 'places', 1);
	Assert::same(10, $measurement->opportunities);
	Assert::same(['a' => 6, 'b' => 3], $measurement->agreeing);
});


test('a place of a decision of three values is reported by the two it disagrees with', function () {
	// 5 a, 3 b, 2 c, 2 in none: each value reports the places of the others and the 2
	$measurement = Measurement::fromReportedPlaces(['a' => 7, 'b' => 9, 'c' => 10], 'places', 2);
	Assert::same(12, $measurement->opportunities);
	Assert::same(['a' => 5, 'b' => 3, 'c' => 2], $measurement->agreeing);
	Assert::same('a', $measurement->findCommonest());
});


test('of five values each place is reported four times, or five where it agrees with none', function () {
	// 20 a, 4 b, none of c, d and e, no profile of all of them
	$measurement = Measurement::fromReportedPlaces(['a' => 4, 'b' => 20, 'c' => 24, 'd' => 24, 'e' => 24], 'names');
	Assert::same(24, $measurement->opportunities);
	Assert::same(['a' => 20, 'b' => 4, 'c' => 0, 'd' => 0, 'e' => 0], $measurement->agreeing);
	Assert::same('a', $measurement->findPrevailing());
	Assert::same('a in 83%, b in 17% of 24 names', $measurement->describe());
});


test('what the code says of a decision is told by the share of its commonest value, the count of places and the tolerance', function () {
	$classify = fn(array $reported, int $neither = 0, bool $tolerance = false) => Measurement::fromReportedPlaces(
		$reported,
		'places',
		$neither,
	)
		->classify($tolerance);
	Assert::same(Classification::Settled, $classify(['a' => 3, 'b' => 9])); // a in 9 of 12
	Assert::same(Classification::SettledWeakly, $classify(['a' => 0, 'b' => 3])); // a in all 3
	Assert::same(Classification::Mixed, $classify(['a' => 1, 'b' => 3])); // a in 3 of 4, too few to settle
	Assert::same(Classification::Mixed, $classify(['a' => 6, 'b' => 6])); // half and half
	Assert::same(Classification::Tolerated, $classify(['a' => 6, 'b' => 6], tolerance: true));
	Assert::same(Classification::Mixed, $classify(['a' => 10, 'b' => 10], neither: 8, tolerance: true)); // 8 of 12 in none
	Assert::same(Classification::Absent, $classify(['a' => 0, 'b' => 0]));
});


test('a decision nothing reports under any value appears nowhere', function () {
	$measurement = Measurement::fromReportedPlaces(['a' => 0, 'b' => 0, 'c' => 0], 'places');
	Assert::same(0, $measurement->opportunities);
	Assert::null($measurement->findCommonest());
});


test('a file a rule fails in under one value counts for none of them, nor do its places', function () {
	$root = createTempDir('measured');
	file_put_contents("$root/a.php", "<?php\n\$a;\n");
	file_put_contents("$root/b.php", "<?php\n\$b;\n");
	file_put_contents("$root/boom.php", "<?php\n\$a;\n\$boom;\n");
	$base = ['paths' => ['a.php', 'b.php', 'boom.php'], 'rules' => [MeasuredRule::class]];
	$probes = new InProcessProbeRunner($root)->run([
		'a' => [...$base, 'project' => ['variable' => 'a']],
		'b' => [...$base, 'project' => ['variable' => 'b']],
	]);

	$files = Measurement::fromFileProbes($probes, 'project.variable');
	Assert::same(['a' => 1, 'b' => 1], $files->agreeing);
	Assert::same(2, $files->opportunities);
	Assert::same('a in 50%, b in 50% of 2 files, 1 failing file left out', $files->describe());

	$places = Measurement::fromPlaceProbes($probes, 'project.variable', 'variables');
	Assert::same(['a' => 1, 'b' => 1], $places->agreeing);
	Assert::same(2, $places->opportunities);
	Assert::same(1, $places->failed);
});
