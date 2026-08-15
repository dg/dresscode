# To My Agents!

It is my fervent wish that this file guide every AI coding agent working with code in this repository.


## Documentation

`docs/internals.md` is the source of truth for how DressCode works: the tree as a rule sees it, the mutation API, the engine of gaps, the rules and the interop. Read it before any non-trivial change.

## Project overview

DressCode is a PHP code style checker and fixer built on a **lossless concrete syntax tree**: every token of the source is in the tree, whitespace and comments are trivia attached to tokens, and printing the tree reproduces the input byte for byte.

The tree itself is the `phpsyntax/phpsyntax` library (namespace `PhpSyntax`), developed in a repository of its own. This one holds `DressCode` (`src/`): engine, rules API, the library of generic rules, configuration, CLI. The names of other tools live only in `Interop/`, never in a rule.

Rules use only the public API of `PhpSyntax`; whatever a rule in DressCode needs from it is public API for plugins too. What the rules only share among themselves (`Rules\NodeHelpers`, `Rules\BlankLines`) is `@internal`. Presets define style; DressCode has no style of its own except `dresscode/per` (PER Coding Style 2.0) and `dresscode/psr12`.

## Essential commands

- `composer tester`: Nette Tester over `tests/`.
- `composer phpstan`: PHPStan level 8, no baseline; `ignoreErrors` only with a reason.
- `composer reference`: regenerates `docs/reference/rules.md` (rules with their options) from the catalogue. Commit the output; CI diffs it.
- `php bin/dresscode check`: DressCode over its own sources with `dresscode.neon`; CI runs it too.

## Conventions

- Nette coding standard: tabs, `declare(strict_types=1)`, single quotes, types everywhere, two blank lines between methods.
- Modern PHP: `match` instead of `switch`, enums, `readonly`, promoted properties, named arguments, `never`.
- Naming:
  - methods are actions and start with a verb (`getFirstToken()`, `replaceChild()`, `report()`); a bare noun is not a method name;
  - `get*` returns something that belongs to the object (may be `null`), `find*` searches and `null` means not found;
  - boolean queries `is*`/`has*`/`can*`, never `check*`, which is the name of a method that answers nothing and raises the problem itself, by throwing or by reporting;
  - rule classes end with `Rule`; presets and analyses carry bare names in `Presets/` and `Analyses/`;
  - a gap rule names the slots it claims as strings (`'openParen'`, `'statements:item'`), which no type checks, so a claim on a slot that is gone shows up only in the fixture of the rule; the names are in the node reference of PhpSyntax;
  - no `Abstract`, `Interface`, `I` or `Aware` prefixes/suffixes; an interface or base class sits next to the directory of its implementations (`Rule.php` next to `Rules/`);
  - enums of a namespace live in `enums.php`, exceptions in `exceptions.php`;
  - names of the API are written in full, the slots of PhpSyntax a claim names among them (`expression`, `condition`, `statements`, `arguments`, `parameters`, `variable`); the abbreviations left there are `paren` in `openParen`/`closeParen`, the delimiter having no one-word English name, `eol`, and `Op` in `BinaryOpNode` and its kin, which names the family across PHP tooling. A local variable may be abbreviated and often reads better for it (`$stmts`, `$args`, `$params`).
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

- Every unit of work (class, rule) ends with tests, PHPStan and a critical review of correctness, clarity, elegance and names. Fix findings immediately, not in a later commit.
- One commit per unit, message lowercase, past tense, `subject: description` when it clarifies the area. Linear history.
- Committed files, commit messages and code comments never refer to documents outside the repository, nor to transient states of the work (milestones, phases, "until X exists"). Describe the current state; the history is in git.

## Traps

- `<?php` is not a token but `OpenTag` trivia carrying its whole text including the mandatory whitespace; it is always leading trivia of the following token.
- `?>` is a `CloseTag` token that keeps the newline PHP swallows after it; after a terminated statement it forms its own `EmptyStatementNode`.
- Trivia inside string interpolation (`"{$a /* c */}"`) carry `inInterpolation` and must never be reformatted.
- Whitespace that is part of a token stays in its text: inline HTML, heredoc delimiters, `( int )` casts, `T_ENCAPSED_AND_WHITESPACE`.
- A rule mutates only after `$context->report()` returned `true`; a mutation without a report or after a suppressed one is a broken contract that `RuleTester` and `--strict-rules` turn into an error. Every rule ships with fixtures under `tests/DressCode/Rules/fixtures/<slug>/`; `tests/DressCode/Rules/rules.phpt` picks the directory up by itself, a rule needs no test file of its own.
- A rule for a construct that does not exist in every supported PHP says so with `minPhpVersion` in its `#[RuleInfo]`; the resolver then leaves it out below that version, so a preset never has to guard it and `--rule <name>=on` cannot break the code. PHP 8.0 is the floor: never ask whether the target has something 8.0 already had. A rule whose version decides what it may write, not whether it runs at all, asks `RuleContext::getPhpVersion()` and compares the string with `version_compare()`, never with the string operators. A fixture is tested at the `minPhpVersion` of its rule unless it says otherwise with `// php 8.2` next to the options in its header.
- A violation is positioned at the token it was reported on; a problem in whitespace or a comment is reported with the trivia (`report($token, $message, trivia: $trivia)`), whose `originalLine` the lexer stamped, otherwise it lands on the token's line and `dresscode:ignore` on the real line would not match it.
- Options of a rule are validated by its `nette/schema` at the configuration boundary; a list option given replaces the default instead of being merged with it, whatever the schema says.
- Nodes are matched by `instanceof`: `StatementNode::class` in `getVisitedTypes()` catches every statement. A `match` over node classes in a rule needs a `default` arm, because new node classes may appear in a minor release; `composer phpstan` reports a missing one as `match.unhandled`.
- A slot of a node is written by assignment (`$node->condition = $expression`): its set hook moves the parents and tells the index. The text and the trivia of a token are written by `setText()`, `setLeadingTrivia()` and `setTrailingTrivia()`, because `?->` cannot stand on the left of an assignment. A property is written where the write takes one value and has one consequence, a method where it takes more or where two things change together (`StringNode::setValue($value, ?$quote)`). What has no hook the language guards instead: the items of a list are `protected(set)` and change only through the methods of the list, and `parent` is `private(set)`, written by `attachTo()` when the tree adopts or releases a child.
- `Node::getChildren()` is the only way to the children and `Node::find()` (a class or a predicate) to the descendants; a node is not iterable, and a child is never replaced by assigning to it: `replaceWith()` or the setter of the parent, which the `Traverser` notices and skips the replaced node.
- A rule replaces the node it was given, or something inside it, never an ancestor of it. The walk notices a replaced node by its parent, and an ancestor taken out leaves that parent as it was: the rest of the chain and the whole subtree go on being visited outside the file, where `getAnalysis()` throws. `!is_null($x)` is rewritten where the negation stands, not where the call does.
- `FileNode::$revision` is a version of the tree, not a count of mutations: a compound mutation such as `remove()` moves trivia in several steps and increments it several times. Compare it, never count on it.
- A mutation must keep the trivia canonical: the line ending that ends the line of a token belongs to that token's trailing trivia, never to the leading trivia of the next one. A misplaced one makes `getTrailingSpace()` and the whitespace rules blind to the line break; `ensureLeadingNewline()` and `setBlankLinesBefore()` place it correctly, so build on them.
- The whitespace between two tokens is nobody's to write: a `GapRule` claims what the gap must be (`Claim`: the `Space` of a line, the `Line` the second token stands on, the blank lines above and below a comment in the gap), the engine decides, fixes and reports under the name of the rule, and two plain claims on one component of one side of a slot are a `ConfigurationException`. So is a claim on a slot no node has: the gaps of a slot renamed in the tree would otherwise never reach the rule and turn it off in silence, so a claim names the class that really has the slot, and `:item` or `:separator` only a slot holding a list. A rule with an option builds its claim once in `configure()`, not at every gap. A closure gets the `Gap` and must give every gap of a construct the same answer whatever the engine did to the gaps before it: a decision read from the shape of the code (a list already broken, a line too long) goes through `Gap::once()`, which keeps it for the pass.
- A rule that must not destroy a comment asks `Token::hasComment()`, `Token::hasCommentUpTo()` or removes one with `Token::removeTrivia()`; `Node::matches()` and `Node::isRepeatableRead()` answer "does this expression repeat that one safely". Do not reimplement these locally.
