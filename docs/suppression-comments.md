# Suppression comments

The `nette` preset reads two comments as the reason for code its decisions would otherwise report (`suppressionComments`):

```neon
'~\bintentionally\s+[!=]=~': expressions.comparison.loose   # `// intentionally ==`
'~^//\s*@\s+\S~': correctness.errorSuppression                 # `// @ why`
```

Such a comment says why the code departs from the standard. It has one form for each of the two cases, and no other form is written.


## The `@` operator

```php
$content = @file_get_contents($file); // @ is escalated to exception
```

`// @`, a space and the reason: lower case, no dash, no colon, no full stop. The reason is required. It names the error the expression may raise and why that is harmless, or that the code handles the failure right after. It is a few words long; where it needs more, the code needs a comment of its own.

The reasons that recur are written the same way everywhere:

| reason | where |
|---|---|
| `is escalated to exception` | the result is checked and a failure throws, with `error_get_last()` or without it |
| `file may not exist`, `directory may already exist` | `unlink()`, `include`, `fopen()`, `mkdir()`, `rmdir()` |
| `file may have been deleted meanwhile` | a file another process may remove between two calls |
| `function may be disabled`, `can be restricted` | `ini_set()`, `set_time_limit()`, `phpinfo()`, `opcache_invalidate()` |
| `the path may be too long or outside open_basedir` | `is_file()` and `is_dir()` of a path that comes from outside |
| `dependencies may not be installed` | `@!include` of `vendor/autoload.php` in a bootstrap |
| `the process may fail to start` | `exec()`, `proc_open()` whose failure is answered otherwise |
| `<feature> is deprecated` | a test that calls the deprecated API on purpose: `dynamic properties are deprecated`, `??-> is deprecated` |
| `cleanup failure is not a test failure` | a test removing what it created |
| `muted on purpose`, `the error is what the test reads` | a test of error handling itself |

A reason that fits none of them names the concrete cause: `// @ a file smaller than 12 bytes triggers a read error`, `// @ invalid values trigger a warning`. A vague one says nothing and is not written: `// @ may trigger error`, `// @ may fail`, `// @ intentionally`, `// @ deprecated` without saying what is.

The reason is the one the code really has. Remove the `@` and run the code or its test to see what it hides; an `@` that hides nothing is removed, not commented. An `@` kept only for an older version of PHP says which (`// @ deprecated in PHP 8.2`).


## `==` and `!=`

```php
if ($value == null) { // intentionally ==
if ($v != null) { // intentionally !=, skips null and empty string
```

`// intentionally` and the operator the line has, `==` or `!=`. A reason follows after a comma where the code does not make it obvious: what the loose comparison treats as equal (`skips null and empty string`), that it compares objects by value, that a driver may return a string.

A loose comparison whose operands have a known type is written strictly instead (`count() !== 0`, `$entry === '.'`), with no comment.


## Where the comment stands

At the end of the line holding the `@` or the operator, not of the statement: in a condition or a chain spread over lines, it ends the line where the operator stands.

Where that line cannot end with a comment, the opening of a heredoc or a line ending inside a string, the comment stands alone on the line above, which silences the node that starts on the next line:

```php
// @ invalid values trigger a warning
@$latte->renderToString(<<<'XX'
	...
	XX),
```

One line with both an `@` and a loose comparison carries one comment that says both, the `@` first: `// @ file may not exist; intentionally ==, so that false equals null`.


## Not written

```php
@unlink($file); // @ - file may not exist      a dash
@unlink($file); // @                           no reason
@flock($file, LOCK_EX); // intentionally @     no reason
@is_file($file); // @ may trigger error        a reason that says nothing
$a != null // intentionally ==                 the line has !=
$a != null // @intentionally ==                an @ before intentionally
```

The pattern of the preset still accepts the dash after `@`, so that older code is not reported; new code does not write it.
