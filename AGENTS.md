# To My Agents!

It is my fervent wish that this file guide every AI coding agent working with code in this repository.


## Documentation

`docs/internals.md` is the source of truth for how DressCode works: trivia rules, the round-trip invariant, the mutation API, index invalidation, layering. Read it before any non-trivial change.

## Project overview

DressCode is a PHP code style checker and fixer built on a **lossless concrete syntax tree**: every token of the source is in the tree, whitespace and comments are trivia attached to tokens, and printing the tree reproduces the input byte for byte.

Two namespaces, two PSR-4 roots:

- `PhpSyntax` (`src/PhpSyntax/`): lexer, parser, nodes, printer, navigation, mutation, analyses. **No dependencies**; it never imports `DressCode\`, Nette, php-parser or anything from `vendor/`. A PHPStan rule enforces this.
- `DressCode` (`src/DressCode/`): engine, rules API, the library of generic rules, configuration, CLI. The names of other tools live only in `Interop/`, never in a rule.

Rules use only the public API of `PhpSyntax`; whatever a rule in DressCode needs from it is public API for plugins too. What the rules only share among themselves (`NodeHelpers`, `Rules\Whitespace\BlankLines`) is `@internal`. Presets define style; DressCode has no style of its own except `dresscode/per` (PER Coding Style 2.0) and `dresscode/psr12`.

## Essential commands

- `composer tester`: Nette Tester over `tests/`.
- `composer phpstan`: PHPStan level 8, no baseline; `ignoreErrors` only with a reason.
- `composer build`: regenerates `src/PhpSyntax/Parser/ParserData.php`, `src/PhpSyntax/TokenKind.php` and `src/PhpSyntax/Nodes/**` from `grammar/` (`php.y` for the parser, `nodes.php` for the node classes). Commit the output; CI diffs it.
- `composer reference`: regenerates `docs/reference/rules.md` (rules with their options) and `docs/reference/nodes.md` (node classes with their slots) from the code and `grammar/nodes.php`. Commit the output; CI diffs it.
- `php tests/_update-tests.php`: rewrites the expected output of failed dump fixtures from their `.actual` files.
- `php tools/update-violations.php [--all] [slug...]`: writes the `.violations` file of a rule fixture from what the rule reports, `--all` also over the files that have one. Review the diff; a wrong message recorded there is a wrong message tested everywhere.
- Round-trip over an external corpus: `DRESSCODE_CORPUS=/path/to/php/code composer tester`.
- `php bin/dresscode check`: DressCode over its own sources with `dresscode.neon`; CI runs it too.

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
- Rule names say the state the rule enforces, never the step the fixer takes, and follow one vocabulary; `tests/DressCode/Rules/naming.phpt` enforces it and holds the short list of names that stand outside:
  - `no-` is a construct that must not appear at all (`no-global-keyword`), `useless-` one that is legal and right elsewhere but adds nothing here (`useless-else`); an empty instance is always `no-`;
  - horizontal space is `<construct>-spacing`, vertical blank lines `<construct>-blank-lines`, indentation `<construct>-indentation`;
  - `phpdoc` is the whole block, `annotation` a single tag; `parameter` is declared, `argument` is passed; a backslash is `backslash`;
  - canonical spelling is `-notation` or `-canonical-*`, canonical case `-casing`; something that must be present is `-required`;
  - the slug is the class name in kebab-case, with `phpdoc`, `eof`, `inheritdoc` and `elseif` as single tokens.
- The directory of a rule is the most encompassing of the topics it could belong to, not a criterion: none exists that would hold for the whole catalogue, and both indexes of `docs/reference/rules.md` are generated, one from the directory and one from the name.
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
- A grammar alternative with two or more symbols must have an action that uses every symbol, otherwise tokens drop out of the tree; `composer build` fails on such an alternative. A single symbol passes through by default.
- Semantic actions may put plain arrays of slot values on the value stack (alternative syntax tails, optional pairs like `: type`); they are spread into the node constructor and never leave the parser.
- Dump fixtures in `tests/PhpSyntax/Parser/dump/` are the oracle for the shape of the tree; after an intended change run `php tests/_update-tests.php` and review the diff, never paste output by hand.
- A rule mutates only after `$context->report()` returned `true`; a mutation without a report or after a suppressed one is a broken contract that `RuleTester` and `--strict-rules` turn into an error. Every rule ships with fixtures under `tests/DressCode/Rules/fixtures/<slug>/`; `tests/DressCode/Rules/rules.phpt` picks the directory up by itself, a rule needs no test file of its own.
- A rule for a construct that does not exist in every supported PHP says so with `minPhpVersion` in its `#[RuleInfo]`; the resolver then leaves it out below that version, so a preset never has to guard it and `--rule <name>=on` cannot break the code. PHP 8.0 is the floor: never ask whether the target has something 8.0 already had. A rule whose version decides what it may write, not whether it runs at all, asks `RuleContext::getPhpVersion()`. A fixture is tested at the `minPhpVersion` of its rule unless it says otherwise with `// php 8.2` next to the options in its header.
- A violation is positioned at the token it was reported on; a problem in whitespace or a comment is reported with the trivia (`report($token, $message, trivia: $trivia)`), whose `originalLine` the lexer stamped, otherwise it lands on the token's line and `dresscode:ignore` on the real line would not match it.
- Options of a rule are validated by its `nette/schema` at the configuration boundary; a list option given replaces the default instead of being merged with it, whatever the schema says.
- Nodes are matched by `instanceof`: `StatementNode::class` in `getVisitedTypes()` catches every statement. A `match` over node classes in a rule needs a `default` arm, because new node classes may appear in a minor release; `composer phpstan` reports a missing one as `match.unhandled`.
- The text and trivia of a token are written only through `setText()`, `setLeadingTrivia()` and `setTrailingTrivia()`, slots only through the setters of nodes: the token index is kept up to date from what the setters report, so a direct write leaves it wrong without any error; `TreeWriteRule` (PHPStan) refuses such a write outside the tree implementation.
- `Node::getChildren()` is the only way to the children; a node is not iterable, and a child is never replaced by assigning to it: `replaceWith()` or the setter of the parent, which the `Traverser` notices and skips the replaced node.
- `FileNode::$revision` is a version of the tree, not a count of mutations: a compound mutation such as `remove()` moves trivia in several steps and increments it several times. Compare it, never count on it.
- A mutation must keep the trivia canonical: the line ending that ends the line of a token belongs to that token's trailing trivia, never to the leading trivia of the next one. A misplaced one makes `getTrailingSpace()` and the whitespace rules blind to the line break; `ensureLeadingNewline()` and `setBlankLinesBefore()` place it correctly, so build on them.
- A rule that must not destroy a comment asks `Token::hasComment()`, `Token::hasCommentUpTo()` or removes one with `Token::removeTrivia()`; `Node::matches()` and `Node::isRepeatableRead()` answer "does this expression repeat that one safely". Do not reimplement these locally.
