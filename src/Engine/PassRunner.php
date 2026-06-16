<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\{Analyses, ConfigurationException, ConvergenceException, NodeRule, RuleContext, RuleException, RuleInfo, Severity, Stage, Style, Violation};
use DressCode\Engine\Gaps\{Fixer, Resolver};
use PhpSyntax\{Node, Printer, Token, Traverser, Trivia};
use PhpSyntax\Nodes\FileNode;
use function strlen;


/**
 * Runs the rules over a file in passes until nothing mutates: each pass is one pre-order traversal per stage,
 * rules are dispatched by the type of the node or token, every mutation is paired with a report.
 * @internal
 */
final class PassRunner
{
	/** @var array<string, array<class-string, list<array{NodeRule, RuleContext, string}>>>  stage => node class => rules entering it, with their contexts and names */
	private array $entering = [];

	/** @var array<string, array<class-string, list<array{NodeRule, RuleContext, string}>>>  the same for `leave()` */
	private array $leaving = [];

	/** @var array<string, RuleContext> */
	private array $contexts = [];

	/** the gap rules, applied together along the traversal of the formatting stage */
	private readonly Resolver $gaps;

	/** @var array<string, true>  rules that mutated the file in the current pass */
	private array $mutatedRules = [];

	/** @var array<string, Violation>  by fingerprint, so that a pass repeating a report adds nothing */
	private array $violations = [];

	/** @var \WeakMap<Token, string>  a token whose line the fixer opened or closed => the fingerprint of the violation it did it for, the first of its chain */
	private \WeakMap $opened;

	/** @var \WeakMap<Token, string>  a token whose line a rule moved => the fingerprint of the violation the move follows from */
	private \WeakMap $moved;

	/** @var list<array{string, Report, bool, Severity}>  the reports of violations of the current pass: the rule, the report, whether its fix was refused, its severity */
	private array $reported = [];

	/** @var list<string> */
	private array $warnings = [];

	private Fingerprints $fingerprints;

	/** @var list<int>  byte offset of the start of each line of the original code */
	private array $lineOffsets = [];
	private string $code = '';

	private FileNode $file;
	private string $path = '';


	public function __construct(
		private readonly RulePlan $plan,
		private readonly Analyses\Registry $analyses,
		/** @var \Closure(string): list<string> a name in a suppression comment => the rules it stands for */
		private readonly \Closure $resolveNames,
		private readonly int $maxPasses = 10,
		/** a broken rule contract throws instead of warning */
		private readonly bool $strict = false,
		/** @var array<string, true>  rules whose violations only warn */
		private readonly array $warningRules = [],
		/** whether every fix that may change what the code does is allowed */
		private readonly bool $fixRisky = false,
		/** @var array<string, true>  rules whose fixes that may change what the code does are allowed */
		private readonly array $fixRiskyRules = [],
	) {
		$this->gaps = new Resolver($plan->claims);
	}


	/** @throws RuleException|ConvergenceException|ConfigurationException */
	public function run(FileNode $file, string $code, string $path, Style $style, string $phpVersion): PassResult
	{
		$this->file = $file;
		$this->path = $path;
		$this->code = $code;
		$lines = preg_split('~\r\n|\r|\n~', $code);
		$this->lineOffsets = [0];
		preg_match_all('~\r\n|\r|\n~', $code, $matches, PREG_OFFSET_CAPTURE);
		foreach ($matches[0] as [$eol, $offset]) {
			$this->lineOffsets[] = $offset + strlen($eol);
		}
		$this->violations = $this->warnings = $this->contexts = $this->entering = $this->leaving = [];
		$this->opened = new \WeakMap;
		$this->moved = new \WeakMap;
		$this->fingerprints = new Fingerprints($lines);
		$suppression = Suppression::fromFile($file, $this->resolveNames, $code);
		foreach ($this->plan->rules as $rule) {
			$name = RuleInfo::of($rule)->name;
			$this->contexts[$name] = new RuleContext($file, $path, $style, $phpVersion, $this->analyses, $suppression, $this->fingerprints, $name, $this->fixRisky || isset($this->fixRiskyRules[$name]), $this->plan->rules, $this->strict);
		}

		$seen = [hash('xxh3', $code) => true];
		$last = $code;
		$passes = 0;
		$mutated = false;
		$mutatedRules = [];
		while (true) {
			if ($passes++ === $this->maxPasses) {
				throw new ConvergenceException($path, array_keys($this->mutatedRules), '');
			}

			$this->mutatedRules = $this->reported = [];
			$this->fingerprints->startPass();
			foreach ($this->contexts as $context) {
				$context->storage = [];
			}

			$revision = $file->revision;
			foreach ($this->plan->stages as $stage => $rules) {
				$this->runStage($stage, $rules, $style);
			}

			if ($file->revision === $revision) {
				$this->checkUnfixed();
				break;
			}

			$mutated = true;
			$mutatedRules += $this->mutatedRules;
			$output = Printer::print($file);
			$hash = hash('xxh3', $output);
			if (isset($seen[$hash])) { // a state seen before: the rules cycle
				throw new ConvergenceException($path, array_keys($this->mutatedRules), Diff::unified($last, $output, $path));
			}

			$seen[$hash] = true;
			$last = $output;
		}

		return new PassResult(
			self::sortByPosition(array_values($this->violations)),
			$this->warnings,
			$passes,
			$mutated,
			array_keys($mutatedRules),
			remaining: $mutated ? $this->collectRemaining($last) : null,
		);
	}


	/**
	 * The violations of the fixed text: what the last pass, which changed nothing, reported, identified by the lines
	 * of that text as a run over it would identify them.
	 * @return list<Violation>
	 */
	private function collectRemaining(string $text): array
	{
		$fingerprints = new Fingerprints(preg_split('~\r\n|\r|\n~', $text) ?: []);
		/** @var \WeakMap<Token, string> $opened */
		$opened = new \WeakMap;
		/** @var \WeakMap<Token, string> $moved */
		$moved = new \WeakMap;
		$violations = [];
		foreach ($this->reported as [$name, $report, $refused, $severity]) {
			$token = $report->at instanceof Token ? $report->at : $report->at->getFirstToken();
			$line = self::findCurrentLine($token, $report->trivia);
			$fingerprint = $report->construct === null
				? $fingerprints->create($name, $report->message, $line)
				: $fingerprints->createFor($report->construct, $name, $report->message, $line);
			$derivedFrom = self::findAncestor($report, $opened, $moved);
			$violations[$fingerprint] ??= new Violation(
				$name,
				$report->message,
				$line,
				$report->trivia === null ? $token?->currentColumn : null,
				$severity,
				fingerprint: $fingerprint,
				risk: $report->risk,
				refused: $refused,
				because: $report->because,
				derivedFrom: $derivedFrom === $fingerprint ? null : $derivedFrom,
			);
			self::markLine($report, $refused, $derivedFrom ?? $fingerprint, $opened, $moved);
		}

		return self::sortByPosition(array_values($violations));
	}


	/** The line of the token or of its trivia in the current text; the trivia is counted back from the token, whose line the index follows. */
	private static function findCurrentLine(?Token $token, ?Trivia $trivia): int
	{
		$line = $token->currentLine ?? 1;
		if ($token === null || $trivia === null) {
			return $line;
		}

		$leading = array_search($trivia, $token->leadingTrivia, strict: true);
		if ($leading !== false) {
			foreach (array_slice($token->leadingTrivia, $leading) as $item) {
				$line -= substr_count($item->text, "\n");
			}

			return $line;
		}

		$line += substr_count($token->text, "\n");
		foreach ($token->trailingTrivia as $item) {
			if ($item === $trivia) {
				break;
			}

			$line += substr_count($item->text, "\n");
		}

		return $line;
	}


	/**
	 * The passes report by rule, the reader reads by position.
	 * @param list<Violation> $violations
	 * @return list<Violation>
	 */
	private static function sortByPosition(array $violations): array
	{
		usort($violations, fn(Violation $a, Violation $b) => [$a->line, $a->column ?? 0] <=> [$b->line, $b->column ?? 0]);
		return $violations;
	}


	/** @param list<NodeRule> $rules */
	private function runStage(string $stage, array $rules, Style $style): void
	{
		// the gap rules ride along the traversal of the formatting stage and report under their names;
		// a rule is accounted for when it reported, the revision before the traversal standing for the whole of it
		$gaps = $stage === Stage::Formatting->name && !$this->plan->claims->isEmpty() ? $this->gaps : null;
		if ($rules === [] && $gaps === null) {
			return;
		}

		foreach ($rules as $rule) {
			$this->invoke($rule, fn(RuleContext $context) => $rule->beforePass($context));
		}

		$gaps?->begin($style, new Fixer($this->contexts));
		$before = $this->file->revision;
		$enter = function (Node|Token $node) use ($stage, $gaps): void {
			if (($this->entering[$stage][$node::class] ?? null) !== []) {
				$this->visit($stage, $node, enter: true);
			}

			// a node a rule has just taken out of the tree has no gaps to decide
			if ($gaps === null || ($node->parent === null && !$node instanceof FileNode)) {
				return;
			}

			try {
				$node instanceof Token ? $gaps->enterToken($node) : $gaps->enterNode($node);
			} catch (ConfigurationException $e) { // two claims deciding one gap: the configuration is wrong, not the file
				throw $e;
			} catch (\Throwable $e) {
				throw new RuleException(null, $this->path, $e);
			}
		};
		// the traverser leaves every node it entered, a replaced one included; a rule sees only what is still in the tree
		$leave = fn(Node|Token $node) => ($this->leaving[$stage][$node::class] ?? null) === [] || ($node->parent === null && $node !== $this->file)
			? null
			: $this->visit($stage, $node, enter: false);
		Traverser::traverse($this->file, $enter, $this->plan->leaves[$stage] ? $leave : null);

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
			$this->invoke($rule, fn(RuleContext $context) => $rule->afterPass($context));
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
	 * The rules the plan dispatches the class of the node to, with their contexts in this file.
	 * @return list<array{NodeRule, RuleContext, string}>
	 */
	private function resolveRules(string $stage, Node|Token $node, bool $enter): array
	{
		$rules = [];
		foreach ($this->plan->getRulesVisiting($stage, $node::class, $enter) as $rule) {
			$name = RuleInfo::of($rule)->name;
			$rules[] = [$rule, $this->contexts[$name], $name];
		}

		if ($enter) {
			$this->entering[$stage][$node::class] = $rules;
		} else {
			$this->leaving[$stage][$node::class] = $rules;
		}

		return $rules;
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
	 * Turns the reports of a callback into violations and checks that every mutation follows a report that returned
	 * true; what the rule wrote after a denied report, up to its next one, it wrote for that report.
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
			$refused = $report->risk !== null && !$this->fixRisky && !isset($this->fixRiskyRules[$name]);
			$denied = !$report->fixable || $report->silenced || $refused;
			if ($denied && ($reports[$i + 1]->revision ?? $after) > $report->revision) {
				$this->violateContract($name, $report->fixable
					? 'it changed the file after a suppressed report'
					: 'it changed the file after a report without a fix');
			}

			if ($report->silenced || $fingerprint === null) { // and then there is no violation to record
				continue;
			}

			$reported = true;
			$severity = isset($this->warningRules[$name]) ? Severity::Warning : Severity::Error;
			$this->reported[] = [$name, $report, $refused, $severity];
			$derivedFrom = self::findAncestor($report, $this->opened, $this->moved);
			$this->violations[$fingerprint] ??= new Violation(
				$name,
				$report->message,
				$report->line,
				$report->trivia === null ? $this->findOriginalColumn($report->at) : null,
				$severity,
				fingerprint: $fingerprint,
				risk: $report->risk,
				refused: $refused,
				because: $report->because,
				derivedFrom: $derivedFrom === $fingerprint ? null : $derivedFrom,
			);
			self::markLine($report, $refused, $derivedFrom ?? $fingerprint, $this->opened, $this->moved);
		}

		if ($after > $before) {
			$this->mutatedRules[$name] = true;
			if (!$reported && $checkSilent) {
				$this->violateContract($name, 'it changed the file without reporting a violation');
			}
		}
	}


	/**
	 * The violation the report follows from: the one the fixer opened or closed the line of the reported gap
	 * for, else the one that opened, closed or moved the line the reported whitespace is counted from. A report
	 * about the shape of the line it stands on asks who opened or closed that line, and nothing about whitespace.
	 * @param \WeakMap<Token, string> $opened
	 * @param \WeakMap<Token, string> $moved
	 */
	private static function findAncestor(Report $report, \WeakMap $opened, \WeakMap $moved): ?string
	{
		if ($report->byLine) {
			$token = $report->at instanceof Token ? $report->at : $report->at->getFirstToken();
			return $token === null ? null : $opened[$token] ?? null;
		}

		if ($report->gap !== null && isset($opened[$report->gap])) {
			return $opened[$report->gap];
		}

		$follows = $report->follows;
		return $follows === null ? null : $opened[$follows] ?? $moved[$follows] ?? null;
	}


	/**
	 * What is placed by the line of the reported gap follows from what opened, closed or moved it, the first of the chain.
	 * @param \WeakMap<Token, string> $opened
	 * @param \WeakMap<Token, string> $moved
	 */
	private static function markLine(Report $report, bool $refused, string $origin, \WeakMap $opened, \WeakMap $moved): void
	{
		if ($report->gap === null || $refused) {
			return;
		} elseif ($report->breaks) {
			$opened[$report->gap] ??= $origin;
		} elseif ($report->follows !== null) {
			$moved[$report->gap] ??= $origin;
		}
	}


	/**
	 * A pass that changed nothing left every occurrence it reported as it was, so the rule had no fix for one it did not
	 * report with `fixable: false`, or refused as risky.
	 */
	private function checkUnfixed(): void
	{
		$rules = [];
		foreach ($this->reported as [$name, $report, $refused]) {
			if ($report->fixable && !$refused && $report->gap === null) { // a gap is the engine's to fix, and a comment may keep it from that
				$rules[$name] = true;
			}
		}

		foreach (array_keys($rules) as $name) {
			$this->violateContract($name, 'it reported an occurrence it then left unfixed without saying `fixable: false`');
		}
	}


	/** @param  string  $breach  what the rule did, as a clause */
	private function violateContract(string $name, string $breach): void
	{
		if ($this->strict) {
			throw new RuleException($name, $this->path, new \LogicException(ucfirst($breach) . '.'));
		}

		$this->warnings[] = "Rule `$name` is faulty: $breach.";
	}


	/**
	 * Column in characters of the token in the original file, from its original offset and the start of its line.
	 */
	private function findOriginalColumn(Node|Token $at): ?int
	{
		$token = $at instanceof Token ? $at : $at->getFirstToken();
		if ($token === null || $token->pos < 0 || $token->line < 0) {
			return null;
		}

		$lineStart = $this->lineOffsets[$token->line - 1] ?? 0;
		$before = substr($this->code, $lineStart, $token->pos - $lineStart);
		return strlen($before) - preg_match_all('~[\x80-\xBF]~', $before) + 1;
	}
}
