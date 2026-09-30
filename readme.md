DressCode
=========

[![Tests](https://github.com/dg/dresscode/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/dg/dresscode/actions)
[![Latest Stable Version](https://poser.pugx.org/dresscode/dresscode/v/stable)](https://github.com/dg/dresscode/releases)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/dg/dresscode/blob/master/license.md)

 <!---->

<h3>

✅ Fixes style, [upgrades PHP and libraries](#upgrading-code-to-newer-php-from-80-to-86), in one run<br>
✅ Changes only what it fixes, [not a byte more](#changes-only-what-it-touches-a-lossless-syntax-tree-instead-of-a-token-array)<br>
✅ [PER Coding Style, PSR-12, Nette, Symfony](#coding-standards-and-decisions-per-coding-style-31-psr-12-nette-symfony) built in<br>
✅ [More of PER Coding Style and PSR-12](#how-much-of-the-standard-per-coding-style-and-psr-12-against-php-cs-fixer-and-php_codesniffer) than PHP CS Fixer or PHP_CodeSniffer fixes<br>
✅ Made to work hand in hand with [AI coding agents](#continuous-integration-git-hooks-and-ai-coding-agents)

</h3>

 <!---->

**A dress code for your PHP code.** DressCode checks the style of your code and fixes it in the standard you
pick; it has no style of its own. And while it is at it, it rewrites the code to what newer PHP offers. With
PER Coding Style and the set `modernizations`, one `dresscode fix` turns this:

```php
function getName($user){return $user===null?null:$user->getName();}
```

into this:

```php
function getName($user)
{
    return $user?->getName();
}
```

The layout comes from PER Coding Style, the `?->` from PHP 8.0, which the `composer.json` of the project
allows.

 <!---->

Installation and first run
==========================

**1️⃣ Install it: `composer global require dresscode/dresscode`**<br>
**2️⃣ Have a configuration made to measure: `dresscode init`**<br>
**3️⃣ Check your code and fix it: `dresscode check`, `dresscode fix`**

DressCode is a tool, not a library, so it does not have to be installed in the project it checks, and a
global installation is the simplest way; just make sure the directory of global Composer binaries is in your
`PATH`. For CI, where you want the version of the tool pinned, install it into a directory of its own with
`composer create-project dresscode/dresscode temp/dresscode`. It can also be a development dependency of your
project (`composer require --dev dresscode/dresscode`), which is the way to go once you want DressCode to
ask your PHPStan about the [types of your code](#types-from-phpstan); the project then has to run on
PHP 8.4 to 8.6 too.

DressCode itself runs on PHP 8.4 to 8.6. **That is the PHP of the tool, not the PHP your code is written for.**
The second number DressCode reads from `require.php` in your `composer.json`, and it never writes syntax that
version does not have. So DressCode running on PHP 8.5 can check a project written for PHP 8.1. Code for
PHP 8.0 and newer is supported.

A check looks like this:

```
DRESS|CODE 1.0.0
Config     /var/www/shop/dresscode.neon
Target     PHP 8.2 from `composer.json`
Checking   214 files in /var/www/shop

src/Cart.php
  error   9:52  Expected a line break before the opening brace.         braces.class
  error  10:18  The array must be written `[…]` instead of `array(…)`.  literals.longArraySyntax
  error  11:22  Expected at least one space before the `==` operator.   spacing.binaryOperator
  error  11:22  Expected at least one space after the `==` operator.    spacing.binaryOperator

FOUND  4 violations, a fix leaves none in 1 file
```

Every finding ends with the key of the configuration it breaks. That key is all you need: to give it another
value, to leave the thing alone with `keep`, to suppress it on one line, or to read what it is for with
`dresscode explain braces.class`.
A clean run ends with `OK  214 files, all up to the dress code`, and the exit code is the verdict: 0 clean,
1 violations, syntax errors or a refused baseline, 2 a file that failed, 3 a mistake of the command line or
of the configuration.

DressCode has no style of its own, so without a configuration it does not start: a silent default would
rewrite nearly every line of a project written another way. `dresscode init` measures your code (the
indentation, the quotes, the shape of conditions) and writes `dresscode.neon` into the root of the project;
to just try the tool, name a standard instead with `dresscode check src --use perCs`. The configuration
can also be written by hand, in NEON or as `dresscode.php` with the same keys:

```neon
use:
	- perCs
	- modernizations

paths:
	- src
	- tests
```

 <!---->

Changes only what it touches: a lossless syntax tree instead of a token array
============================================================================

PHP style tools have been built on `token_get_all()` for twenty years. It returns a flat list: this element is
an `if`, the next a parenthesis, the next a space. The list does not say where the `if` ends, or whether `[`
starts an array or reads an item of one, so every rule has to work the structure out again by itself.

DressCode reads each file into a **syntax tree** in which every part of the code knows what it is: an `if`
has its condition and its body, a ternary operator its three parts. And nothing of the source is left out of
the tree, spaces, blank lines and comments included, so printing the tree gives the original file back byte
for byte. That is what "lossless" means, and the tree is a library of its own,
[PhpSyntax](https://github.com/phpsyntax/phpsyntax).

A rule therefore changes the piece of code it fixed and nothing else. Here is a file nobody has formatted in
years, fixed with the set `modernizations` alone and no standard at all (`dresscode fix --use modernizations`):

```diff
   {
       $sum = 0;
       foreach ( $items as $item )
-          $sum = $sum + $item->price;   // yes, floats
+          $sum += $item->price;   // yes, floats
       return $sum;
   }

-  function hasHttps($url) { return strpos( $url, 'https://' ) === 0; }
+  function hasHttps($url) { return str_starts_with($url, 'https://'); }
 }
```

The two-space indentation, the `foreach` without braces, the spaces inside its parentheses and the comment
are all still there, because nothing was asked about them. In a project that already keeps its style, the diff
of an upgrade contains the upgrade and nothing else.

 <!---->

Coding standards and decisions: PER Coding Style 3.1, PSR-12, Nette, Symfony
============================================================================

DressCode has over two hundred rules, and you never name one. The configuration says how your code is
written, thing by thing: every key names a thing in the code, and its value says how it is written.

```neon
braces:
	class: nextLine          # the brace of a class on a line of its own
spacing:
	call: compact            # or the shape itself, "foo($a, $b)"
cleanup:
	is_null: forbidden       # `$x === null` instead
```

What other tools spread over several rules takes two lines here. Global functions written bare, except those
PHP optimizes when it compiles the code, which are imported: in PHP CS Fixer that is
`native_function_invocation` with three options and `global_namespace_import` turning its backslashes into
imports, and PHP_CodeSniffer with Slevomat cannot import those alone. In DressCode it reads as it is meant:

```neon
qualification:
	globalFunction: bare
	optimizedFunction: imported
```

You do not have to write hundreds of keys either, because the configuration comes down to two questions
you can answer right away.

**What should the code look like?** That is a standard, just one, chosen by name with `use`:

| preset | what it is |
|---|---|
| `perCs` | [PER Coding Style 3.1](https://www.php-fig.org/per/coding-style/) in full, the successor of PSR-12 |
| `psr12` | [PSR-12](https://www.php-fig.org/psr/psr-12/), section by section |
| `nette` | [Nette Coding Standard](https://doc.nette.org/en/contributing/coding-standard), PER Coding Style with tabs and a few departures |
| `symfony` | Symfony Coding Standards, as the `@Symfony` set of PHP CS Fixer has them |

A standard is a file of the same shape as yours, so you can read in it what it decides and why, and your
file has the last word on every key. Where the standards differ, they give the same key different values,
never an exception hidden in a rule, so your own preset can choose the same way. And a part of the project,
such as tests or legacy code, can get values of its own under the key `overrides`.

**What else do you want from the code?** That is a set, used beside the standard
(`use: [nette, modernizations]`):

| set | what it decides |
|---|---|
| `modernizations` | the construct newer PHP has, where an older one says the same |
| `deprecations` | what newer PHP or a library deprecated or removed |
| `cleanup` | code that is there for nothing |
| `correctness` | what is most likely a mistake |
| `types` | types written where PHP reads them |
| `compilerOptimizations` | the global names PHP optimizes when it compiles the code, written so that it can |

A set names what you want, not a list of keys: instead of twenty decisions you write `modernizations`, and
when a later version of DressCode learns a new modernization, the set brings it without a change of your
configuration. A set decides nothing about the looks, so it never fights your standard, and a decision that
writes a newer PHP than yours quietly waits until your project allows it. Anything outside the sets is a
key of its own: `dresscode explain upgrading` lists every key of a section with what each value does and
what the standards and your configuration say, and the [reference](docs/reference/decisions.md) has them
all.

Trying a decision, adopting it or cleaning up old code takes no edit of the configuration:

```shell
dresscode fix --set cleanup.is_null=forbidden # your configuration plus this decision, in your style
dresscode fix --only dresscode/cleanup        # one kind of change per commit
dresscode check src --use psr12               # a foreign project, nothing to set up
```

`--only` changes no value: it narrows the run to the decisions your configuration already makes, so it
never brings back what the project left alone on purpose, and what it rewrites still comes out in your
style. And nothing happens behind your back: the run tells you which decisions of a section it left out
and which entry of `use` changes nothing.

 <!---->

How much of the standard: PER Coding Style and PSR-12 against PHP CS Fixer and PHP_CodeSniffer
==============================================================================================

Every style tool says it supports PER Coding Style. The honest test is to let a competitor check the result,
so that is what we did: Laravel and Symfony 3.4 fixed by DressCode and then handed to PHP CS Fixer with its own
PER Coding Style set, and the other way round.

| | Laravel, 1,696 files | Symfony 3.4, 4,097 files |
|---|---|---|
| PHP CS Fixer still changes after DressCode | 9 places in 7 files | 29 places in 16 files |
| DressCode still finds after PHP CS Fixer | 1,201 places | 7,497 places |

The few places PHP CS Fixer still changes are deliberate choices or habits of its own: DressCode keeps the `=`
of an assignment at the end of a line and leaves an operator where a comment follows it, while PHP CS Fixer
moves both, breaks a line inside a parenthesized expression and re-indents a comment above `} elseif`. What
DressCode finds on top of PHP CS Fixer, PER Coding Style asks for: a signature, a call, a condition, a chain
or an array spread over lines gets one item per line, a heredoc follows the indentation of the code and a
multi-line list ends with a comma. PHP CS Fixer implements PER Coding Style 3.0, DressCode 3.1.

The same with PSR-12 and PHP_CodeSniffer: after `dresscode fix --use psr12`, PHP_CodeSniffer finds 83
problems over the whole of Laravel, apart from lines longer than 120 characters, which PSR-12 wants a tool
only to warn about and which DressCode reports once you set `file.longLines: forbidden`. The other way round,
DressCode still finds 772 places after `phpcbf`, among them the visibility of constants, which PSR-12 requires
and `phpcbf` reports but cannot add.

Where a specification leaves room for reading, the presets follow its text: [`perCs`](src/Presets/perCs.neon)
and [`psr12`](src/Presets/psr12.neon) are written section by section, each decision under the number of the
section it comes from.

 <!---->

Findings that say why: compared with PHP_CodeSniffer and PHP CS Fixer
=====================================================================

Every finding of DressCode is a sentence about your code, and where a fix may change what the code does, it says
what exactly may break. Two small files checked against PER Coding Style with a few decisions of `classes`,
`correctness` and `upgrading` and the types from PHPStan:

```
src/Settings.php
  risky  7:37  Parameter `$name` of `offsetGet()` must be named `$key`, as in `ArrayObject::offsetGet()`.  classes.overridingParameterNames
               Risky because a call naming the argument `name:` stops working.

src/Users.php
  error  12:16  The `in_array()` call must pass `$strict = true`.                    correctness.strictComparisonArgument
  risky  17:16  The `in_array()` call must pass `$strict = true`.                    correctness.strictComparisonArgument
                Risky because a strict search no longer finds a value of another type.
  error  22:18  Method `Acme\Mail\Mailer::sendMessage()` is deprecated: use send().  upgrading.declarations.deprecatedMember
```

`Settings` overrides `offsetGet()` of `ArrayObject` and names its parameter `$name`. PHP accepts that, but
`$settings->offsetGet(key: 'theme')`, which works on every `ArrayObject`, ends with `Unknown named parameter $key`.
Renaming it breaks the callers that already write `name:`, so the fix is risky, and the finding says why.

Both calls of `in_array()` get the same advice, not the same treatment. On line 12 the types say an `int` is
searched among `list<int>`, where a strict search finds the same, so `fix` adds `true` by itself. On line 17
the value is `mixed`: that fix waits for your consent and says what it would change.

The library marks `sendMessage()` with `@deprecated use send()`. The finding quotes that advice and `fix`
writes `send()`.

PHP_CodeSniffer reports none of these lines in any standard it ships; Slevomat, installed on top, reports both
calls of `in_array()` with one and the same sentence. PHP CS Fixer reports none with `@PER-CS`. With its risky
fixer `strict_param` and `--allow-risky=yes` it adds `true` to both calls and lists the file:

```
   1) src/Users.php (strict_param)
```

It gives the reason once, for every place, in `php-cs-fixer describe strict_param`: "Risky when the fixed
function is overridden or if the code relies on non-strict usage." A flat array of tokens does not say which
of the two calls that is.

Findings about layout speak the same way, with the measured value (`Expected the statement indented by 12
spaces, 10 spaces found.`), and a violation the fix itself brings stands right under the one causing it, as
`Then also:`.

 <!---->

Fixes you can trust: risky fixes, suppressions and a baseline
=============================================================

A tool that rewrites your code is only useful while you can trust what it writes. DressCode does not leave that
to the care of each rule's author; the core of the tool enforces it.

- **First report, then fix.** A rule may change the code only after it has reported the violation and the
  report was accepted. The core checks that every change comes with a report, so a rule that changes code
  without a report fails its own tests.
- **A suppression stops the fix, not only the message.** `// dresscode:ignore` on a line, `dresscode:disable`
  and `dresscode:enable` around a block, `dresscode:ignoreFile`, or a decision left to `keep` for a path: in
  all of these cases the code stays untouched.
- **A fix that may change what the code does is made only with your consent.** Take `strpos()` inside
  `namespace App\Model`: PHP calls `App\Model\strpos()` if such a function exists, and the global one only
  if it does not. From one file you cannot tell, so rewriting it to `str_contains()` is a risky fix. DressCode
  reports it and waits until you allow it, for one decision in the key `fixRisky` or for one run with
  `--fix-risky`. Or you tell it the truth once, `nameResolution: certain` (or list what your namespaces do
  declare under `namespaces`), and such fixes stop being risky at all. Until then they only warn, and the
  summary says for every kind of risk what would decide it; where the types would, `typeAnalysis: phpstan`
  does.
- **No priorities.** When two rules touch the same code, their order matters. DressCode does not number the
  rules; it runs them again and again until the code stops changing. Two rules pulling the same code back
  and forth are detected, and the run names them.
- **Every space has one owner.** No rule writes whitespace directly. A rule says what it wants between two
  tokens, one space or a line break, and the core decides and fixes it, so two rules never fight over a
  space. Indentation is computed from the structure of the code, so one badly indented line does not drag
  the lines below it along.
- **A baseline for what cannot be fixed yet.** `dresscode baseline` records, once a fix changes nothing, what it
  leaves behind, identified by the content of the line rather than its number, so the record survives edits
  elsewhere in the file. The summary of every run says how many violations the baseline hides.

 <!---->

Upgrading code to newer PHP, from 8.0 to 8.6
============================================

Raise the PHP version in your `composer.json`, run `dresscode fix`, and lines like these change:

```diff
- strpos($url, '://') !== false
+ str_contains($url, '://')                                  // PHP 8.0

- $user === null ? null : $user->getName()
+ $user?->getName()                                          // PHP 8.0

- trim(strtolower(strip_tags($title)))
+ $title |> strip_tags(...) |> strtolower(...) |> trim(...)  // PHP 8.5

- max(0, min(5, $votes))
+ clamp($votes, 0, 5)                                        // PHP 8.6
```

The rewritten code comes out already formatted by your standard. Inside a namespace, `str_contains()` and
`clamp()` wait for your consent, for a reason explained in
[Fixes you can trust](#fixes-you-can-trust-risky-fixes-suppressions-and-a-baseline).

Every decision that writes newer PHP knows which version brought what it writes, and it takes effect the
day your `composer.json` allows that version. While the constraint says `^8.3`, no `array_any()` gets into
your code; raise it to `^8.4` and the next `fix` writes it. You never have to keep track of it, and
`dresscode config` tells you which decisions are waiting and why:

```neon
upgrading:
	functions:
		arraySearchFunctions: adopted     # dresscode/modernizations, no effect: php
		clamp: adopted                    # dresscode/modernizations, no effect: php
```

What gets rewritten, by the version of PHP that brought it:

| PHP | what gets rewritten |
|---|---|
| before 8.0 | a closure returning one expression to `fn`, a ternary testing `null` to `??`, a ternary repeating its condition to `?:`, `array()` and `list()` to `[]`, `$a = $a + $b` to `+=` and its kin |
| 8.0 | `strpos() !== false` to `str_contains()`, `substr()` comparisons to `str_starts_with()` and `str_ends_with()`, a simple `switch` to `match`, a property assigned in the constructor to one declared there, a ternary testing `null` to `?->`, `Stringable`, `get_debug_type()`, an `if` throwing on `null` right after an assignment to `?? throw` |
| 8.1 | `$this->save(...)` instead of `[$this, 'save']`, `array_is_list()`, octal numbers as `0o755`, `array_merge($a, $b)` to `[...$a, ...$b]`, a parameter defaulting to `null` that the body replaces by an object to `Clock $clock = new SystemClock` |
| 8.3 | `json_validate()` instead of `json_decode()` called only as a test, `Foo::{$name}` instead of `constant()` |
| 8.4 | a loop that only searches or tests items to `array_any()`, `array_all()`, `array_find()` or `array_find_key()`, and so `count(array_filter($a, $f)) > 0`, the `RoundingMode` enum in `round()`, `#[\Deprecated]` instead of `@deprecated` |
| 8.5 | nested calls to the pipe operator `\|>`, `array_first()` and `array_last()`, a clone whose properties are assigned next to `clone($this, ['name' => $name])` |
| 8.6 | `max()` around `min()` to `clamp()`, `fn($s) => str_pad($s, 10, '-')` to the partial application `str_pad(?, 10, '-')` |

All of this is turned on by the set `modernizations`, each item a key of its own that a project may also
write alone (`upgrading.syntax.match: adopted`). The set `deprecations` goes with it and fixes what newer PHP
deprecated: a parameter with the default `null` gets its `?`, the CSV functions get their `escape`
argument, `${name}` in a string becomes `{$name}`, backticks become `shell_exec()` and `(integer)` becomes
`(int)`, and what cannot be fixed is reported, such as a `return` inside `finally` or a class named `let`,
which PHP 8.6 deprecated. The guide of PHP, `upgrading.php.deprecatedCall`, removes what newer PHP no longer needs,
such as `curl_close()` or `setAccessible()`.

Upgrade tools such as Rector work on an abstract syntax tree, which keeps what the code means but not how
it is laid out, so they need a coding standard tool after them; Rector's own readme says so. DressCode is
both at once: the rewrite and the formatting happen in the same run, with one configuration.

DressCode understands the syntax of new PHP even when it runs on an older one: it fills in the pieces of
syntax the running PHP does not know yet, so DressCode on PHP 8.4 reads a file with the pipe operator of
PHP 8.5, a file PHP 8.4 itself would reject.

 <!---->

Upgrading libraries: Nette and more
===================================

When a library renames a class, a constant, a method or a parameter, DressCode rewrites your code for you,
and what it cannot rewrite it reports together with what to write instead. What changed in which version
comes as data in a package of rules for the ecosystem: `dresscode/rules-nette` knows all twenty Nette
libraries, most of them from version 3.0 to the current one. Install the package and DressCode finds it by
itself:

```diff
- /** @persistent */
+ #[Persistent]

- $this->invalidateControl('list');
+ $this->redrawControl('list');

- $form->addText('title')->addRule(Form::MAX_LENGTH, null, 100);
+ $form->addText('title')->addRule(Form::MaxLength, null, 100);

- $page = $this->getParameter('page', 1);
+ $page = $this->getParameter('page') ?? 1;

- Json::encode($values, Json::PRETTY)
+ Json::encode($values, pretty: true)
```

None of this is a search and replace: an annotation became an attribute along with its import, the default
of a parameter moved behind `??` and a flag became a named argument. And DressCode knows that `$form` is a
form and `$this` a presenter, because it asks PHPStan about the types. The data are let in by the set
`deprecations` or `modernizations`; most of the rewrites also need the [types](#types-from-phpstan), and
without them only classes, functions and annotations are fixed:

```neon
typeAnalysis: phpstan

use:
	- nette
	- deprecations
```

A package of rules can be written for any library, and it can ship rules of its own besides the data.

 <!---->

Types from PHPStan
==================

The syntax tree knows every byte of a file, but not that `$form` is a form, what a class inherits, or what
the declaration of a method says. If your project has PHPStan, DressCode asks it, with your `phpstan.neon`
and its extensions, so the answers are as good as the analysis you already trust. For that, DressCode has
to be installed in the project next to PHPStan (`composer require --dev phpstan/phpstan dresscode/dresscode`),
and one line of the configuration does the rest:

```neon
typeAnalysis: phpstan
```

That unlocks the rules a style tool cannot otherwise have: the deprecated API of a library rewritten
according to what the variable really is, `UserRepository::class` in place of a string naming an existing
class, and, once you set `upgrading.classes.Override: adopted`, `#[\Override]` on a method that overrides a
method of its parent. PHPStan only answers; it rewrites and reports nothing. Without the key, a decision that
needs types stays out where a standard makes it, and where your configuration does, the run says what is
missing.

 <!---->

Migrating from PHP CS Fixer and PHP_CodeSniffer
===============================================

DressCode knows not only its own decisions, but also the names their rules have in PHP CS Fixer,
PHP_CodeSniffer and Slevomat. So the move takes two steps, and you can stop after the first.

First, leave the code as it is. Comments `// phpcs:ignore`, `phpcs:disable`, `phpcs:enable`,
`phpcs:ignoreFile` and the annotation `@phpcsSuppress` keep working: DressCode translates the foreign rule
name to its decisions, and the suppression holds.

Second, translate the configuration. `import` reads it and writes its equivalent, and it tells you what it
could not carry over:

```shell
dresscode import phpcs.xml > dresscode.php
```

```
Read 4 rules; set 3 decisions and 1 preset.
  No DressCode rule covers Squiz.Commenting.FunctionComment.
```

`.php-cs-fixer.dist.php` is translated the same way, as long as PHP CS Fixer is still installed in the
project, because that file is PHP which has to run.

 <!---->

Continuous integration, Git hooks and AI coding agents
======================================================

The output comes in five formats: `console`, `github` (annotations right in the pull request, chosen
automatically in GitHub Actions), `checkstyle`, `json` and `bare`. Files are processed in parallel, and a
file already known to be clean is skipped on the next run.

An AI coding agent should have every file it writes put into shape right away, and it has to learn when a
file changed under it. Run DressCode after each write with the format `bare`, which is made for that:

```shell
dresscode fix src/Cart.php -f bare --skip-excluded
```

```
src/Cart.php  rewritten
```

A clean file prints nothing, so the check costs the agent none of its context. Violations the fix could
not resolve are listed, for the agent to fix itself. And a file the fix rewrote says so in one line, which
tells the agent to read the file again before its next edit instead of working with a version that no
longer exists.
`--skip-excluded` leaves alone a file the configuration excludes, such as a test fixture the agent wrote.

A pre-commit hook should check what goes into the commit, not the working tree, and `--stdin` does exactly
that:

```shell
for file in $(git diff --cached --name-only --diff-filter=ACM -- '*.php'); do
	git show ":$file" | dresscode check --stdin "$file" -f bare || exit 1
done
```

 <!---->

Writing your own rule
=====================

A rule is a small class that says which parts of the code it is interested in and asks each of them a few
questions. This is the complete rule that shortens `$a ? $a : $b` to `$a ?: $b`:

```php
#[RuleInfo(Stage::Structure)]
final class ShortTernaryForRepeatedConditionRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('expressions.ternaryReturningItsCondition', Domain::state('forbidden'), '`$a ? $a : $b` is `$a ?: $b`')];
	}


	public function getVisitedNodes(): array
	{
		return [TernaryNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof TernaryNode
			|| $node->then === null
			|| !$node->condition->isRepeatableRead()
			|| !$node->condition->matches($node->then)
			|| $node->question->getCurrentLine() !== $node->colon->getCurrentLine()
			|| $node->question->hasCommentUpTo($node->colon)
			|| !$context->report($node, 'The ternary repeating its condition must be written `?:`.')
		) {
			return;
		}

		$node->then = null;
		$node->question->setTrailingTrivia([]);
	}
}
```

The rule declares the one decision it makes, `expressions.ternaryReturningItsCondition`, which a configuration
sets to `forbidden` and every finding of the rule is reported under. The conditions read like a sentence: it is a ternary, it has a middle part, its condition can be evaluated
twice without side effects, the middle part is the same code as the condition, both are on one line, there
is no comment between them, and nobody suppressed the rule here. A question like "is it safe to read this
expression twice" is answered by the tree, so the rule does not have to work it out.

Your project registers its own rule by its class under `rules`, the rule decides under the section `project`,
and the configuration writes that decision like any other (`project: {rawQueries: forbidden}`).
`DressCode\Testing\RuleTester` tests it on pairs of files, the code before and after the fix. Rules and
presets that several projects share are packaged as a plugin.

 <!---->

Performance: PHP CS Fixer, PHP_CodeSniffer
==========================================

DressCode is made to run after every edit, so what counts most is the time one file takes: about a third
to a half of what PHP CS Fixer needs, 118 ms for a small file against 323 ms. Over a whole project, doing
the same work, it is faster too. Run with only the rules that match the PER Coding Style set of PHP CS
Fixer, DressCode takes 64 to 90 percent of its time in one process, and 46 to 64 percent in parallel
processes, over Laravel, WordPress, Symfony and Nette. Symfony with its own configuration of PHP CS Fixer,
translated by `dresscode import`, is checked in 56 percent of the time, 14.2 s against 25.3 s over its
7,704 files; PHP CS Fixer runs a hundred more rules there, on phpDoc and PHPUnit, that have no
counterpart in DressCode.

The preset `perCs` does more than that set: it also breaks long signatures, conditions, chains and arrays
over lines and fixes the casing of names, none of which the set does. Preset against preset, a run
therefore takes 72 percent of the time of PHP CS Fixer on Laravel, and 118 percent on WordPress, whose
code gives the extra rules the most work.

Against PHP_CodeSniffer, fixing is where the difference shows: `dresscode fix` takes 36 to 54 percent of
the time `phpcbf` needs for PSR-12. `phpcs`, which only reports, is faster at reporting than
`dresscode check`, which works the fixes out in memory as well.

Upgrading code takes a fraction of what Rector needs, 1.6 s against 128.5 s over Symfony 3.4, and with
the [types from PHPStan](#types-from-phpstan) it is still faster: 4.4 s against 10.5 s over Laravel with
PHPStan's cache warm, 9.0 s against 195 s with it cold.

Measured in October 2026 on PHP 8.5, against PHP CS Fixer 3.95, PHP_CodeSniffer 4.0 and Rector 2.6, as the
median of three runs.

 <!---->

Limits
======

- **It runs on PHP 8.4 to 8.6.** The code it checks can be written for PHP 8.0 and newer; code for
  an older version is checked as PHP 8.0, with a warning.
- **A file that does not parse is left alone.** It is reported as a syntax error and not touched.
- **Types come from PHPStan.** Without PHPStan in the project, DressCode does not know what a variable is
  or what a class inherits, and the rules that would need to know stay out.

 <!---->

Documentation
=============

- [dresscode.run](https://dresscode.run) - the user manual
- [docs/reference/decisions.md](docs/reference/decisions.md) - every decision with its values, generated from the code
- [docs/reference/presets.md](docs/reference/presets.md) - what each preset turns on
- `dresscode explain <decision>` - what a decision is for and its value in your project

 <!---->

Credits
-------

For years I used PHP CS Fixer and PHP_CodeSniffer and took them as a given. Then I needed one new rule and
found out how much work it takes when a tool sees only a flat list of tokens. DressCode exists so that a
rule can be written in an afternoon, and so that you can trust what it fixes.

DressCode comes from David Grudl, the author of [Nette](https://nette.org), [Latte](https://latte.nette.org)
and [Tracy](https://tracy.nette.org). The syntax tree is [PhpSyntax](https://github.com/phpsyntax/phpsyntax),
a library of its own without a single dependency; besides it, DressCode needs four small Nette packages, the
phpDoc parser of PHPStan and the version comparator of Composer. No framework.
