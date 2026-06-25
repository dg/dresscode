# To My Agents!

It is my fervent wish that this file guide every AI coding agent working with code in this repository.


## Documentation

`docs/internals.md` is the source of truth for how DressCode works: trivia rules, the round-trip invariant, the mutation API, index invalidation, layering. Read it before any non-trivial change.

## Project overview

DressCode is a PHP code style checker and fixer built on a **lossless concrete syntax tree**: every token of the source is in the tree, whitespace and comments are trivia attached to tokens, and printing the tree reproduces the input byte for byte.

Two namespaces, two PSR-4 roots:

- `PhpSyntax` (`src/PhpSyntax/`): lexer, parser, nodes, printer, navigation, mutation, analyses. **No dependencies**; it never imports `DressCode\`, Nette, php-parser or anything from `vendor/`. A PHPStan rule enforces this.
- `DressCode` (`src/DressCode/`): engine, rules API, the library of generic rules, configuration, CLI.

Rules use only the public API of `PhpSyntax`; whatever a rule in DressCode needs from it is public API for plugins too. What the rules only share among themselves (`NodeHelpers`, `Rules\Whitespace\BlankLines`) is `@internal`. Presets define style; DressCode has no style of its own except `dresscode/per` (PER Coding Style 2.0) and `dresscode/psr12`.

## Essential commands

- `composer tester`: Nette Tester over `tests/`.
- `composer phpstan`: PHPStan level 8, no baseline; `ignoreErrors` only with a reason.
- `composer build`: regenerates `src/PhpSyntax/Parser/ParserData.php`, `src/PhpSyntax/TokenKind.php` and `src/PhpSyntax/Nodes/**` from `grammar/`. Commit the output; CI diffs it.
- Round-trip over an external corpus: `DRESSCODE_CORPUS=/path/to/php/code composer tester`.

## Conventions

- Nette coding standard: tabs, `declare(strict_types=1)`, single quotes, types everywhere, two blank lines between methods.
- Modern PHP: `match` instead of `switch`, enums, `readonly`, promoted properties, named arguments, `never`.
- Naming:
  - methods are actions and start with a verb (`getFirstToken()`, `replaceChild()`, `report()`); a bare noun is not a method name;
  - `get*` returns something that belongs to the object (may be `null`), `find*` searches and `null` means not found;
  - boolean queries `is*`/`has*`/`can*`, never `check*`;
  - node classes end with `Node`, rule classes with `Rule`; presets and analyses carry bare names in `Presets/` and `Analyses/`;
  - node slots are named by role, not by text (`ifKeyword`, `openParen`, `closeParen`, `semicolon`, `arrow`, `body`, `cond`), lists in plural, paired delimiters always `open*`/`close*`;
  - no `Abstract`, `Interface`, `I` or `Aware` prefixes/suffixes; an interface or base class sits next to the directory of its implementations (`Rule.php` next to `Rules/`);
  - enums of a namespace live in `enums.php`, exceptions in `exceptions.php`;
  - abbreviations only when established in PHP or Latte (`stmts`, `params`, `args`, `cond`, `expr`, `eof`, `eol`, `paren`, `var`, `ref`).
- Comments only where the code itself is not enough; never restate what the code shows; density follows the surrounding file. No phpDoc for what the types already say.
- Code, comments, identifiers and messages in English.

## Working rules

- Every unit of work (class, grammar production, rule) ends with tests, PHPStan and a critical review of correctness, clarity, elegance and names. Fix findings immediately, not in a later commit.
- Round-trip `print(parse($code)) === $code` is an invariant: any change to the lexer, grammar, nodes or printer must pass the round-trip test over the committed corpus.
- Generated files are never edited by hand; change `grammar/` and rebuild.
- Grammar productions in `grammar/php.y` are not changed, only their actions.
- `Lexer`, `Parser`, `TokenIndex` and `Traverser` are parentless classes over generated data, node constructors are trivial assignments: this keeps the door open for a native acceleration extension.
- One commit per unit, message lowercase, past tense, `subject: description` when it clarifies the area. Linear history.
- Committed files, commit messages and code comments never refer to documents outside the repository, nor to transient states of the work (milestones, phases, "until X exists"). Describe the current state; the history is in git.

## Traps

- `<?php` is not a token but `OpenTag` trivia carrying its whole text including the mandatory whitespace; it is always leading trivia of the following token.
- `?>` is a `CloseTag` token that keeps the newline PHP swallows after it; after a terminated statement it forms its own `EmptyStatementNode`.
- Trivia inside string interpolation (`"{$a /* c */}"`) carry `inInterpolation` and must never be reformatted.
- Whitespace that is part of a token stays in its text: inline HTML, heredoc delimiters, `( int )` casts, `T_ENCAPSED_AND_WHITESPACE`.
- `T_*` token ids differ between PHP builds; the lexer maps them by constant name, never by value.
- A grammar production without an action must produce no callback (`null` in the table), so the driver can build a generic node; the Latte `#noact` behavior is wrong here.
