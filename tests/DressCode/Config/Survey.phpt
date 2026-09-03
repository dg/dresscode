<?php declare(strict_types=1);

use DressCode\{Config, ConfigurableRule, NodeRule, Profile, RuleContext, RuleInfo, Stage};
use DressCode\Config\Survey;
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\VariableNode;
use Tester\{Assert, Helpers};

require __DIR__ . '/../../bootstrap.php';


/** Wants every variable named after its value, and fails at $boom under the value b. */
#[RuleInfo('test/surveyed', Stage::Structure)]
final class SurveyedRule extends NodeRule implements ConfigurableRule
{
	private string $value = 'a';


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure(['value' => Expect::string('a')]);
	}


	public function configure(array $options): void
	{
		$this->value = $options['value'];
	}


	public function getVisitedTypes(): array
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
			$context->report($node, "Not \$$this->value");
		}
	}
}


$root = __DIR__ . '/../../temp/survey';
@mkdir($root, recursive: true); // @ - may exist
Helpers::purge($root);
file_put_contents("$root/a.php", "<?php\n\$a;\n");
file_put_contents("$root/b.php", "<?php\n\$b;\n");
file_put_contents("$root/boom.php", "<?php\n\$a;\n\$boom;\n");

$survey = new Survey($root, ['a.php', 'b.php', 'boom.php'], new Config);
$values = [
	'a' => new Profile(rules: [SurveyedRule::class => ['value' => 'a']]),
	'b' => new Profile(rules: [SurveyedRule::class => ['value' => 'b']]),
];


test('a file the rule fails in under one value agrees with none of them', function () use ($survey, $values) {
	$measurement = $survey->measureFiles(SurveyedRule::class, $values);
	Assert::same(['a' => 1, 'b' => 1], $measurement->agreeing);
	Assert::same(2, $measurement->opportunities);
	Assert::same('a 50%, b 50% of 2 files, 1 failing file left out', $measurement->describe());
});


test('the price of a configuration tells the files a rule fails in apart from the unchanged ones', function () use ($survey) {
	Assert::same([0, 1], $survey->countChanged(new Config(rules: [SurveyedRule::class => ['value' => 'b']])));
});


test('nor are its places counted for any of them', function () use ($survey, $values) {
	$measurement = $survey->measurePlaces(SurveyedRule::class, $values, 'variables');
	Assert::same(['a' => 1, 'b' => 1], $measurement->agreeing);
	Assert::same(2, $measurement->opportunities);
	Assert::same(1, $measurement->failed);
});
