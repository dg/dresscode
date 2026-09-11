<?php declare(strict_types=1);

namespace DressCode\Engine;

use DressCode\Analyses;
use DressCode\ConfigurationException;
use DressCode\ConvergenceException;
use DressCode\Engine\Gaps\Fixer;
use DressCode\Engine\Gaps\Resolver;
use DressCode\NodeRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleException;
use DressCode\RuleInfo;
use DressCode\Severity;
use DressCode\Stage;
use DressCode\Violation;
use PhpSyntax\Node;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Printer;
use PhpSyntax\Style;
use PhpSyntax\Token;
use PhpSyntax\Traverser;
use function strlen;


/**
 * Runs the rules over a file in passes until nothing mutates: each pass is one pre-order traversal per stage,
 * rules are dispatched by the type of the node or token, every mutation is paired with a report.
 * @internal
 */
final class PassRunner
{
	/** @var array<string, list<NodeRule>>  stage name → node rules in configuration order */
	private array $stages = [];

	/** @var array<string, array<class-string, list<array{NodeRule, RuleContext, string}>>>  stage → node class → rules entering it, with their contexts and names */
	private array $entering = [];

	/** @var array<string, array<class-string, list<array{NodeRule, RuleContext, string}>>>  the same for leave() */
	private array $leaving = [];

	/** @var array<string, bool>  stage → some rule of it overrides leave() */
	private array $leaves = [];

	/** @var array<string, RuleContext> */
	private array $contexts = [];

	/** the gap rules, applied together along the traversal of the formatting stage */
	private readonly Resolver $gaps;

	/** @var array<string, true>  rules that mutated the file in the current pass */
	private array $mutatedRules = [];

	/** @var array<string, Violation>  by fingerprint, so that a pass repeating a report adds nothing */
	private array $violations = [];

	/** @var \WeakMap<Token, string>  a token whose line the fixer opened or closed → the fingerprint of the violation it did it for, the first of its chain */
	private \WeakMap $opened;

	/** @var \WeakMap<Token, string>  a token whose line a rule moved → the fingerprint of the violation the move follows from */
	private \WeakMap $moved;

	/** @var list<string> */
	private array $warnings = [];

	private Fingerprints $fingerprints;

	/** @var list<string> */
	private array $lines = [];

	/** @var list<int>  byte offset of the start of each line of the original code */
	private array $lineOffsets = [];
	private string $code = '';

	private FileNode $file;
	private string $path = '';


	public function __construct(
		/** @var list<Rule> in configuration order */
		private readonly array $rules,
		private readonly Analyses\Registry $analyses,
		/** @var \Closure(string): list<string> a name in a suppression comment → the rules it stands for */
		private readonly \Closure $resolveNames,
		private readonly int $maxPasses = 10,
		/** a broken rule contract (silent mutation, mutation after a suppressed report) throws instead of warning */
		private readonly bool $strict = false,
		/** violations it knows are neither reported nor fixed */
		private readonly ?Baseline $baseline = null,
		/** @var array<string, true>  rules whose violations only warn */
		private readonly array $warningRules = [],
		/** whether a fix that may change what the code does is allowed */
		private readonly bool $fixRisky = false,
	) {
		foreach (Stage::cases() as $stage) {
			$this->stages[$stage->name] = [];
			$this->leaves[$stage->name] = false;
		}

		foreach ($rules as $rule) {
			if ($rule instanceof NodeRule) {
				$stage = RuleInfo::of($rule)->stage->name;
				$this->stages[$stage][] = $rule;
				$this->leaves[$stage] = $this->leaves[$stage] || self::overrides($rule, 'leave');
			}
		}

		$this->gaps = new Resolver($rules);
	}


	/** @throws RuleException|ConvergenceException */
	public function run(FileNode $file, string $code, string $path, Style $style, string $phpVersion): PassResult
	{
		$this->file = $file;
		$this->path = $path;
		$this->code = $code;
		$this->lines = preg_split('~\r\n|\r|\n~', $code);
		$this->lineOffsets = [0];
		preg_match_all('~\r\n|\r|\n~', $code, $matches, PREG_OFFSET_CAPTURE);
		foreach ($matches[0] as [$eol, $offset]) {
			$this->lineOffsets[] = $offset + strlen($eol);
		}
		$this->violations = $this->warnings = $this->contexts = $this->entering = $this->leaving = [];
		$this->opened = new \WeakMap;
		$this->moved = new \WeakMap;
		$this->fingerprints = new Fingerprints($this->lines, $path, $this->baseline);
		$suppression = Suppression::fromFile($file, $this->resolveNames, $code);
		foreach ($this->rules as $rule) {
			$name = RuleInfo::of($rule)->name;
			$this->contexts[$name] = new RuleContext($file, $path, $style, $phpVersion, $this->analyses, $suppression, $this->fingerprints, $name, $this->fixRisky);
		}

		$seen = [hash('xxh3', $code) => true];
		$last = $code;
		$passes = 0;
		$mutated = false;
		while (true) {
			if ($passes++ === $this->maxPasses) {
				throw new ConvergenceException($path, array_keys($this->mutatedRules), '');
			}

			$this->mutatedRules = [];
			$this->fingerprints->startPass();
			$revision = $file->revision;
			foreach ($this->stages as $stage => $rules) {
				$this->runStage($stage, $rules, $style);
			}

			if ($file->revision === $revision) {
				break;
			}

			$mutated = true;
			$output = Printer::print($file);
			$hash = hash('xxh3', $output);
			if (isset($seen[$hash])) { // a state seen before: the rules cycle
				throw new ConvergenceException($path, array_keys($this->mutatedRules), Diff::unified($last, $output, $path));
			}

			$seen[$hash] = true;
			$last = $output;
		}

		$violations = array_values($this->violations);
		// the passes report by rule, the reader reads by position
		usort($violations, fn(Violation $a, Violation $b) => [$a->line, $a->column ?? 0] <=> [$b->line, $b->column ?? 0]);
		return new PassResult($violations, $this->warnings, $passes, $mutated, $this->fingerprints->getSilenced());
	}


	/** @param list<NodeRule> $rules */
	private function runStage(string $stage, array $rules, Style $style): void
	{
		// the gap rules ride along the traversal of the formatting stage and report under their names;
		// a rule is accounted for when it reported, the revision before the traversal standing for the whole of it
		$gaps = $stage === Stage::Formatting->name && $this->gaps->hasRules() ? $this->gaps : null;
		if ($rules === [] && $gaps === null) {
			return;
		}

		foreach ($rules as $rule) {
			$this->invoke($rule, fn(RuleContext $context) => $rule->beforeFile($context));
		}

		$gaps?->begin($style, new Fixer($this->contexts));
		$before = $this->file->revision;
		$enter = function (Node|Token $node) use ($stage, $gaps): void {
			$this->visit($stage, $node, enter: true);
			// a node a rule has just taken out of the tree has no gaps to decide
			if ($gaps === null || ($node->parent === null && !$node instanceof FileNode)) {
				return;
			}

			try {
				$node instanceof Token ? $gaps->enterToken($node) : $gaps->enterNode($node);
			} catch (ConfigurationException $e) { // two claims deciding one gap: the configuration is wrong, not the file
				throw $e;
			} catch (\Throwable $e) {
				throw new RuleException('gaps', $this->path, $e);
			}
		};
		// the traverser leaves every node it entered, a replaced one included; a rule sees only what is still in the tree
		$leave = fn(Node|Token $node) => $node->parent === null && $node !== $this->file
			? null
			: $this->visit($stage, $node, enter: false);
		new Traverser()->traverse($this->file, $enter, $this->leaves[$stage] ? $leave : null);

		if ($gaps !== null) {
			foreach ($this->contexts as $name => $context) {
				if ($context->hasReports()) {
					// the whitespace of a gap is written by the engine, always after a report of its own,
					// so an unreported mutation here belongs to another rule of the same traversal
					$this->account($name, $context, $before, checkSilent: false);
				}
			}
		}

		foreach ($rules as $rule) {
			$this->invoke($rule, fn(RuleContext $context) => $rule->afterFile($context));
		}
	}


	private function visit(string $stage, Node|Token $node, bool $enter): void
	{
		$class = $node::class;
		$rules = ($enter ? $this->entering : $this->leaving)[$stage][$class] ?? $this->resolveRules($stage, $node, $enter);
		if ($rules === []) {
			return;
		}

		$parent = $node->parent;
		$file = $this->file;
		foreach ($rules as [$rule, $context, $name]) {
			$before = $file->revision;
			try {
				$enter ? $rule->enter($node, $context) : $rule->leave($node, $context);
			} catch (\Throwable $e) {
				throw new RuleException($name, $this->path, $e);
			}

			if ($file->revision !== $before || $context->hasReports()) {
				$this->account($name, $context, $before);
			}

			if ($node->parent !== $parent) { // replaced or removed: the rest of the chain never sees it
				return;
			}
		}
	}


	/**
	 * The rules of the stage whose enter() or leave() wants the class of the node, remembered for the next node of it.
	 * @return list<array{NodeRule, RuleContext, string}>
	 */
	private function resolveRules(string $stage, Node|Token $node, bool $enter): array
	{
		$method = $enter ? 'enter' : 'leave';
		$rules = [];
		foreach ($this->stages[$stage] as $rule) {
			if (!self::overrides($rule, $method)) {
				continue;
			}

			foreach ($rule->getVisitedTypes() as $type) {
				if ($node instanceof $type) {
					$name = RuleInfo::of($rule)->name;
					$rules[] = [$rule, $this->contexts[$name], $name];
					break;
				}
			}
		}

		if ($enter) {
			$this->entering[$stage][$node::class] = $rules;
		} else {
			$this->leaving[$stage][$node::class] = $rules;
		}

		return $rules;
	}


	private static function overrides(NodeRule $rule, string $method): bool
	{
		static $cache = [];
		return $cache[$rule::class][$method]
			??= new \ReflectionMethod($rule, $method)->getDeclaringClass()->getName() !== NodeRule::class;
	}


	/**
	 * Calls a per-file callback of a rule and accounts for what it did.
	 * @param \Closure(RuleContext): void $callback
	 */
	private function invoke(NodeRule $rule, \Closure $callback): void
	{
		$name = RuleInfo::of($rule)->name;
		$context = $this->contexts[$name];
		$before = $this->file->revision;
		try {
			$callback($context);
		} catch (\Throwable $e) {
			throw new RuleException($name, $this->path, $e);
		}

		$this->account($name, $context, $before);
	}


	/**
	 * Turns the reports of a callback into violations, marks the fixed ones, and checks that every mutation
	 * follows a report that returned true. A report the rule was denied — by a comment, by the baseline or
	 * because the run refuses a risky fix — is judged by the window between it and the next report of the
	 * same rule: what the rule wrote there it wrote for the report it was denied.
	 * @param int $before  the revision of the file before the callback
	 * @param bool $checkSilent  whether an unreported mutation is the rule's doing, which along the gap
	 *                           traversal it need not be, because the revision then covers every rule
	 */
	private function account(string $name, RuleContext $context, int $before, bool $checkSilent = true): void
	{
		$after = $this->file->revision;
		$reported = false;
		$reports = $context->takeReports();
		foreach ($reports as $i => $report) {
			$fingerprint = $report->fingerprint;
			$refused = $report->risky && !$this->fixRisky;
			$denied = $report->silenced || $fingerprint === null || $refused; // a comment, the baseline or the risk
			// what the rule wrote between this report and its next one it wrote for the report it was denied
			if ($denied && ($reports[$i + 1]->revision ?? $after) > $report->revision) {
				$this->violateContract("Rule $name mutated the file after a suppressed report.");
			}

			if ($report->silenced || $fingerprint === null) { // and then there is no violation to record
				continue;
			}

			$reported = true;
			$derivedFrom = $this->findAncestor($report);
			$this->violations[$fingerprint] ??= new Violation(
				$name,
				$report->message,
				$report->line,
				$report->trivia === null ? $this->findOriginalColumn($report->at) : null,
				// every rule is an error until the configuration softens it
				isset($this->warningRules[$name]) ? Severity::Warning : $report->severity,
				// a rule may write its fixes after reporting them all, so the whole callback is the window
				fixable: !$refused && $after > $report->revision,
				fingerprint: $fingerprint,
				risky: $report->risky,
				derivedFrom: $derivedFrom === $fingerprint ? null : $derivedFrom,
			);
			// what is placed by this line follows from what opened, closed or moved it, the first of the chain
			if ($report->gap !== null && !$refused) {
				if ($report->breaks) {
					$this->opened[$report->gap] ??= $derivedFrom ?? $fingerprint;
				} elseif ($report->follows !== null) {
					$this->moved[$report->gap] ??= $derivedFrom ?? $fingerprint;
				}
			}
		}

		if ($after > $before) {
			$this->mutatedRules[$name] = true;
			if (!$reported && $checkSilent) {
				$this->violateContract("Rule $name mutated the file without reporting a violation.");
			}
		}
	}


	/**
	 * The violation the report follows from: the one the fixer opened or closed the line of the reported gap
	 * for, else the one that opened, closed or moved the line the reported whitespace is counted from.
	 */
	private function findAncestor(Report $report): ?string
	{
		if ($report->gap !== null && isset($this->opened[$report->gap])) {
			return $this->opened[$report->gap];
		}

		$follows = $report->follows;
		return $follows === null ? null : $this->opened[$follows] ?? $this->moved[$follows] ?? null;
	}


	private function violateContract(string $message): void
	{
		if ($this->strict) {
			throw new RuleException('', $this->path, new \LogicException($message));
		}

		$this->warnings[] = $message;
	}


	/**
	 * Column in characters of the token in the original file, from its original offset and the start of its line.
	 */
	private function findOriginalColumn(Node|Token $at): ?int
	{
		$token = $at instanceof Token ? $at : $at->getFirstToken();
		if ($token?->originalOffset === null || $token->originalLine === null) {
			return null;
		}

		$lineStart = $this->lineOffsets[$token->originalLine - 1] ?? 0;
		$before = substr($this->code, $lineStart, $token->originalOffset - $lineStart);
		return strlen($before) - preg_match_all('~[\x80-\xBF]~', $before) + 1;
	}
}
