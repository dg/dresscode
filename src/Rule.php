<?php declare(strict_types=1);

namespace DressCode;


/**
 * A rule inspects and fixes one aspect of style. It is stateless across files: an instance serves the whole
 * run, per-file state goes to RuleContext::$storage. Every mutation of the tree must follow a report()
 * that returned true. A rule is a NodeRule, visiting the nodes and tokens it names, or a GapRule, claiming
 * what the gaps between tokens must be.
 */
abstract class Rule
{
}
