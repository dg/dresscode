<?php declare(strict_types=1);

namespace DressCode\Engine;

use DressCode\AnalysisRegistry;
use DressCode\ConvergenceException;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleException;
use DressCode\RuleInfo;
use DressCode\Stage;
use DressCode\Violation;
use PhpSyntax\Node;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\PhpVersion;
use PhpSyntax\Printer;
use PhpSyntax\Style;
use PhpSyntax\Token;
use PhpSyntax\Traverser;


/**
 * Runs the rules over a file in passes until nothing mutates: each pass is one pre-order traversal per stage,
 * rules are dispatched by the type of the node or token, every mutation is paired with a report.
 * @internal
 */
final class PassRunner
{
	/** @var array<string, list<Rule>>  stage name → rules in configuration order */
	private array $stages = [];

	/** @var array<string, array<class-string, list<array{Rule, RuleContext, string}>>>  stage → node class → rules entering it, with their contexts and names */
	private array $entering = [];

	/** @var array<string, array<class-string, list<array{Rule, RuleContext, string}>>>  the same for leave() */
	private array $leaving = [];

	/** @var array<string, bool>  stage → some rule of it overrides leave() */
	private array $leaves = [];

	/** @var array<string, RuleContext> */
	private array $contexts = [];

	/** @var array<string, true>  rules that mutated the file in the current pass */
	private array $mutatedRules = [];

	/** @var array<string, Violation>  by fingerprint, so that a pass repeating a report adds nothing */
	private array $violations = [];

	/** @var list<string> */
	private array $warnings = [];

	/** @var array<string, int>  rule\nmessage\nline content → occurrences, for fingerprints */
	private array $occurrences = [];

	/** @var list<string> */
	private array $lines = [];

	private FileNode $file;
	private string $path = '';


	/**
	 * @param list<Rule> $rules  in configuration order
	 * @param \Closure(string): ?string $resolveAlias  rule name or alias → canonical name
	 * @param bool $strict  a broken rule contract (silent mutation, mutation after a suppressed report) throws instead of warning
	 */
	public function __construct(
		array $rules,
		private readonly AnalysisRegistry $analyses,
		private readonly \Closure $resolveAlias,
		private readonly int $maxPasses = 10,
		private readonly bool $strict = false,
	) {
		foreach (Stage::cases() as $stage) {
			$this->stages[$stage->name] = [];
			$this->leaves[$stage->name] = false;
		}

		foreach ($rules as $rule) {
			$stage = RuleInfo::of($rule)->stage->name;
			$this->stages[$stage][] = $rule;
			$this->leaves[$stage] = $this->leaves[$stage] || self::overrides($rule, 'leave');
		}
	}


	/** @throws RuleException|ConvergenceException */
	public function run(FileNode $file, string $code, string $path, Style $style, PhpVersion $phpVersion): PassResult
	{
		$this->file = $file;
		$this->path = $path;
		$this->lines = preg_split('~\r\n|\r|\n~', $code);
		$this->violations = $this->warnings = $this->occurrences = $this->contexts = $this->entering = $this->leaving = [];
		$suppression = Suppression::fromFile($file, $this->resolveAlias);
		foreach ($this->stages as $rules) {
			foreach ($rules as $rule) {
				$name = RuleInfo::of($rule)->name;
				$this->contexts[$name] = new RuleContext($file, $path, $style, $phpVersion, $this->analyses, $suppression, $name);
			}
		}

		$seen = [hash('xxh3', $code) => true];
		$last = $code;
		$passes = 0;
		$mutated = false;
		while (true) {
			if ($passes++ === $this->maxPasses) {
				throw new ConvergenceException($path, array_keys($this->mutatedRules), '');
			}

			$this->mutatedRules = $this->occurrences = [];
			$revision = $file->revision;
			foreach ($this->stages as $stage => $rules) {
				$this->runStage($stage, $rules);
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

		return new PassResult(array_values($this->violations), $this->warnings, $passes, $mutated);
	}


	/** @param list<Rule> $rules */
	private function runStage(string $stage, array $rules): void
	{
		foreach ($rules as $rule) {
			$this->invoke($rule, fn(RuleContext $context) => $rule->beforeFile($context));
		}

		(new Traverser(
			fn(Node|Token $node) => $this->visit($stage, $node, enter: true),
			$this->leaves[$stage] ? fn(Node|Token $node) => $this->visit($stage, $node, enter: false) : null,
		))->traverse($this->file);

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
	 * @return list<array{Rule, RuleContext, string}>
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


	private static function overrides(Rule $rule, string $method): bool
	{
		static $cache = [];
		return $cache[$rule::class][$method]
			??= (new \ReflectionMethod($rule, $method))->getDeclaringClass()->getName() !== Rule::class;
	}


	/**
	 * Calls a per-file callback of a rule and accounts for what it did.
	 * @param \Closure(RuleContext): void $callback
	 */
	private function invoke(Rule $rule, \Closure $callback): void
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
	 * follows a report that returned true.
	 * @param int $before  the revision of the file before the callback
	 */
	private function account(string $name, RuleContext $context, int $before): void
	{
		$after = $this->file->revision;
		$reported = false;
		foreach ($context->takeReports() as [$at, $message, $severity, $reportRevision]) {
			if ($reportRevision === -1) {
				if ($after > $before) {
					$this->violateContract("Rule $name mutated the file after a suppressed report.");
				}

				continue;
			}

			$reported = true;
			$line = RuleContext::findOriginalLine($at) ?? 1;
			$content = $this->lines[$line - 1] ?? '';
			$key = "$name\n$message\n$content";
			$this->occurrences[$key] = ($this->occurrences[$key] ?? 0) + 1;
			$fingerprint = Violation::createFingerprint($name, $message, $content, $this->occurrences[$key]);
			$this->violations[$fingerprint] ??= new Violation(
				$name,
				$message,
				$line,
				self::findOriginalColumn($at),
				$severity,
				fixable: $after > $reportRevision,
				followUp: $reportRevision > 0,
				fingerprint: $fingerprint,
			);
		}

		if ($after > $before) {
			$this->mutatedRules[$name] = true;
			if (!$reported) {
				$this->violateContract("Rule $name mutated the file without reporting a violation.");
			}
		}
	}


	private function violateContract(string $message): void
	{
		if ($this->strict) {
			throw new RuleException('', $this->path, new \LogicException($message));
		}

		$this->warnings[] = $message;
	}


	private static function findOriginalColumn(Node|Token $at): ?int
	{
		$token = $at instanceof Token ? $at : $at->getFirstToken();
		if ($token?->originalOffset === null || $token->originalLine === null) {
			return null;
		}

		$file = $token->getFile();
		if (!$file) {
			return null;
		}

		$offset = $token->originalOffset;
		$code = Printer::print($file);
		$lineStart = $offset === 0 ? 0 : (strrpos(substr($code, 0, $offset), "\n") ?: -1) + 1;
		return strlen(substr($code, $lineStart, $offset - $lineStart)) - preg_match_all('~[\x80-\xBF]~', substr($code, $lineStart, $offset - $lineStart)) + 1;
	}
}
