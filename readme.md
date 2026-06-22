DressCode
=========

DressCode is a checker and fixer of PHP code style. It parses source files into a lossless concrete syntax tree in which every token, whitespace and comment is preserved, so that rules edit the tree and the printer reproduces the file byte for byte. Rules, presets, analyses and reporters are plugins addressed by name; DressCode itself has no opinion about style beyond the presets derived from the PER Coding Style specification.

**Status: in development.** Nothing is usable yet; the API, names and behavior are all subject to change.


Credits
-------

- The PHP grammar (`grammar/php.y`) and the token emulators come from [nikic/php-parser](https://github.com/nikic/PHP-Parser), BSD-3-Clause.
- The parser build pipeline comes from [Latte](https://github.com/nette/latte).
