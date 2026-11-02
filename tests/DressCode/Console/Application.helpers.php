<?php declare(strict_types=1);

// the rules of the project and the run of the application that the tests of Application.*.phpt share

use DressCode\Console\Application;
use DressCode\{Decision, Domain, NodeRule, Plugin, PluginManifest, Risk, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\VariableNode;


#[RuleInfo(Stage::Structure)]
final class ConsoleRename extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('project.rename', Domain::state('forbidden'), '`$a` is `$b`')];
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof VariableNode && $node->name instanceof Token && $node->name->text === '$a') {
			if ($context->report($node, 'Rename $a.')) {
				$node->name->setText('$b');
			}
		}
	}
}


#[RuleInfo(Stage::Structure)]
final class ConsoleRiskyRename extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('project.riskyRename', Domain::state('forbidden'), '`$r` is `$s`')];
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof VariableNode && $node->name instanceof Token && $node->name->text === '$r') {
			if ($context->report($node, 'Rename $r.', risk: Risk::BehaviorChanges)) {
				$node->name->setText('$s');
			}
		}
	}
}


#[RuleInfo(Stage::Formatting)]
final class ConsoleReport extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('project.report', Domain::state('forbidden'), 'A variable is reported')];
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$context->report($node, 'Seen.', fixable: false);
	}
}


#[RuleInfo(Stage::Formatting)]
final class ConsoleFailing extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('project.failing', Domain::state('forbidden'), 'A variable breaks the rule')];
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		throw new LogicException('Broken rule');
	}
}


/** the rules of the project every configuration of these tests names */
const ConsoleRules = [ConsoleRename::class, ConsoleRiskyRename::class, ConsoleReport::class, ConsoleFailing::class];


final class ConsolePlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest;
	}
}


/** A project of its own with the configuration most tests run with, src/a.php to rename and src/b.php clean. */
function createConsoleProject(): string
{
	$root = createTempDir('console');
	mkdir("$root/src");
	// the target is pinned, so that a rule of a newer PHP has something to be newer than
	file_put_contents(
		"$root/dresscode.php",
		"<?php\nreturn new DressCode\\Config(rules: ConsoleRules, decisions: ['project' => ['rename' => 'forbidden']], paths: ['src'], targets: ['php' => '8.3']);\n",
	);
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n");
	file_put_contents("$root/src/b.php", "<?php\n\$x;\n");
	return $root;
}


/**
 * @param  list<string>  $args
 * @return array{int, string, string}
 */
function runApp(string $root, array $args, string $stdin = '', bool $xdebug = false, bool $interactive = false): array
{
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$in = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	fwrite($in, $stdin);
	rewind($in);
	if (in_array($args[0] ?? null, ['check', 'fix', 'baseline'], strict: true) && !in_array('--jobs', $args, strict: true)) {
		$args = [...$args, '--jobs', '1']; // the rules of this file do not exist in a worker process
	}

	$code = new Application(
		$out,
		$err,
		$in,
		$root,
		script: __DIR__ . '/../../../bin/dresscode',
		xdebug: $xdebug,
		interactive: $interactive,
	)->run(
		[
			'dresscode',
			...$args,
		],
	);
	rewind($out);
	rewind($err);
	// how long a run took is up to the machine, which a busy one makes long enough to be said
	$clean = fn($stream) => (string) preg_replace('~, \d+\.\d s$~m', '', (string) stream_get_contents($stream));
	return [$code, $clean($out), $clean($err)];
}
