# Writing a rule, or porting one from another tool

A procedure for an agent that writes a rule for DressCode, new or ported from a sniff of PHP_CodeSniffer or a fixer
of PHP CS Fixer. It decides and orders; what a rule may and must do is the contract the manual states once, and this
guide links to it rather than repeat it:

- [custom-rule](https://dresscode.run/custom-rule), a rule from its fixture to a project that uses it;
- [rule-contract](https://dresscode.run/rule-contract), the contract: decisions, values, report before mutation,
  risk, analyses;
- [whitespace-rules](https://dresscode.run/whitespace-rules), a rule of the whitespace, which claims and does not
  write;
- [testing-rules](https://dresscode.run/testing-rules), fixtures and `RuleTester`;
- [porting-rules](https://dresscode.run/porting-rules), the specification taken from the tests of the foreign rule.

The engine itself is `docs/internals.md`; read the part a rule touches before writing one.


## 0. Is there anything to write?

`dresscode catalogue --format json` lists for every decision the foreign names it covers (`covers`). A sniff or
a fixer named there is covered: write nothing, translate the configuration with `dresscode migrate config`.


## 1. The fixture first

Write `fixtures/<slug>/basic.code` before any code (`tests/DressCode/Rules/fixtures/<slug>/` for a rule of the core):
the code before the fix, every case the foreign rule's tests have, and the ones a tree sees that a token stream does not
(a comment inside the construct, the construct over several lines, the alternative syntax, markup around it). Then
`basic.expected` (omit it where the fix changes nothing) and `basic.violations`, `line: message` per report, two
reports on one line being two lines. `values.neon` beside them gives the decision its value, written as a configuration
nests it (`project:` and under it `key: required`), or the rule does nothing; a comment opening a fixture can say more
(`// risky` for a run that makes risky fixes, `// php 8.4`, `// lineLength 80`). The slug is the class without `Rule`,
its first letter in lower case.


## 2. Choose the shape

Ask in this order and stop at the first yes:

1. **Is it only the whitespace between two tokens** (a space, a line break, blank lines)? A `GapRule`, in
   `Stage::Formatting`, made of claims alone: `getClaims()` returns node class => slot => `[before, after]`, each a
   `Claim` or null. It never writes a token. Archetype: `src/Rules/ControlFlow/SwitchCaseSpacingRule.php`.
2. **Is it a comment or a doc comment?** A `NodeRule` visiting `Token::class`, in `Stage::Finishing`, with
   `modifiesComments: true` where it changes one. Archetype: `src/Rules/Comments/NoHashCommentsRule.php`.
3. **Does it rewrite code?** A `NodeRule` in `Stage::Structure` visiting the node classes it rewrites. Archetype:
   `src/Rules/Expressions/NoDoubleNegationsRule.php`.
4. **Does it only report?** The same, every report with `fixable: false`, and its description says "which is only
   reported". Archetype: `src/Rules/Variables/NoGlobalStatementsRule.php`.

A rule that would both claim whitespace and change tokens is two rules.


## 3. Name the decision

A user never names a rule, only the decision it enforces. In a project it is `project.<key>`, in a plugin
`<section>.<key>`; the key names the thing in the code and its value says how it is written (`required`,
`forbidden`, two shapes by their examples). A foreign option that chose between two forms is the value, one that
turned the rule on and off is `keep` against the value, a list of exceptions is a parameter. An option that only
worked around a weakness of tokens is dropped. The vocabulary is in `AGENTS.md` (Conventions) and on the page
[extending](https://dresscode.run/extending) of the manual.


## 4. Write the rule in this order

1. `getDecisions()`: `new Decision($path, $domain, $description)`, the domain one of `DressCode\Domains\*` or
   `Domain::state('forbidden')` and `Domain::state('required')` for a thing that must not or must be there; the
   description English, capitalized, no trailing period.
2. `getVisitedNodes()`, or `getClaims()` for a gap rule. The node classes are in `PhpSyntax\Nodes`, `Nodes\Expression`,
   `Nodes\Statement`, `Nodes\Member` and `Nodes\Scalar` of the PhpSyntax package, each with its slots in its `Slots`
   constant, and the page `nodes` of its manual describes them. What a foreign rule tells by the next token is often
   the class of the node: `static::A`, `static::a()` and `static::$a` are `ClassConstantFetchNode`,
   `StaticMethodCallNode` and `StaticPropertyFetchNode`, so a foreign loop over tokens is a question about a class or
   a slot.
3. `enter()`: one `if` of questions to the tree, shortest first, ending with `!$context->report($at, $message)`,
   which returns false where a comment silenced the place or the run does not take the fix. `$at` is the node or token
   the problem is at: its line is the line of the report, which a `dresscode:ignore` and the baseline match.
4. Ask whether the fix changes what the code does where the file does not show it: a child class that declares the
   constant again (`static::A` is not `self::A` there), a closure bound later, a function of the namespace. Where it
   may, report with `risk: Risk::BehaviorChanges` (or the `Risk` that would decide it) and a `because:`; where the tree
   tells it cannot (a final class), the fix is safe. A foreign rule that called its fix safe is no answer.
5. The fix after the condition, never before it: a slot assigned, `replaceWith()`, `setValue()`, a `Builder` for new
   code. Writing `$name->text` or `setValue()` keeps the comments around the node; a node put into a slot is taken
   with `withoutEdgeTrivia()`.
6. `configure(Values $values)` reads the values once, where the rule has more than its one requirement.

Every line answers a question about the code. A line asking what may stand between two tokens is a translated line,
not a ported one: delete it. A rule much over a hundred lines does several things or still thinks in tokens.


## 5. Run it

```php
Assert::noError(fn() => DressCode\Testing\RuleTester::run(AcmeRule::class, __DIR__ . '/fixtures/acme'));
```

`RuleTester::run()` throws `DressCode\Testing\TestFailure` with a diff where a fixture fails. It compares the output and
the reports and checks the contract: the output is a fixpoint, no comment is
lost, `dresscode:ignoreFile` is honoured, nothing changed without a report. Then PHPStan at level 8. Read a failure by
its symptom:

| what you see | what is wrong |
|---|---|
| `It changed the file although report() returned false` | the fix stands outside the condition ending with `report()` |
| `It changed the file after a report with fixable: false` | a report-only occurrence is fixed after all |
| `It reported an occurrence it then left unfixed without saying fixable: false` | the rule reports what it cannot fix |
| `It asks for analysis X, which it does not name` | `analyses: [X::class]` missing in `#[RuleInfo]`, a helper's included |
| `does not converge` | the rule rewrites its own output: it is not idempotent |
| the output loses a space or a line ending | a node written into a slot kept the trivia of its edges: take it with `withoutEdgeTrivia()` |
| `The node does not belong to the file of the analysis` | the rule removed or replaced an ancestor of the node it was given; visit the ancestor instead |


## 6. Porting: what does not carry over

- **The code.** Only the behaviour and the test cases; mark fixtures taken from a foreign project with their origin and
  licence in their header.
- **The foreign name.** `phpcs:ignore Acme.Sniff.Name` silences a decision of the core, never a rule of a plugin; the
  user rewrites such comments with the path of the new decision, which `dresscode migrate suppressions` lists.
- **A risky fix of the foreign rule.** Asked of the tree (`ExpressionNode::isRepeatableRead()`, the types), the fix
  may be safe; where the code cannot tell, it is reported with `risk:` and the `Risk` that would decide it.
