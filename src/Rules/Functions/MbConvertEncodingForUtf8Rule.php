<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{NodeRule, Risk, RuleContext, RuleGroup, RuleInfo, Stage};
use DressCode\Rules\{CodeWriter, GlobalCalls};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArgumentNode, Expression, ExpressionNode, NameNode};
use function count;


/**
 * `utf8_encode()` and `utf8_decode()`, deprecated in PHP 8.2, convert between ISO-8859-1 and UTF-8 and
 * nothing else, which is what `mb_convert_encoding()` says out loud. The rule runs where the code targets
 * 8.2 or later, so that a project on an older version is not told about a deprecation it has not reached.
 *
 * The fix is risky: it reaches for mbstring, which the code does not say is there, and a build without the
 * extension answers the new call with an error instead of the deprecation it answered before.
 */
#[RuleInfo(
	'dresscode/mbConvertEncodingForUtf8',
	Stage::Structure,
	description: 'Replaces the deprecated `utf8_encode()` and `utf8_decode()` with `mb_convert_encoding()`',
	group: RuleGroup::Deprecations,
)]
final class MbConvertEncodingForUtf8Rule extends NodeRule
{
	private const Conversions = [
		'utf8_encode' => "'UTF-8', 'ISO-8859-1'",
		'utf8_decode' => "'ISO-8859-1'",
	];


	public function getVisitedTypes(): array
	{
		return [Expression\FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof Expression\FunctionCallNode
			|| ($function = GlobalCalls::findFunction($node, self::Conversions, $context)) === null
		) {
			return;
		}

		$argument = self::readSingleArgument($node);
		$name = $node->name;
		if (
			$argument === null
			|| !$name instanceof NameNode
			|| version_compare($context->phpVersion, '8.2', '<')
			|| $node->hasInnerComment()
			|| !$context->report($node, "The deprecated `$function()` call must be written with `mb_convert_encoding()`", risk: Risk::BehaviorChanges, because: 'a build without mbstring answers the call with an error')
		) {
			return;
		}

		$spelling = CodeWriter::spellFunction('mb_convert_encoding', $name, $context);
		$call = (new Builder)->expression("$spelling(0, " . self::Conversions[$function] . ')');
		assert($call instanceof Expression\FunctionCallNode);
		$first = $call->arguments->items->getItems()[0];
		assert($first instanceof ArgumentNode);
		$first->value->replaceWith($argument->withoutEdgeTrivia());
		$node->replaceWith($call);
	}


	/** The only argument of the call, null where it has more, none, or one of another kind. */
	private static function readSingleArgument(Expression\FunctionCallNode $call): ?ExpressionNode
	{
		$argument = $call->arguments->items->getItems()[0] ?? null;
		return count($call->arguments->items) === 1
			&& $argument instanceof ArgumentNode
			&& !$argument->name && !$argument->ampersand && !$argument->ellipsis
			? $argument->value
			: null;
	}
}
