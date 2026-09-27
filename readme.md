DressCode
=========

[![Tests](https://github.com/dg/dresscode/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/dg/dresscode/actions)
[![Latest Stable Version](https://poser.pugx.org/dresscode/dresscode/v/stable)](https://github.com/dg/dresscode/releases)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/dg/dresscode/blob/master/license.md)

 <!---->

<h3>

✅ Fixes coding style and [upgrades to newer PHP](#upgrading-code-to-newer-php-from-80-to-86) in one run<br>
✅ Changes only what it fixes, [not a byte more](#changes-only-what-it-touches-a-lossless-syntax-tree-instead-of-a-token-array)<br>
✅ [PER Coding Style, PSR-12 or Nette](#coding-standards-and-rule-groups-per-coding-style-31-psr-12-nette-coding-standard) out of the box<br>
✅ Made to work hand in hand with [AI coding agents](#continuous-integration-git-hooks-and-ai-coding-agents)

</h3>

 <!---->

**A dress code for your PHP code.** DressCode checks the style of your code and fixes it in the standard you
pick; it has no style of its own. And while it is at it, it rewrites the code to what newer PHP offers. With
PER Coding Style and the group `modernization`, one `dresscode fix` turns this:

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
**2️⃣ Check your code: `dresscode check src --preset per`**<br>
**3️⃣ Let it fix what it found: `dresscode fix src --preset per`**

DressCode is a tool, not a library, so it does not have to be installed in the project it checks, and a
global installation is the simplest way; just make sure the directory of global Composer binaries is in your
`PATH`. For CI, where you want the version of the tool pinned, install it
into a directory of its own with
`composer create-project dresscode/dresscode temp/dresscode`. It can also be a development dependency of your
project (`composer require --dev dresscode/dresscode`).

DressCode itself runs on PHP 8.4 to 8.6. **That is the PHP of the tool, not the PHP your code is written for.**
The second number DressCode reads from `require.php` in your `composer.json`, and it never writes syntax that
version does not have. So DressCode running on PHP 8.5 can check a project written for PHP 8.1. Code for
PHP 8.0 and newer is supported.

A check looks like this:

```
DRESS|CODE 1.0.0
Config     none, preset per
Target     PHP 8.2 from `composer.json`
Checking   214 files in /var/www/shop

src/Cart.php
  error   9:52  A line break before the opening brace           braces-position
  error  10:18  An array must be written with the short syntax  short-array-syntax
  error  11:22  At least one space before the `==` operator     binary-operator-spacing
  error  11:22  At least one space after the `==` operator      binary-operator-spacing

FOUND  4 violations, a fix leaves none in 1 file
```

Every finding ends with the name of the rule that made it. That name is all you need: to switch the rule off,
to set its options, to suppress it on one line, or to read what it is for with `dresscode explain braces-position`.
A clean run ends with `OK  214 files, all up to the dress code`, and the exit code is the verdict: 0 clean,
1 violations or syntax errors found, 2 a failure of the run.

DressCode has no style of its own, so without a configuration it does not start: a silent default would
rewrite nearly every line of a project written another way. To just try the tool, name a standard with
`--preset`, as above. Your own choice goes into `dresscode.neon` in the root of the project (or into
`dresscode.php` with the same keys):

```neon
paths:
	- src
	- tests

presets:
	- per

groups:
	- cleanup
	- modernization
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
years, fixed with the modernization rules alone and no standard at all (`dresscode fix --group modernization`):

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
are all still there, because no rule was asked about them. In a project that already keeps its style, the diff
of an upgrade contains the upgrade and nothing else.

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

Every rule that writes newer PHP knows which version brought what it writes, and it switches itself on the
day your `composer.json` allows that version. While the constraint says `^8.3`, no `array_any()` gets into
your code; raise it to `^8.4` and the next `fix` writes it. You never have to keep track of it, and
`dresscode config` tells you which rules are waiting and why:

```
Not running
  dresscode/array-function-for-foreach    it needs PHP 8.4 and the target is 8.3
  dresscode/clamp-for-min-max             it needs PHP 8.6 and the target is 8.3
```

What gets rewritten, by the version of PHP that brought it:

| PHP | what gets rewritten |
|---|---|
| before 8.0 | a closure returning one expression to `fn`, a ternary testing `null` to `??`, a ternary repeating its condition to `?:`, `array()` and `list()` to `[]`, `$a = $a + $b` to `+=` and its kin |
| 8.0 | `strpos() !== false` to `str_contains()`, `substr()` comparisons to `str_starts_with()` and `str_ends_with()`, a simple `switch` to `match`, a property assigned in the constructor to one declared there, a ternary testing `null` to `?->`, `Stringable`, `get_debug_type()` |
| 8.1 | `$this->save(...)` instead of `[$this, 'save']`, `array_is_list()`, octal numbers as `0o755` |
| 8.3 | `json_validate()` instead of `json_decode()` called only as a test, `Foo::{$name}` instead of `constant()` |
| 8.4 | a loop that only searches or tests items to `array_any()`, `array_all()`, `array_find()` or `array_find_key()`, the `RoundingMode` enum in `round()`, `#[\Deprecated]` instead of `@deprecated` |
| 8.5 | nested calls to the pipe operator `\|>`, `array_first()` and `array_last()` |
| 8.6 | `max()` around `min()` to `clamp()` |

All of this is turned on by the group `modernization`. Two more groups go with it. `deprecations` fixes what
newer PHP deprecated: a parameter with the default `null` gets its `?`, the CSV functions get their `escape`
argument, `${name}` in a string becomes `{$name}`, backticks become `shell_exec()` and `(integer)` becomes
`(int)`. `cleanup` removes what newer PHP no longer needs, such as `curl_close()`, `setAccessible()` or an
unused variable in `catch`.

DressCode understands the syntax of new PHP even when it runs on an older one: it fills in the pieces of
syntax the running PHP does not know yet, so DressCode on PHP 8.4 reads a file with the pipe operator of
PHP 8.5, a file PHP 8.4 itself would reject.

 <!---->

Coding standards and rule groups: PER Coding Style 3.1, PSR-12, Nette Coding Standard
=====================================================================================

DressCode has 194 rules, and you do not have to go through them one by one. The configuration comes down to
two questions you can answer right away.

**What should the code look like?** That is a standard, just one, chosen by name:

| preset | what it is |
|---|---|
| `per` | [PER Coding Style 3.1](https://www.php-fig.org/per/coding-style/) in full, the successor of PSR-12 |
| `psr12` | [PSR-12](https://www.php-fig.org/psr/psr-12/), section by section |
| `nette` | [Nette Coding Standard](https://doc.nette.org/en/contributing/coding-standard), PER with tabs and a few departures |

**What else do you want from the code?** That is a group of rules, and there are exactly six:

| group | what it turns on |
|---|---|
| `cleanup` | code that is there for nothing |
| `modernization` | the construct newer PHP has, where an older one says the same |
| `types` | types written where PHP reads them |
| `deprecations` | what newer PHP deprecated or removed |
| `correctness` | what is most likely a mistake |
| `optimized-calls` | calls in the form PHP optimizes when it compiles the code |

A group names what you want, not a list of rules: instead of twenty rule names you write `cleanup`, and every
rule that removes code which is there for nothing runs. A rule that cannot run in your project, because it writes a
newer PHP than yours, quietly stays out, so turning on a group never breaks anything.

Your configuration still has the last word. Any rule is switched off or set up by the name it reports under.
Where the standards differ, the rule has an option for the difference rather than an exception hidden in a
preset, so your own preset can choose the same way. And a part of the project, such as tests or legacy code, can get settings of its
own under the key `overrides`.

 <!---->

Fixes you can trust: risky fixes, suppressions and a baseline
=============================================================

A tool that rewrites your code is only useful while you can trust what it writes. DressCode does not leave that
to the care of each rule's author; the core of the tool enforces it.

- **First report, then fix.** A rule may change the code only after it has reported the violation and the
  report was accepted. The core checks that every change comes with a report, so a rule that changes code
  without a report fails its own tests.
- **A suppression stops the fix, not only the message.** `// dresscode:ignore` on a line, `dresscode:disable`
  and `dresscode:enable` around a block, `dresscode:ignore-file`, or a rule switched off for a path: in all
  of these cases the code stays untouched.
- **A fix that may change what the code does is made only with your consent.** Take `strpos()` inside
  `namespace App\Model`: PHP calls `App\Model\strpos()` if such a function exists, and the global one only
  if it does not. From one file you cannot tell, so rewriting it to `str_contains()` is a risky fix. DressCode
  reports it and waits until you allow it, for one rule in the key `fixRisky` or for one run with
  `--fix-risky`. Or you tell it the truth once, `nameResolution: certain` (or list what your namespaces do
  declare under `namespaces`), and such fixes stop being risky at all.
- **No priorities.** When two rules touch the same code, their order matters. DressCode does not number the
  rules; it runs them again and again until the code stops changing. Two rules pulling the same code back
  and forth are detected, and the run names them.
- **Every space has one owner.** No rule writes whitespace directly. A rule says what it wants between two
  tokens, one space or a line break, and the core decides and fixes it, so two rules never fight over a
  space. Indentation is computed from the structure of the code, so one badly indented line does not drag
  the lines below it along.
- **A baseline for what cannot be fixed yet.** `dresscode check --generate-baseline` records what a fix
  leaves behind, identified by the content of the line rather than its number, so the record survives edits
  elsewhere in the file. The summary of every run says how many violations the baseline hides.

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

A clean file prints nothing, so the check costs the agent none of its context. Violations the fix could not
resolve are listed, for the agent to fix itself. And a file the fix rewrote says so in one line, which tells the
agent to read the file again before its next edit instead of working with a version that no longer exists.
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
#[RuleInfo(
	'dresscode/short-ternary-operator',
	Stage::Structure,
	description: 'Uses ?: where the ternary repeats its condition',
	group: Group::Modernization,
)]
final class ShortTernaryOperatorRule extends NodeRule
{
	public function getVisitedTypes(): array
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
			|| $node->question->getLine() !== $node->colon->getLine()
			|| $node->question->hasCommentUpTo($node->colon)
			|| !$context->report($node, "A ternary repeating its condition must be written `?:`")
		) {
			return;
		}

		$node->then = null;
		$node->question->setTrailingTrivia([]);
	}
}
```

The conditions read like a sentence: it is a ternary, it has a middle part, its condition can be evaluated
twice without side effects, the middle part is the same code as the condition, both are on one line, there
is no comment between them, and nobody suppressed the rule here. A question like "is it safe to read this
expression twice" is answered by the tree, so the rule does not have to work it out.

Your project turns its own rule on by the class name (`App\CodeStyle\MyRule: true` under `rules`), and
`DressCode\Testing\RuleTester` tests it on pairs of files, the code before and after the fix. Rules and presets
that several projects share are packaged as an extension.

 <!---->

Limits
======

- **It runs on PHP 8.4 to 8.6.** The code it checks can be written for PHP 8.0 and newer; code for
  an older version is checked as PHP 8.0, with a warning.
- **A file that does not parse is left alone.** It is reported as a syntax error and not touched.
- **It sees one file at a time.** It does not know the types of variables or what a class inherits, so the
  catalogue has no rules that would need to know.

 <!---->

Documentation
=============

- [dresscode.run](https://dresscode.run) - the user manual
- [docs/reference/rules.md](docs/reference/rules.md) - every rule with its options, generated from the code
- [docs/reference/presets.md](docs/reference/presets.md) - what each preset turns on
- `dresscode explain <rule>` - what a rule is for, its options in your project and its examples

 <!---->

Credits
-------

For years I used PHP CS Fixer and PHP_CodeSniffer and took them as a given. Then I needed one new rule and found
out how much work it takes when a tool sees only a flat list of tokens. DressCode exists so that a rule can be
written in an afternoon, and so that you can trust what it fixes.

DressCode comes from David Grudl, the author of [Nette](https://nette.org), [Latte](https://latte.nette.org)
and [Tracy](https://tracy.nette.org). The syntax tree is [PhpSyntax](https://github.com/phpsyntax/phpsyntax),
a library of its own without a single dependency; besides it, DressCode needs four small Nette packages, the
phpDoc parser of PHPStan and the version comparator of Composer. No framework.
